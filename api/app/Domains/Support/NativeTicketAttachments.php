<?php

namespace App\Domains\Support;

use App\Domains\Identity\Internal\CurrentSessionAuthorization;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Support\Contracts\TicketAttachments;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/** Private encrypted SQL storage. Request receipts retain hashes and IDs, never attachment bytes. */
final class NativeTicketAttachments implements TicketAttachments
{
    private const MAX_FILE = 5242880;
    private const MAX_TICKET = 33554432;
    private const CHUNK = 8192;
    private const TYPES = ['image/png', 'image/jpeg', 'application/pdf', 'text/plain'];
    private const HASH_FIELDS = ['id', 'installation_id', 'ticket_id', 'uploader_id', 'message_id', 'filename',
        'media_type', 'size_bytes', 'sha256', 'status', 'received_bytes', 'next_chunk_index', 'created_at', 'expires_at'];

    public function __construct(private readonly CurrentSessionAuthorization $sessions) {}

    public function begin(int $actorId, array $input, string $key, bool $admin = false): array
    {
        self::fields($input, ['ticket_id', 'filename', 'media_type', 'size_bytes', 'sha256']);
        self::decimal($input['ticket_id']); self::filename($input['filename']); self::digest($input['sha256']);
        self::decimal($input['size_bytes']);
        if ((int) $input['size_bytes'] > self::MAX_FILE || !in_array($input['media_type'], self::TYPES, true)) self::invalid();
        return $this->mutate($actorId, 'begin', $input, $key, $admin);
    }

    public function append(int $actorId, array $input, string $key, bool $admin = false): array
    {
        self::fields($input, ['attachment_id', 'chunk_index', 'content_base64']); self::uuid($input['attachment_id']);
        self::index($input['chunk_index']);
        if (!is_string($input['content_base64']) || strlen($input['content_base64']) > 10924) self::invalid();
        $bytes = base64_decode($input['content_base64'], true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::CHUNK
            || base64_encode($bytes) !== $input['content_base64']) self::invalid();
        return $this->mutate($actorId, 'append', $input, $key, $admin, $bytes);
    }

    public function finish(int $actorId, array $input, string $key, bool $admin = false): array
    {
        self::fields($input, ['attachment_id']); self::uuid($input['attachment_id']);
        return $this->mutate($actorId, 'finish', $input, $key, $admin);
    }

    public function cancel(int $actorId, array $input, string $key, bool $admin = false): array
    {
        self::fields($input, ['attachment_id']); self::uuid($input['attachment_id']);
        return $this->mutate($actorId, 'cancel', $input, $key, $admin);
    }

    public function get(int $actorId, string $id, bool $admin = false): ?array
    {
        self::uuid($id);
        return $this->read($actorId, $id, $admin, fn (object $row): array => $this->metadata($row));
    }

    public function download(int $actorId, array $input, bool $admin = false): ?array
    {
        self::fields($input, ['attachment_id', 'chunk_index']); self::uuid($input['attachment_id']); self::index($input['chunk_index']);
        return $this->read($actorId, $input['attachment_id'], $admin, function (object $row) use ($input): ?array {
            if (!in_array($this->effectiveStatus($row), ['ready', 'attached'], true)) abort(409);
            $chunk = DB::table('tx_support_attachment_chunk')->where('attachment_id', $row->id)
                ->where('chunk_index', $input['chunk_index'])->sharedLock()->first();
            if ($chunk === null) return null;
            $bytes = $this->decode($chunk);
            return ['attachment_id' => $row->id, 'chunk_index' => $input['chunk_index'],
                'content_base64' => base64_encode($bytes), 'sha256' => $chunk->sha256,
                'last' => $input['chunk_index'] === (int) $row->next_chunk_index - 1];
        });
    }

