<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Contracts\GiftCards;
use App\Domains\Identity\Internal\CurrentSessionAuthorization;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Notifications\Internal\EventJournal;
use App\Extensions\Contracts\DomainEvent;
use App\Core\Kernel\Execution\CurrentInvocation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use App\Domains\Billing\Contracts\OrderPage;

/** Wallet-funded, single-use transfers. All money and evidence commit together. */
final class NativeGiftCards implements GiftCards
{
    public function __construct(private readonly CurrentSessionAuthorization $sessions,
        private readonly NativeMoneyLedger $ledger, private readonly EventJournal $events,
        private readonly CurrentInvocation $invocation) {}

    public function listing(int $actorId, bool $admin, int $page, int $perPage): OrderPage
    {
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 50) self::invalid();
        return $this->transaction($actorId, $admin, false, [], function (array $installation) use ($actorId, $admin, $page, $perPage): OrderPage {
            $query = DB::table('tx_native_gift_card')->where('installation_id', $installation['installation_id']);
            if (!$admin) $query->where(fn ($q) => $q->where('issuer_id', $actorId)->orWhere('recipient_id', $actorId));
            $rows = $query->orderByDesc('created_at')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
            return new OrderPage(array_map(function (object $row) use ($installation): array {
                $this->verify($installation, $row);
                return $this->dto($row);
            }, $rows->items()),
                ['page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()]);
        });
    }

    public function execute(int $actorId, string $action, array $input, string $key, bool $admin = false): array
    {
        if (!in_array($action, ['issue', 'redeem', 'revoke'], true) || !Str::isUuid($key) || $admin && $action !== 'revoke') self::invalid();
        if ($action === 'issue') {
            if (count($input) !== 3 || ($input['currency'] ?? null) !== 'CNY' || !is_string($input['amount_minor'] ?? null)
                || bccomp(MoneyValue::minor($input['amount_minor'], true), '2147483647', 0) > 0
                || !is_int($input['expires_at'] ?? null) || $input['expires_at'] < 1 || $input['expires_at'] > 253402300799) self::invalid();
            $lookup = null; $users = [$actorId];
        } else {
            if ($action === 'redeem') {
                if (count($input) !== 1 || !is_string($input['code'] ?? null) || preg_match('/\\AG1-[A-F0-9]{64}\\z/D', $input['code']) !== 1) self::invalid();
                $query = DB::table('tx_native_gift_card')->where('code_hash', hash('sha256', $input['code']));
            } else {
                if (count($input) !== 2 || !Str::isUuid($input['card_id'] ?? '') || !is_string($input['expected_version'] ?? null)) self::invalid();
                MoneyValue::minor($input['expected_version'], true);
                $query = DB::table('tx_native_gift_card')->where('id', $input['card_id']);
            }
            // Discovery takes no locks. The issuer is immutable and rechecked after ordered account locks.
            $lookup = $query->useWritePdo()->first();
            if ($lookup === null) throw new BillingError('GIFT_CARD_UNAVAILABLE');
            $users = [$actorId, (int) $lookup->issuer_id];
        }
        return $this->transaction($actorId, $admin, true, $users, function (array $installation) use ($actorId, $action, $input, $key, $admin, $lookup): array {
            $hash = MoneyValue::hash($input);
            $keyHash = hash('sha256', $installation['installation_id'].':'.$actorId.':gift.'.$action.':'.($admin ? 'admin:' : 'user:').strtolower($key));
            $old = DB::table('tx_native_gift_request')->where('key_hash', $keyHash)->lockForUpdate()->first();
            if ($old !== null) {
                if ($old->installation_id !== $installation['installation_id'] || (int) $old->actor_id !== $actorId || $old->action !== $action) throw new LogicException('GIFT_CARD_RECEIPT_INVALID');
                if (!hash_equals($old->input_hash, $hash)) throw new BillingError('GIFT_CARD_IDEMPOTENCY_CONFLICT');
                $json = Crypt::decryptString($old->response_cipher);
                if (!hash_equals($old->content_hash, hash('sha256', $hash.':'.$json))) throw new LogicException('GIFT_CARD_RECEIPT_INVALID');
                return json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            }
            $now = time(); $code = null;
            if ($action === 'issue') {
                if ($input['expires_at'] <= $now || $input['expires_at'] > $now + 366 * 86400) throw new BillingError('GIFT_CARD_EXPIRY_INVALID');
                $this->lockMoney($installation, [$actorId]);
                [$row, $code] = $this->mintLocked($installation, $actorId, $input, (string) Str::uuid(), $now);
            } else {
                $row = DB::table('tx_native_gift_card')->where('id', $lookup->id)->lockForUpdate()->first();
                if ($row === null || (int) $row->issuer_id !== (int) $lookup->issuer_id || $row->installation_id !== $installation['installation_id']) throw new BillingError('GIFT_CARD_UNAVAILABLE');
                if ($action === 'revoke' && !$admin && (int) $row->issuer_id !== $actorId) abort(404);
                if ($action === 'redeem' && !hash_equals($row->code_hash, hash('sha256', $input['code']))) throw new BillingError('GIFT_CARD_UNAVAILABLE');
                if ($action === 'redeem' && (int) $row->issuer_id === $actorId) throw new BillingError('GIFT_CARD_SELF_REDEEM');
                $this->lockMoney($installation, $action === 'redeem' ? [(int) $row->issuer_id, $actorId] : [(int) $row->issuer_id]);
                $terms = $this->verify($installation, $row);
                if ($row->state !== 'reserved') throw new BillingError('GIFT_CARD_UNAVAILABLE');
                if ($action === 'revoke' && (string) $row->row_version !== $input['expected_version']) throw new BillingError('GIFT_CARD_VERSION_CONFLICT');
                if ($action === 'redeem' && $now >= (int) $row->expires_at) throw new BillingError('GIFT_CARD_EXPIRED');
                $change = ['row_version' => $this->nextVersion($row), 'updated_at' => $now];
                if ($action === 'redeem') {
                    $capture = $this->post($installation, $terms, (int) $row->issuer_id, 'capture', $now);
                    $credit = $this->post($installation, $terms, $actorId, 'credit', $now);
                    $change += ['state' => 'redeemed', 'recipient_id' => $actorId, 'capture_journal_id' => $capture['journal_id'],
                        'credit_journal_id' => $credit['journal_id'], 'closed_at' => $now];
                } else {
                    $release = $this->post($installation, $terms, (int) $row->issuer_id, 'release', $now);
                    $change += ['state' => 'revoked', 'release_journal_id' => $release['journal_id'], 'closed_at' => $now];
                }
                $this->terminal($row, $change);
                DB::table('tx_native_gift_card')->where('id', $row->id)->update($change);
                $row = (object) (array_merge((array) $row, $change));
            }
            $this->verify($installation, $row);
            $result = $this->dto($row) + ['code' => $code];
            $this->event($installation, $row, $action, $actorId, $now);
            $json = json_encode($result, JSON_THROW_ON_ERROR);
            DB::table('tx_native_gift_request')->insert(['key_hash' => $keyHash, 'input_hash' => $hash,
                'installation_id' => $installation['installation_id'], 'actor_id' => $actorId, 'action' => $action,
                'response_cipher' => Crypt::encryptString($json), 'content_hash' => hash('sha256', $hash.':'.$json), 'created_at' => $now]);
            return $result;
        });
    }

    public function issueBatch(int $actorId, array $input, string $key): array
    {
        if (!Str::isUuid($key) || count($input) !== 5 || !is_string($input['label'] ?? null)
            || trim($input['label']) !== $input['label'] || $input['label'] === '' || strlen($input['label']) > 320
            || preg_match('//u', $input['label']) !== 1 || mb_strlen($input['label'], 'UTF-8') > 80
            || preg_match('/[\p{Cc}\p{Cf}]/u', $input['label']) === 1
            || ($input['currency'] ?? null) !== 'CNY' || !is_string($input['amount_minor'] ?? null)
            || !is_int($input['quantity'] ?? null) || $input['quantity'] < 1 || $input['quantity'] > 50
            || !is_int($input['expires_at'] ?? null) || $input['expires_at'] < 1 || $input['expires_at'] > 253402300799) self::invalid();
        $amount = MoneyValue::minor($input['amount_minor'], true);
        $total = bcmul($amount, (string) $input['quantity'], 0);
        if (bccomp($total, '2147483647', 0) > 0) self::invalid();
        return $this->transaction($actorId, false, true, [$actorId], function (array $installation) use ($actorId, $input, $key, $total): array {
            $hash = MoneyValue::hash($input);
            $keyHash = hash('sha256', $installation['installation_id'].':'.$actorId.':gift.issue-batch:user:'.strtolower($key));
            $old = DB::table('tx_native_gift_request')->where('key_hash', $keyHash)->lockForUpdate()->first();
            if ($old !== null) {
                if ($old->installation_id !== $installation['installation_id'] || (int) $old->actor_id !== $actorId
                    || $old->action !== 'issue-batch') throw new LogicException('GIFT_CARD_RECEIPT_INVALID');
                if (!hash_equals($old->input_hash, $hash)) throw new BillingError('GIFT_CARD_IDEMPOTENCY_CONFLICT');
                $json = Crypt::decryptString($old->response_cipher);
                if (!hash_equals($old->content_hash, hash('sha256', $hash.':'.$json))) throw new LogicException('GIFT_CARD_RECEIPT_INVALID');
                $receipt = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($receipt['input'] ?? null) || MoneyValue::hash($receipt['input']) !== $hash
                    || ($receipt['key'] ?? null) !== strtolower($key)
                    || !is_array($receipt['result'] ?? null)) throw new LogicException('GIFT_CARD_RECEIPT_INVALID');
                $current = $this->batchResultLocked($installation, $receipt['result']['batch']['batch_id']);
                foreach (['label', 'quantity', 'amount_minor', 'currency', 'expires_at'] as $field) {
                    if ($current['batch'][$field] !== $input[$field]) throw new LogicException('GIFT_CARD_RECEIPT_INVALID');
                }
                if ($current['batch']['issuer_id'] !== (string) $actorId || $current['batch']['total_minor'] !== $total
                    || !is_array($receipt['result']['cards'] ?? null)
                    || count($receipt['result']['cards']) !== $input['quantity']) throw new LogicException('GIFT_CARD_RECEIPT_INVALID');
                $codes = [];
                foreach ($receipt['result']['cards'] as $card) {
                    if (!is_string($card['code'] ?? null) || preg_match('/\AG1-[A-F0-9]{64}\z/D', $card['code']) !== 1
                        || isset($codes[$card['card_id']])) throw new LogicException('GIFT_CARD_RECEIPT_INVALID');
                    $codes[$card['card_id']] = $card['code'];
                }
                foreach ($current['cards'] as &$card) {
                    if (!isset($codes[$card['card_id']])) throw new LogicException('GIFT_CARD_RECEIPT_INVALID');
                    $stored = DB::table('tx_native_gift_card')->where('id', $card['card_id'])->sharedLock()->first(['code_hash']);
                    if ($stored === null || !hash_equals($stored->code_hash, hash('sha256', $codes[$card['card_id']]))) {
                        throw new LogicException('GIFT_CARD_RECEIPT_INVALID');
                    }
                    $card['code'] = $codes[$card['card_id']];
                }
                unset($card);
                return $current;
            }
            $now = time();
            if ($input['expires_at'] <= $now || $input['expires_at'] > $now + 366 * 86400) throw new BillingError('GIFT_CARD_EXPIRY_INVALID');
            $ids = []; for ($ordinal = 0; $ordinal < $input['quantity']; $ordinal++) $ids[] = (string) Str::uuid();
            $batchId = (string) Str::uuid();
            $terms = ['schema' => 'native-gift-batch/1', 'batch_id' => $batchId, 'installation_id' => $installation['installation_id'],
                'issuer_id' => (string) $actorId, 'label' => $input['label'], 'quantity' => $input['quantity'],
                'amount_minor' => $input['amount_minor'], 'total_minor' => $total, 'currency' => 'CNY',
                'expires_at' => (string) $input['expires_at'], 'created_at' => (string) $now, 'card_ids' => $ids];
            $this->lockMoney($installation, [$actorId]);
            DB::table('tx_native_gift_batch')->insert(['id' => $batchId, 'installation_id' => $installation['installation_id'],
                'issuer_id' => $actorId, 'terms_json' => json_encode($terms, JSON_THROW_ON_ERROR),
                'terms_hash' => MoneyValue::hash($terms), 'created_at' => $now]);
            $rows = []; $codes = [];
            foreach ($ids as $ordinal => $id) {
                [$row, $code] = $this->mintLocked($installation, $actorId, $input, $id, $now, $batchId, $ordinal);
                $rows[] = $row; $codes[$id] = $code;
            }
            $result = $this->batchResultLocked($installation, $batchId, false);
            foreach ($result['cards'] as &$card) $card['code'] = $codes[$card['card_id']];
            unset($card);
            // All wallet postings precede notification/plugin locks for every card in the batch.
            foreach ($rows as $row) $this->event($installation, $row, 'issue', $actorId, $now);
            $json = json_encode(['input' => $input, 'key' => strtolower($key), 'result' => $result], JSON_THROW_ON_ERROR);
            $cipher = Crypt::encryptString($json);
            if (strlen($cipher) > 60000) throw new LogicException('GIFT_BATCH_RECEIPT_TOO_LARGE');
            DB::table('tx_native_gift_request')->insert(['key_hash' => $keyHash, 'input_hash' => $hash,
                'installation_id' => $installation['installation_id'], 'actor_id' => $actorId, 'action' => 'issue-batch',
                'response_cipher' => $cipher, 'content_hash' => hash('sha256', $hash.':'.$json), 'created_at' => $now]);
            return $result;
        });
    }

    public function batches(int $actorId, bool $admin, int $page, int $perPage): OrderPage
    {
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 50) self::invalid();
        return $this->transaction($actorId, $admin, false, [], function (array $installation) use ($actorId, $admin, $page, $perPage): OrderPage {
            $query = DB::table('tx_native_gift_batch')->where('installation_id', $installation['installation_id']);
            if (!$admin) $query->where('issuer_id', $actorId);
            $rows = $query->orderByDesc('created_at')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
            $items = array_map(fn (object $row): array => $this->batchSummary($this->batchTerms($installation, $row)), $rows->items());
            return new OrderPage($items, ['page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
                'total' => $rows->total(), 'last_page' => $rows->lastPage()]);
        });
    }

    public function batch(int $actorId, string $batchId, bool $admin = false): ?array
    {
        if (!Str::isUuid($batchId)) self::invalid();
        return $this->transaction($actorId, $admin, false, [], function (array $installation) use ($actorId, $batchId, $admin): ?array {
            $query = DB::table('tx_native_gift_batch')->where('installation_id', $installation['installation_id'])->where('id', $batchId);
            if (!$admin) $query->where('issuer_id', $actorId);
            if ($query->sharedLock()->first() === null) return null;
            return $this->batchResultLocked($installation, $batchId);
        });
    }

    private function mintLocked(array $installation, int $actorId, array $input, string $id, int $now,
        ?string $batchId = null, ?int $ordinal = null): array
    {
        $code = 'G1-'.strtoupper(bin2hex(random_bytes(32)));
        $terms = ['schema' => $batchId === null ? 'native-gift-card/1' : 'native-gift-card/2', 'id' => $id,
            'installation_id' => $installation['installation_id'], 'issuer_id' => (string) $actorId,
            'amount_minor' => $input['amount_minor'], 'currency' => 'CNY', 'code_hash' => hash('sha256', $code),
            'expires_at' => (string) $input['expires_at'], 'created_at' => (string) $now];
        if ($batchId !== null) $terms += ['batch_id' => $batchId, 'ordinal' => $ordinal];
        $journal = $this->post($installation, $terms, $actorId, 'reserve', $now);
        DB::table('tx_native_gift_card')->insert(['id' => $id, 'installation_id' => $installation['installation_id'],
            'issuer_id' => $actorId, 'batch_id' => $batchId, 'code_hash' => $terms['code_hash'],
            'terms_json' => json_encode($terms, JSON_THROW_ON_ERROR), 'terms_hash' => MoneyValue::hash($terms),
            'state' => 'reserved', 'row_version' => 1, 'reserve_journal_id' => $journal['journal_id'],
            'expires_at' => $input['expires_at'], 'created_at' => $now, 'updated_at' => $now]);
        return [DB::table('tx_native_gift_card')->where('id', $id)->lockForUpdate()->first(), $code];
    }

    private function batchResultLocked(array $installation, string $batchId, bool $immutableEvidence = true): array
    {
        $row = DB::table('tx_native_gift_batch')->where('id', $batchId)->where('installation_id', $installation['installation_id'])->sharedLock()->first();
        if ($row === null) throw new LogicException('GIFT_BATCH_EVIDENCE_INVALID');
        $terms = $this->batchTerms($installation, $row);
        $cards = DB::table('tx_native_gift_card')->where('batch_id', $batchId)->orderBy('id')->limit(51)->sharedLock()->get();
        if ($cards->count() !== $terms['quantity']) throw new LogicException('GIFT_BATCH_EVIDENCE_INVALID');
        $byId = [];
        // No consistent-read snapshot precedes these current card locks on get or receipt replay.
        // Terminal and reversal checks remain current; immutable ledger identities need no money locks.
        foreach ($cards as $card) { $this->verify($installation, $card, $immutableEvidence); $byId[$card->id] = $this->dto($card); }
        $ordered = [];
        foreach ($terms['card_ids'] as $id) {
            if (!isset($byId[$id])) throw new LogicException('GIFT_BATCH_EVIDENCE_INVALID');
            $ordered[] = $byId[$id];
        }
        return ['batch' => $this->batchSummary($terms), 'cards' => $ordered];
    }

    private function batchTerms(array $installation, object $row): array
    {
        $terms = json_decode($row->terms_json, true, 32, JSON_THROW_ON_ERROR);
        $fields = ['schema', 'batch_id', 'installation_id', 'issuer_id', 'label', 'quantity', 'amount_minor',
            'total_minor', 'currency', 'expires_at', 'created_at', 'card_ids'];
        if (!is_array($terms) || count($terms) !== count($fields) || array_diff($fields, array_keys($terms))
            || !hash_equals($row->terms_hash, MoneyValue::hash($terms)) || $terms['schema'] !== 'native-gift-batch/1'
            || $terms['batch_id'] !== $row->id || $terms['installation_id'] !== $installation['installation_id']
            || $row->installation_id !== $installation['installation_id'] || $terms['issuer_id'] !== (string) $row->issuer_id
            || $terms['created_at'] !== (string) $row->created_at || !Str::isUuid($terms['batch_id'])
            || !is_int($terms['quantity']) || $terms['quantity'] < 1 || $terms['quantity'] > 50
            || !is_string($terms['label']) || $terms['label'] === '' || trim($terms['label']) !== $terms['label']
            || strlen($terms['label']) > 320 || preg_match('//u', $terms['label']) !== 1
            || mb_strlen($terms['label'], 'UTF-8') > 80 || preg_match('/[\p{Cc}\p{Cf}]/u', $terms['label']) === 1
            || $terms['currency'] !== 'CNY' || !is_string($terms['amount_minor']) || !is_string($terms['total_minor'])
            || bcmul(MoneyValue::minor($terms['amount_minor'], true), (string) $terms['quantity'], 0) !== $terms['total_minor']
            || bccomp($terms['total_minor'], '2147483647', 0) > 0
            || !is_array($terms['card_ids']) || !array_is_list($terms['card_ids']) || count($terms['card_ids']) !== $terms['quantity']
            || !is_string($terms['expires_at']) || !is_string($terms['created_at'])
            || (int) MoneyValue::minor($terms['expires_at'], true) <= (int) MoneyValue::minor($terms['created_at'], true)
            || (int) $terms['expires_at'] > (int) $terms['created_at'] + 366 * 86400) throw new LogicException('GIFT_BATCH_EVIDENCE_INVALID');
        foreach ($terms['card_ids'] as $id) if (!is_string($id) || !Str::isUuid($id)) throw new LogicException('GIFT_BATCH_EVIDENCE_INVALID');
        if (count(array_unique($terms['card_ids'])) !== $terms['quantity']) throw new LogicException('GIFT_BATCH_EVIDENCE_INVALID');
        return $terms;
    }

    private function batchSummary(array $terms): array
    {
        return ['batch_id' => $terms['batch_id'], 'issuer_id' => $terms['issuer_id'], 'label' => $terms['label'],
            'quantity' => $terms['quantity'], 'amount_minor' => $terms['amount_minor'], 'total_minor' => $terms['total_minor'],
            'currency' => $terms['currency'], 'expires_at' => (int) $terms['expires_at'], 'created_at' => (int) $terms['created_at']];
    }

    /** Restartable bounded worker. Expiry and a concurrent redemption serialize on the card. */
    public function expire(int $limit): array
    {
        if ($limit < 1 || $limit > 1000 || DB::transactionLevel() !== 0) self::invalid();
        app(InstallationAuthority::class)->assertNativeRuntime();
        $candidates = DB::table('tx_native_gift_card')->useWritePdo()->where('state', 'reserved')->where('expires_at', '<=', time())
            ->orderBy('expires_at')->orderBy('id')->limit($limit)->get(['id', 'issuer_id']);
        $count = 0; $errors = [];
        foreach ($candidates as $candidate) {
            try { $count += DB::transaction(function () use ($candidate): int {
                $installation = app(InstallationAuthority::class)->assertNativeMutation();
                $user = DB::table('tx_user')->where('id', $candidate->issuer_id)->lockForUpdate()->first();
                if ($user === null) throw new LogicException('GIFT_CARD_ISSUER_MISSING');
                $row = DB::table('tx_native_gift_card')->where('id', $candidate->id)->lockForUpdate()->first();
                if ($row === null || (int) $row->issuer_id !== (int) $candidate->issuer_id) throw new LogicException('GIFT_CARD_EVIDENCE_INVALID');
                if ($row->state !== 'reserved' || (int) $row->expires_at > time()) return 0;
                $this->lockMoney($installation, [(int) $row->issuer_id]);
                $terms = $this->verify($installation, $row); $now = time();
                $release = $this->post($installation, $terms, (int) $row->issuer_id, 'release', $now);
                $change = ['state' => 'expired', 'row_version' => $this->nextVersion($row), 'release_journal_id' => $release['journal_id'], 'closed_at' => $now, 'updated_at' => $now];
                $this->terminal($row, $change);
                DB::table('tx_native_gift_card')->where('id', $row->id)->update($change);
                $row = (object) array_merge((array) $row, $change);
                $this->verify($installation, $row); $this->event($installation, $row, 'expire', null, $now);
                return 1;
            }, 3); } catch (\Throwable $failure) {
                $code = $failure->getMessage();
                $errors[] = ['card_id' => $candidate->id, 'error_code' => preg_match('/^[A-Z][A-Z0-9_]{0,79}$/D', $code) === 1 ? $code : 'GIFT_CARD_EXPIRY_FAILED'];
            }
        }
        return ['scanned' => $candidates->count(), 'expired' => $count, 'errors' => $errors];
    }

    private function transaction(int $actorId, bool $admin, bool $write, array $users, \Closure $run): mixed
    {
        if ($actorId < 1 || DB::transactionLevel() !== 0) throw new LogicException('GIFT_CARD_TRANSACTION_INVALID');
        return DB::transaction(function () use ($actorId, $admin, $write, $users, $run): mixed {
            $installation = $write ? app(InstallationAuthority::class)->assertNativeMutation() : app(InstallationAuthority::class)->assertNativeRead();
            $users[] = $actorId; $users = array_unique($users); sort($users, SORT_NUMERIC);
            foreach ($users as $id) if (DB::table('tx_user')->where('id', $id)->lockForUpdate()->first() === null) throw new BillingError('BILLING_ACCOUNT_MISSING');
            $this->sessions->assertCurrentLocked($actorId, $admin ? ($write ? 'billing.gift-cards.manage' : 'billing.gift-cards.read') : null);
            return $run($installation);
        }, 3);
    }

    private function lockMoney(array $installation, array $users): void
    {
        $users = array_unique($users); sort($users, SORT_NUMERIC);
        foreach ($users as $id) $this->ledger->lockAccountsLocked($installation, $id, 'CNY', [
            ['owner_id' => '0', 'purpose' => 'gift_card_liability'],
            ['owner_id' => (string) $id, 'purpose' => 'wallet_available'], ['owner_id' => (string) $id, 'purpose' => 'wallet_reserved']]);
    }

    private function journal(array $terms, int $userId, string $kind, int $at): array
    {
        [$debitOwner, $debit, $creditOwner, $credit] = match ($kind) {
            'reserve' => [(string) $userId, 'wallet_available', (string) $userId, 'wallet_reserved'],
            'release' => [(string) $userId, 'wallet_reserved', (string) $userId, 'wallet_available'],
            'capture' => [(string) $userId, 'wallet_reserved', '0', 'gift_card_liability'],
            'credit' => ['0', 'gift_card_liability', (string) $userId, 'wallet_available'],
        };
        return ['business_type' => 'gift_card_'.$kind, 'business_ref' => $terms['id'],
            'idempotency_key' => 'gift.'.$terms['id'].'.'.$kind, 'currency' => $terms['currency'], 'occurred_at' => (string) $at,
            'postings' => [['owner_id' => $debitOwner, 'purpose' => $debit, 'side' => 'debit', 'amount_minor' => $terms['amount_minor']],
                ['owner_id' => $creditOwner, 'purpose' => $credit, 'side' => 'credit', 'amount_minor' => $terms['amount_minor']]]];
    }
    private function post(array $installation, array $terms, int $userId, string $kind, int $at): array
    {
        return $this->ledger->postLocked($installation, $userId, $this->journal($terms, $userId, $kind, $at));
    }

    private function verify(array $installation, object $row, bool $immutableEvidence = false): array
    {
        $terms = $this->terms($row);
        if ($terms['installation_id'] !== $installation['installation_id'] || !in_array($row->state, ['reserved', 'redeemed', 'revoked', 'expired'], true)
            || (string) $row->row_version !== ($row->state === 'reserved' ? '1' : '2')
            || ($row->state === 'reserved') !== ($row->closed_at === null)
            || ($row->state === 'redeemed') !== ($row->recipient_id !== null)) throw new LogicException('GIFT_CARD_EVIDENCE_INVALID');
        $terminal = DB::table('tx_native_gift_terminal')->where('card_id', $row->id)->sharedLock()->first();
        if (($row->state === 'reserved') !== ($terminal === null)) throw new LogicException('GIFT_CARD_EVIDENCE_INVALID');
        if ($terminal !== null) {
            $payload = json_decode($terminal->payload_json, true, 16, JSON_THROW_ON_ERROR);
            if (!hash_equals($terminal->content_hash, MoneyValue::hash($payload)) || $payload !== $this->terminalPayload($row)) throw new LogicException('GIFT_CARD_EVIDENCE_INVALID');
        }
        foreach (['reserve', 'capture', 'credit', 'release'] as $kind) {
            $expected = $kind === 'reserve' || ($kind === 'release' ? in_array($row->state, ['revoked', 'expired'], true) : $row->state === 'redeemed');
            $journalId = $row->{$kind.'_journal_id'};
            if ($expected !== ($journalId !== null)) throw new LogicException('GIFT_CARD_EVIDENCE_INVALID');
            if (!$expected) continue;
            $userId = (int) ($kind === 'credit' ? $row->recipient_id : $row->issuer_id);
            if (DB::table('tx_money_journal')->where('reverses_journal_id', $journalId)->sharedLock()->exists()) throw new LogicException('GIFT_CARD_JOURNAL_REVERSED');
            $evidence = $immutableEvidence ? $this->ledger->inspectJournal((int) $journalId)
                : $this->ledger->inspectJournalLocked((int) $journalId);
            $journal = $this->journal($terms, $userId, $kind, (int) ($kind === 'reserve' ? $row->created_at : $row->closed_at));
            if ($evidence['installation_id'] !== $installation['installation_id'] || $evidence['user_id'] !== (string) $userId) throw new LogicException('GIFT_CARD_EVIDENCE_INVALID');
            foreach ($journal as $field => $value) if ($evidence[$field] !== $value) throw new LogicException('GIFT_CARD_EVIDENCE_INVALID');
        }
        return $terms;
    }

    private function terms(object $row): array
    {
        $terms = json_decode($row->terms_json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($terms) || !hash_equals($row->terms_hash, MoneyValue::hash($terms))
            || !in_array($terms['schema'] ?? null, ['native-gift-card/1', 'native-gift-card/2'], true)) throw new LogicException('GIFT_CARD_EVIDENCE_INVALID');
        foreach (['id', 'installation_id', 'issuer_id', 'code_hash', 'expires_at', 'created_at'] as $field)
            if (($terms[$field] ?? null) !== (string) $row->$field) throw new LogicException('GIFT_CARD_EVIDENCE_INVALID');
        if ($terms['currency'] !== 'CNY' || !Str::isUuid($terms['id']) || !is_string($terms['amount_minor'])
            || bccomp(MoneyValue::minor($terms['amount_minor'], true), '2147483647', 0) > 0) throw new LogicException('GIFT_CARD_EVIDENCE_INVALID');
        if ($terms['schema'] === 'native-gift-card/1') {
            if ($row->batch_id !== null || isset($terms['batch_id']) || isset($terms['ordinal'])) throw new LogicException('GIFT_BATCH_EVIDENCE_INVALID');
        } else {
            if (!is_string($terms['batch_id'] ?? null) || $terms['batch_id'] !== $row->batch_id
                || !is_int($terms['ordinal'] ?? null) || $terms['ordinal'] < 0 || $terms['ordinal'] > 49) throw new LogicException('GIFT_BATCH_EVIDENCE_INVALID');
            $batch = DB::table('tx_native_gift_batch')->where('id', $row->batch_id)->sharedLock()->first();
            if ($batch === null) throw new LogicException('GIFT_BATCH_EVIDENCE_INVALID');
            $batchTerms = $this->batchTerms(['installation_id' => $row->installation_id], $batch);
            if (($batchTerms['card_ids'][$terms['ordinal']] ?? null) !== $row->id
                || $batchTerms['issuer_id'] !== $terms['issuer_id'] || $batchTerms['amount_minor'] !== $terms['amount_minor']
                || $batchTerms['currency'] !== $terms['currency'] || $batchTerms['expires_at'] !== $terms['expires_at']
                || $batchTerms['created_at'] !== $terms['created_at']) throw new LogicException('GIFT_BATCH_EVIDENCE_INVALID');
        }
        return $terms;
    }

    private function dto(object $row): array
    {
        $terms = $this->terms($row);
        return ['card_id' => $row->id, 'issuer_id' => (string) $row->issuer_id, 'recipient_id' => $row->recipient_id === null ? null : (string) $row->recipient_id,
            'amount_minor' => $terms['amount_minor'], 'currency' => $terms['currency'], 'state' => $row->state,
            'row_version' => (string) $row->row_version, 'expires_at' => (int) $row->expires_at,
            'created_at' => (int) $row->created_at, 'closed_at' => $row->closed_at === null ? null : (int) $row->closed_at];
    }
    private function terminal(object $row, array $change): void
    {
        $payload = $this->terminalPayload((object) array_merge((array) $row, $change));
        DB::table('tx_native_gift_terminal')->insert(['card_id' => $row->id,
            'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR), 'content_hash' => MoneyValue::hash($payload)]);
    }
    private function terminalPayload(object $row): array
    {
        $payload = [];
        foreach (['id', 'installation_id', 'issuer_id', 'recipient_id', 'state', 'row_version', 'reserve_journal_id', 'capture_journal_id', 'credit_journal_id', 'release_journal_id', 'closed_at'] as $field)
            $payload[$field] = $row->$field === null ? null : (string) $row->$field;
        return $payload;
    }
    private function nextVersion(object $row): int
    {
        if ((string) $row->row_version !== '1') throw new LogicException('GIFT_CARD_EVIDENCE_INVALID');
        return 2;
    }
    private function event(array $installation, object $row, string $action, ?int $actorId, int $now): void
    {
        $context = $this->invocation->current();
        $this->events->appendLocked(new DomainEvent(hash('sha256', $installation['installation_id'].':gift:'.$row->id.':'.$row->row_version),
            $installation['installation_id'], 'billing.gift-card.changed.v1', 'gift:'.$row->id,
            $this->dto($row) + ['action' => $action, 'actor_id' => $actorId === null ? null : (string) $actorId], $now, $context?->correlationId ?? (string) Str::uuid()));
    }
    private static function invalid(): never { throw new \InvalidArgumentException('GIFT_CARD_INPUT_INVALID'); }
}