    private function read(int $actorId, string $id, bool $admin, \Closure $read): ?array
    {
        $this->standalone();
        return DB::transaction(function () use ($actorId, $id, $admin, $read): ?array {
            $installation = app(InstallationAuthority::class)->assertNativeRead();
            $this->sessions->assertCurrentLocked($actorId, $admin ? 'support.tickets.read' : null);
            $locator = $this->locator($installation, $id);
            if ($locator === null) return null;
            if ($this->ticket($actorId, (int) $locator->ticket_id, $admin, false) === null) return null;
            $row = $this->attachment($installation, $id, (int) $locator->ticket_id, false);
            if ($row === null || ($row->message_id === null && (int) $row->uploader_id !== $actorId)) return null;
            return $read($row);
        }, 3);
    }

    private function mutate(int $actorId, string $operation, array $input, string $key, bool $admin, ?string $bytes = null): array
    {
        self::uuid($key); $this->standalone();
        return DB::transaction(function () use ($actorId, $operation, $input, $key, $admin, $bytes): array {
            $installation = app(InstallationAuthority::class)->assertNativeMutation();
            $this->sessions->assertCurrentLocked($actorId, $admin ? 'support.tickets.write' : null);
            $scope = ($admin ? 'admin.' : 'user.').$operation;
            $keyHash = hash('sha256', $installation['installation_id'].':'.$actorId.':'.$scope.':'.strtolower($key));
            ksort($input); $inputHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $receipt = DB::table('tx_support_attachment_request')->where('key_hash', $keyHash)->lockForUpdate()->first();
            if ($receipt !== null) {
                if ($receipt->installation_id !== $installation['installation_id'] || (int) $receipt->actor_id !== $actorId
                    || $receipt->operation !== $scope || !hash_equals($receipt->content_hash, self::receiptHash($receipt))) {
                    throw new LogicException('SUPPORT_ATTACHMENT_RECEIPT_INVALID');
                }
                if (!hash_equals($receipt->input_hash, $inputHash)) abort(409);
            }
            $id = $receipt?->attachment_id ?? ($input['attachment_id'] ?? null);
            $locator = $id === null ? null : $this->locator($installation, $id);
            if ($id !== null && $locator === null) abort(404);
            $ticketId = $locator === null ? (int) $input['ticket_id'] : (int) $locator->ticket_id;
            $ticket = $this->ticket($actorId, $ticketId, $admin, true);
            if ($ticket === null) abort(404);
            $row = $id === null ? null : $this->attachment($installation, $id, $ticketId, true);
            if ($row !== null && (int) $row->uploader_id !== $actorId) abort(404);
            // Replaying a completed request returns today's metadata, including later binding or expiry.
            if ($receipt !== null) return $this->metadata($row);
            if ((int) $ticket->status !== 0 && $operation !== 'cancel') abort(409);
            if ($operation === 'begin') {
                $now = time();
                $pending = DB::table('tx_support_attachment')->where('installation_id', $installation['installation_id'])
                    ->where('uploader_id', $actorId)->whereNull('message_id')->whereIn('status', ['uploading', 'ready'])
                    ->where('expires_at', '>', $now)->count();
                if ($pending >= 16) abort(409);
                // Expired ciphertext still occupies the budget until cancellation or cleanup removes it.
                $reserved = DB::table('tx_support_attachment')->where('installation_id', $installation['installation_id'])
                    ->where('ticket_id', $ticketId)->whereIn('status', ['uploading', 'ready', 'attached'])->sum('size_bytes');
                if ((int) $reserved + (int) $input['size_bytes'] > self::MAX_TICKET) abort(409);
                $row = (object) ['id' => (string) Str::uuid(), 'installation_id' => $installation['installation_id'],
                    'ticket_id' => $ticketId, 'uploader_id' => $actorId, 'message_id' => null,
                    'filename' => $input['filename'], 'media_type' => $input['media_type'], 'size_bytes' => (int) $input['size_bytes'],
                    'sha256' => $input['sha256'], 'status' => 'uploading', 'received_bytes' => 0, 'next_chunk_index' => 0,
                    'created_at' => $now, 'expires_at' => $now + 86400];
                $row->content_hash = self::rowHash($row);
                DB::table('tx_support_attachment')->insert((array) $row);
            } else {
                if ($row->message_id !== null) abort(409);
                if ($operation === 'cancel') {
                    if ($row->status !== 'cancelled') {
                        DB::table('tx_support_attachment_chunk')->where('attachment_id', $row->id)->delete();
                        $this->save($row, ['status' => $this->effectiveStatus($row) === 'expired' ? 'expired' : 'cancelled']);
                    }
                } else {
                    if ($this->effectiveStatus($row) !== 'uploading') abort(409);
                    if ($operation === 'append') {
                        $expected = min(self::CHUNK, (int) $row->size_bytes - (int) $row->received_bytes);
                        if ($input['chunk_index'] !== (int) $row->next_chunk_index || strlen($bytes) !== $expected) abort(409);
                        DB::table('tx_support_attachment_chunk')->insert(['attachment_id' => $row->id,
                            'chunk_index' => $input['chunk_index'], 'ciphertext' => Crypt::encryptString(base64_encode($bytes)),
                            'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes)]);
                        $this->save($row, ['received_bytes' => (int) $row->received_bytes + strlen($bytes),
                            'next_chunk_index' => (int) $row->next_chunk_index + 1]);
                    } else {
                        $this->verifyFinished($row);
                        $this->save($row, ['status' => 'ready']);
                    }
                }
            }
            $receipt = (object) ['key_hash' => $keyHash, 'installation_id' => $installation['installation_id'],
                'actor_id' => $actorId, 'operation' => $scope, 'input_hash' => $inputHash,
                'attachment_id' => $row->id, 'created_at' => time()];
            $receipt->content_hash = self::receiptHash($receipt);
            DB::table('tx_support_attachment_request')->insert((array) $receipt);
            return $this->metadata($row);
        }, 3);
    }

    public function bindLocked(array $installation, int $actorId, int $ticketId, int $messageId, array $ids, bool $admin): void
    {
        $this->participant($installation);
        if ($actorId < 1 || $ticketId < 1 || $messageId < 1 || !array_is_list($ids) || count($ids) > 4) self::invalid();
        foreach ($ids as $id) self::uuid($id);
        if (count(array_unique($ids, SORT_STRING)) !== count($ids)) self::invalid();
        $ticket = $this->ticket($actorId, $ticketId, $admin, true);
        if ($ticket === null) abort(404);
        if ((int) $ticket->status !== 0) abort(409);
        $message = DB::table('tx_ticket_message')->where('id', $messageId)->where('ticket_id', $ticketId)->lockForUpdate()->first();
        if ($message === null || (int) $message->user_id !== $actorId
            || $message->author_kind !== ($admin ? 'admin' : 'user')) abort(409);
        $existing = DB::table('tx_support_attachment')->where('message_id', $messageId)->count();
        if ($existing + count($ids) > 4) abort(409);
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            $row = $this->attachment($installation, $id, $ticketId, true);
            if ($row === null || (int) $row->uploader_id !== $actorId || $row->message_id !== null
                || $this->effectiveStatus($row) !== 'ready') abort(409);
            $this->save($row, ['message_id' => $messageId, 'status' => 'attached']);
        }
    }

    public function forMessagesLocked(array $installation, int $actorId, int $ticketId, array $messageIds, bool $admin): array
    {
        $this->participant($installation);
        if (count($messageIds) > 20) self::invalid();
        foreach ($messageIds as $id) self::decimal((string) $id);
        if ($this->ticket($actorId, $ticketId, $admin, false) === null) abort(404);
        $rows = DB::table('tx_support_attachment')->where('installation_id', $installation['installation_id'])
            ->where('ticket_id', $ticketId)->whereIn('message_id', $messageIds)->where('status', 'attached')->orderBy('id')->sharedLock()->get();
        $result = [];
        foreach ($rows as $row) { $this->verify($row); $result[(string) $row->message_id][] = $this->metadata($row); }
        return $result;
    }

    /** Worker only: keep metadata and receipts while reclaiming expired, unbound ciphertext. */
    public function expire(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 100) self::invalid();
        $this->standalone();
        $locators = DB::table('tx_support_attachment')->useWritePdo()->whereNull('message_id')
            ->whereIn('status', ['uploading', 'ready'])->where('expires_at', '<=', time())
            ->orderBy('expires_at')->orderBy('id')->limit($limit)->get(['id', 'ticket_id']);
        $expired = 0;
        foreach ($locators as $locator) {
            $expired += DB::transaction(function () use ($locator): int {
                $installation = app(InstallationAuthority::class)->assertNativeMutation();
                if (DB::table('tx_ticket')->where('id', $locator->ticket_id)->lockForUpdate()->first() === null) return 0;
                $row = $this->attachment($installation, $locator->id, (int) $locator->ticket_id, true);
                if ($row === null || $row->message_id !== null || !in_array($row->status, ['uploading', 'ready'], true)
                    || (int) $row->expires_at > time()) return 0;
                DB::table('tx_support_attachment_chunk')->where('attachment_id', $row->id)->delete();
                $this->save($row, ['status' => 'expired']);
                return 1;
            }, 3);
        }
        return $expired;
    }

    private function verifyFinished(object $row): void
    {
        if ((int) $row->received_bytes !== (int) $row->size_bytes) abort(409);
        $content = ''; $index = 0;
        $chunks = DB::table('tx_support_attachment_chunk')->where('attachment_id', $row->id)->orderBy('chunk_index')->lockForUpdate()->get();
        foreach ($chunks as $chunk) {
            if ((int) $chunk->chunk_index !== $index++) throw new LogicException('SUPPORT_ATTACHMENT_CHUNK_INVALID');
            $content .= $this->decode($chunk);
        }
        if ($index !== (int) $row->next_chunk_index || strlen($content) !== (int) $row->size_bytes
            || !hash_equals($row->sha256, hash('sha256', $content))) abort(409);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        if ($mime !== $row->media_type || !in_array($mime, self::TYPES, true)
            || ($mime === 'text/plain' && (preg_match('//u', $content) !== 1
                || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $content) === 1))) abort(422);
    }

    private function decode(object $chunk): string
    {
        $encoded = Crypt::decryptString($chunk->ciphertext); $bytes = base64_decode($encoded, true);
        if ($bytes === false || base64_encode($bytes) !== $encoded || strlen($bytes) !== (int) $chunk->size_bytes
            || strlen($bytes) < 1 || strlen($bytes) > self::CHUNK || !hash_equals($chunk->sha256, hash('sha256', $bytes))) {
            throw new LogicException('SUPPORT_ATTACHMENT_CHUNK_INVALID');
        }
        return $bytes;
    }

    private function locator(array $installation, string $id): ?object
    {
        return DB::table('tx_support_attachment')->useWritePdo()->where('installation_id', $installation['installation_id'])
            ->where('id', $id)->first(['ticket_id']);
    }

    private function ticket(int $actorId, int $ticketId, bool $admin, bool $write): ?object
    {
        $query = DB::table('tx_ticket')->useWritePdo()->where('id', $ticketId);
        if (!$admin) $query->where('user_id', $actorId);
        return ($write ? $query->lockForUpdate() : $query->sharedLock())->first();
    }

    private function attachment(array $installation, string $id, int $ticketId, bool $write): ?object
    {
        $query = DB::table('tx_support_attachment')->useWritePdo()->where('installation_id', $installation['installation_id'])
            ->where('ticket_id', $ticketId)->where('id', $id);
        $row = ($write ? $query->lockForUpdate() : $query->sharedLock())->first();
        if ($row !== null) $this->verify($row);
        return $row;
    }

    private function verify(object $row): void
    {
        if (!hash_equals($row->content_hash, self::rowHash($row)) || !in_array($row->status,
            ['uploading', 'ready', 'attached', 'cancelled', 'expired'], true)
            || (($row->status === 'attached') !== ($row->message_id !== null))
            || (int) $row->size_bytes < 1 || (int) $row->size_bytes > self::MAX_FILE
            || (int) $row->received_bytes < 0 || (int) $row->received_bytes > (int) $row->size_bytes) {
            throw new LogicException('SUPPORT_ATTACHMENT_EVIDENCE_INVALID');
        }
    }

    private function save(object $row, array $change): void
    {
        foreach ($change as $field => $value) $row->$field = $value;
        $row->content_hash = self::rowHash($row);
        DB::table('tx_support_attachment')->where('id', $row->id)->update($change + ['content_hash' => $row->content_hash]);
    }

    private function effectiveStatus(object $row): string
    {
        return $row->message_id === null && in_array($row->status, ['uploading', 'ready'], true)
            && (int) $row->expires_at <= time() ? 'expired' : $row->status;
    }

    private function metadata(object $row): array
    {
        return ['id' => $row->id, 'ticket_id' => (string) $row->ticket_id,
            'message_id' => $row->message_id === null ? null : (string) $row->message_id,
            'filename' => $row->filename, 'media_type' => $row->media_type, 'size_bytes' => (string) $row->size_bytes,
            'sha256' => $row->sha256, 'status' => $this->effectiveStatus($row), 'received_bytes' => (string) $row->received_bytes,
            'next_chunk_index' => (int) $row->next_chunk_index, 'created_at' => self::date($row->created_at),
            'expires_at' => self::date($row->expires_at)];
    }

    private static function rowHash(object $row): string
    {
        $values = [];
        foreach (self::HASH_FIELDS as $field) $values[$field] = $row->$field === null ? null : (string) $row->$field;
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private static function receiptHash(object $row): string
    {
        return hash('sha256', implode(':', [$row->key_hash, $row->installation_id, $row->actor_id,
            $row->operation, $row->input_hash, $row->attachment_id, $row->created_at]));
    }

    private function standalone(): void
    {
        if (DB::transactionLevel() !== 0) throw new LogicException('SUPPORT_ATTACHMENT_OUTER_TRANSACTION_UNSUPPORTED');
    }

    private function participant(array $installation): void
    {
        if (DB::transactionLevel() < 1 || !is_string($installation['installation_id'] ?? null)) {
            throw new LogicException('SUPPORT_ATTACHMENT_TRANSACTION_REQUIRED');
        }
    }

    private static function fields(array $input, array $fields): void
    {
        if (count($input) !== count($fields) || array_diff($fields, array_keys($input))) self::invalid();
    }

    private static function decimal(mixed $value): void
    {
        if (!is_string($value) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) !== 1
            || strlen($value) === 19 && strcmp($value, (string) PHP_INT_MAX) > 0) self::invalid();
    }

    private static function uuid(mixed $value): void
    {
        if (!is_string($value) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/Di', $value) !== 1) self::invalid();
    }

    private static function index(mixed $value): void
    {
        if (!is_int($value) || $value < 0 || $value >= self::MAX_FILE / self::CHUNK) self::invalid();
    }

    private static function digest(mixed $value): void
    {
        if (!is_string($value) || preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1) self::invalid();
    }

    private static function filename(mixed $value): void
    {
        if (!is_string($value) || $value === '' || strlen($value) > 255 || trim($value) !== $value
            || in_array($value, ['.', '..'], true) || preg_match('//u', $value) !== 1
            || mb_strlen($value, 'UTF-8') > 160 || preg_match('~[\\\\/:<>"|?*\p{Cc}\p{Cf}]~u', $value) === 1
            || preg_match('/\.(?:svgz?|html?|xhtml)(?:\.|$)/i', $value) === 1) self::invalid();
    }

    private static function date(mixed $time): string { return Carbon::createFromTimestampUTC((int) $time)->toIso8601String(); }
    private static function invalid(): never { throw new InvalidArgumentException('SUPPORT_ATTACHMENT_INPUT_INVALID'); }
}
