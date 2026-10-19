<?php

namespace App\Domains\Support;

use App\Core\Kernel\Execution\CurrentInvocation;
use App\Domains\Identity\Internal\CurrentSessionAuthorization;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Notifications\Internal\EventJournal;
use App\Domains\Support\Contracts\TicketCommands;
use App\Domains\Support\Contracts\TicketListQuery;
use App\Domains\Support\Contracts\TicketPage;
use App\Domains\Support\Contracts\TicketQueries;
use App\Extensions\Contracts\DomainEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use InvalidArgumentException;

/** Native owner. No legacy writers, synchronous hooks, mail, or remote I/O. */
final class NativeTicketWorkflow implements TicketQueries, TicketCommands
{
    public function __construct(private readonly CurrentSessionAuthorization $sessions,
        private readonly EventJournal $events, private readonly CurrentInvocation $invocation,
        private readonly \App\Domains\Support\Contracts\TicketAttachments $attachments) {}

    public function listing(int $actorId, TicketListQuery $query, bool $admin = false): TicketPage
    {
        return $this->read($actorId, $admin, function () use ($actorId, $query, $admin): TicketPage {
            $rows = DB::table('tx_ticket')->useWritePdo();
            if (!$admin) $rows->where('user_id', $actorId);
            elseif ($query->userId !== null) $rows->where('user_id', $query->userId);
            if ($query->status !== null) $rows->where('status', $query->status);
            if ($query->replyStatus !== null) $rows->where('reply_status', $query->replyStatus);
            if ($admin && $query->assignment === 'mine') $rows->where('assigned_admin_id', $actorId);
            if ($admin && $query->assignment === 'unassigned') $rows->whereNull('assigned_admin_id');
            $page = $rows->orderByDesc('updated_at')->orderByDesc('id')->paginate($query->perPage,
                ['id', 'user_id', 'subject', 'level', 'status', 'reply_status', 'row_version', 'assigned_admin_id', 'created_at', 'updated_at'],
                'page', $query->page);
            return new TicketPage(array_map(self::summary(...), $page->items()),
                ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                    'total' => $page->total(), 'last_page' => $page->lastPage()]);
        });
    }

    public function find(int $actorId, int $ticketId, bool $admin = false): ?array
    {
        if ($ticketId < 1) throw new InvalidArgumentException('SUPPORT_TICKET_QUERY_INVALID');
        return $this->read($actorId, $admin, function () use ($actorId, $ticketId, $admin): ?array {
            $query = DB::table('tx_ticket')->useWritePdo()->where('id', $ticketId);
            if (!$admin) $query->where('user_id', $actorId);
            $ticket = $query->sharedLock()->first();
            return $ticket === null ? null : self::summary($ticket);
        });
    }

    public function messages(int $actorId, int $ticketId, string $afterId, int $limit, bool $admin = false): ?TicketPage
    {
        if ($ticketId < 1 || !self::decimal($afterId, true) || $limit < 1 || $limit > 20) {
            throw new InvalidArgumentException('SUPPORT_TICKET_QUERY_INVALID');
        }
        return $this->read($actorId, $admin, function (array $installation) use ($actorId, $ticketId, $afterId, $limit, $admin): ?TicketPage {
            $query = DB::table('tx_ticket')->useWritePdo()->where('id', $ticketId);
            if (!$admin) $query->where('user_id', $actorId);
            if ($query->sharedLock()->first() === null) return null;
            $rows = DB::table('tx_ticket_message')->useWritePdo()->where('ticket_id', $ticketId)
                ->where('id', '>', $afterId)->orderBy('id')->limit($limit + 1)
                ->get(['id', 'message', 'author_kind', 'created_at']);
            $items = $rows->take($limit)->map(static fn ($row): array => [
                'id' => (string) $row->id, 'message' => (string) $row->message,
                'author_kind' => $row->author_kind ?? 'unknown', 'created_at' => self::date($row->created_at),
            ])->all();
            $files = $this->attachments->forMessagesLocked($installation, $actorId, $ticketId, array_column($items, 'id'), $admin);
            foreach ($items as &$item) $item['attachments'] = $files[$item['id']] ?? [];
            unset($item);
            return new TicketPage($items, ['next_after_id' => $items === [] ? $afterId : $items[array_key_last($items)]['id'],
                'has_more' => $rows->count() > $limit]);
        });
    }

    public function audit(int $actorId, int $ticketId, string $afterId, int $limit): ?TicketPage
    {
        if ($ticketId < 1 || !self::decimal($afterId, true) || $limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('SUPPORT_TICKET_QUERY_INVALID');
        }
        return $this->read($actorId, true, function (array $installation) use ($ticketId, $afterId, $limit): ?TicketPage {
            if (DB::table('tx_ticket')->useWritePdo()->where('id', $ticketId)->sharedLock()->first() === null) return null;
            $rows = DB::table('tx_support_audit')->useWritePdo()->where('installation_id', $installation['installation_id'])
                ->where('ticket_id', $ticketId)->where('id', '>', $afterId)->orderBy('id')->limit($limit + 1)->get();
            $items = $rows->take($limit)->map(static function (object $row): array {
                if (!hash_equals($row->content_hash, hash('sha256', $row->payload_json))) {
                    throw new LogicException('SUPPORT_AUDIT_EVIDENCE_INVALID');
                }
                $payload = json_decode($row->payload_json, true, 16, JSON_THROW_ON_ERROR);
                $fields = ['installation_id', 'ticket_id', 'row_version', 'action', 'actor_id',
                    'actor_kind', 'assigned_admin_id', 'occurred_at'];
                if (!is_array($payload) || count($payload) !== count($fields) || array_diff($fields, array_keys($payload))
                    || ($payload['installation_id'] ?? null) !== $row->installation_id
                    || ($payload['ticket_id'] ?? null) !== (string) $row->ticket_id
                    || ($payload['row_version'] ?? null) !== (string) $row->row_version
                    || !is_string($payload['actor_id']) || !self::decimal($payload['actor_id'])
                    || !in_array($payload['action'], ['create', 'reply', 'close', 'claim', 'unassign'], true)
                    || !in_array($payload['actor_kind'], ['admin', 'user'], true)
                    || ($payload['assigned_admin_id'] !== null && (!is_string($payload['assigned_admin_id'])
                        || !self::decimal($payload['assigned_admin_id'])))
                    || !is_string($payload['occurred_at'])
                    || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\+00:00\z/D', $payload['occurred_at']) !== 1) {
                    throw new LogicException('SUPPORT_AUDIT_EVIDENCE_INVALID');
                }
                unset($payload['installation_id']);
                return ['id' => (string) $row->id] + $payload;
            })->all();
            return new TicketPage($items, ['next_after_id' => $items === [] ? $afterId : $items[array_key_last($items)]['id'],
                'has_more' => $rows->count() > $limit]);
        });
    }

    public function claim(int $actorId, array $input, string $key): array
    {
        return $this->assignment($actorId, $input, $key, true);
    }

    public function unassign(int $actorId, array $input, string $key): array
    {
        return $this->assignment($actorId, $input, $key, false);
    }

    private function assignment(int $actorId, array $input, string $key, bool $claim): array
    {
        self::fields($input, ['ticket_id', 'expected_version']);
        self::identity($input);
        return $this->mutate($actorId, $claim ? 'claim' : 'unassign', $input, $key, true,
            function () use ($actorId, $input, $claim): array {
                $ticket = $this->lockedTicket($actorId, $input, true);
                if ((int) $ticket->status !== 0 || ($claim ? $ticket->assigned_admin_id !== null
                    : (string) $ticket->assigned_admin_id !== (string) $actorId)) abort(409);
                $version = self::nextVersion($ticket);
                $assigned = $claim ? $actorId : null;
                DB::table('tx_ticket')->where('id', $ticket->id)->update(['assigned_admin_id' => $assigned,
                    'row_version' => $version, 'updated_at' => time()]);
                return ['ticket_id' => (string) $ticket->id, 'row_version' => (string) $version,
                    'assigned_admin_id' => $assigned === null ? null : (string) $assigned];
            });
    }

    public function create(int $actorId, array $input, string $key): array
    {
        self::fields($input, ['subject', 'level', 'message']);
        self::text($input['subject'], 1020, 255);
        self::text($input['message'], 10000, 10000);
        if (!is_int($input['level']) || !in_array($input['level'], [0, 1, 2], true)) {
            throw new InvalidArgumentException('SUPPORT_TICKET_INPUT_INVALID');
        }
        return $this->mutate($actorId, 'create', $input, $key, false,
            function () use ($actorId, $input): array {
                // The account lock serializes the one-open-ticket rule across creation keys.
                if (DB::table('tx_ticket')->where('user_id', $actorId)->where('status', 0)->lockForUpdate()->first()) abort(409);
                $now = time();
                $id = DB::table('tx_ticket')->insertGetId(['user_id' => $actorId, 'subject' => $input['subject'],
                    'level' => $input['level'], 'status' => 0, 'reply_status' => 0, 'row_version' => 1,
                    'last_reply_user_id' => $actorId, 'created_at' => $now, 'updated_at' => $now]);
                $message = $this->appendMessage($id, $actorId, 'user', $input['message'], $now);
                return ['ticket_id' => (string) $id, 'message_id' => (string) $message,
                    'row_version' => '1', 'status' => 0];
            });
    }

    public function reply(int $actorId, array $input, string $key, bool $admin = false): array
    {
        self::fields($input, isset($input['attachment_ids'])
            ? ['ticket_id', 'expected_version', 'message', 'attachment_ids'] : ['ticket_id', 'expected_version', 'message']);
        $ids = $input['attachment_ids'] ?? [];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 4 || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) {
            throw new InvalidArgumentException('SUPPORT_TICKET_INPUT_INVALID');
        }
        foreach ($ids as $id) if (!is_string($id) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $id) !== 1) {
            throw new InvalidArgumentException('SUPPORT_TICKET_INPUT_INVALID');
        }
        self::text($input['message'], 10000, 10000);
        self::identity($input);
        return $this->mutate($actorId, 'reply', $input, $key, $admin,
            function (array $installation) use ($actorId, $input, $ids, $admin): array {
                $ticket = $this->lockedTicket($actorId, $input, $admin);
                if ((int) $ticket->status !== 0) abort(409);
                $now = time();
                $message = $this->appendMessage((int) $ticket->id, $actorId, $admin ? 'admin' : 'user', $input['message'], $now);
                $this->attachments->bindLocked($installation, $actorId, (int) $ticket->id, $message, $ids, $admin);
                $version = self::nextVersion($ticket);
                DB::table('tx_ticket')->where('id', $ticket->id)->update(['row_version' => $version,
                    'reply_status' => $admin ? 1 : 0, 'last_reply_user_id' => $actorId, 'updated_at' => $now]);
                return ['ticket_id' => (string) $ticket->id, 'message_id' => (string) $message,
                    'row_version' => (string) $version, 'status' => 0];
            });
    }

    public function close(int $actorId, array $input, string $key, bool $admin = false): array
    {
        self::fields($input, ['ticket_id', 'expected_version']);
        self::identity($input);
        return $this->mutate($actorId, 'close', $input, $key, $admin,
            function () use ($actorId, $input, $admin): array {
                $ticket = $this->lockedTicket($actorId, $input, $admin);
                if ((int) $ticket->status !== 0) abort(409);
                $version = self::nextVersion($ticket);
                DB::table('tx_ticket')->where('id', $ticket->id)->update(['status' => 1,
                    'row_version' => $version, 'updated_at' => time()]);
                return ['ticket_id' => (string) $ticket->id, 'message_id' => null,
                    'row_version' => (string) $version, 'status' => 1];
            });
    }

    private function read(int $actorId, bool $admin, \Closure $read): mixed
    {
        $this->fresh();
        return DB::transaction(function () use ($actorId, $admin, $read): mixed {
            $installation = app(InstallationAuthority::class)->assertNativeRead();
            $this->sessions->assertCurrentLocked($actorId, $admin ? 'support.tickets.read' : null);
            return $read($installation);
        }, 3);
    }

    private function mutate(int $actorId, string $operation, array $input, string $key, bool $admin, \Closure $change): array
    {
        $this->fresh();
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/Di', $key) !== 1) {
            throw new InvalidArgumentException('SUPPORT_TICKET_KEY_INVALID');
        }
        return DB::transaction(function () use ($actorId, $operation, $input, $key, $admin, $change): array {
            $installation = app(InstallationAuthority::class)->assertNativeMutation();
            $this->sessions->assertCurrentLocked($actorId, $admin ? 'support.tickets.write' : null);
            $scope = ($admin ? 'admin.' : 'user.').$operation;
            $keyHash = hash('sha256', $installation['installation_id'].':'.$actorId.':'.$scope.':'.strtolower($key));
            ksort($input);
            $inputHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $old = DB::table('tx_support_request')->where('key_hash', $keyHash)->lockForUpdate()->first();
            if ($old !== null) {
                if ($old->installation_id !== $installation['installation_id'] || (int) $old->actor_id !== $actorId
                    || $old->operation !== $scope || !hash_equals($old->response_hash, hash('sha256', $old->input_hash.':'.$old->response_json))) {
                    throw new LogicException('SUPPORT_REQUEST_EVIDENCE_INVALID');
                }
                if (!hash_equals($old->input_hash, $inputHash)) abort(409);
                return json_decode($old->response_json, true, 16, JSON_THROW_ON_ERROR);
            }
            $result = $change($installation);
            $now = time();
            $context = $this->invocation->current();
            if ($context === null) throw new LogicException('SUPPORT_INVOCATION_REQUIRED');
            $ticket = DB::table('tx_ticket')->useWritePdo()->where('id', $result['ticket_id'])->first();
            if ($ticket === null || (string) $ticket->row_version !== $result['row_version']) {
                throw new LogicException('SUPPORT_TICKET_EVIDENCE_INVALID');
            }
            $audit = ['installation_id' => $installation['installation_id'], 'ticket_id' => $result['ticket_id'],
                'row_version' => $result['row_version'], 'action' => $operation, 'actor_id' => (string) $actorId,
                'actor_kind' => $admin ? 'admin' : 'user',
                'assigned_admin_id' => $ticket->assigned_admin_id === null ? null : (string) $ticket->assigned_admin_id,
                'occurred_at' => self::date($now)];
            $auditJson = json_encode($audit, JSON_THROW_ON_ERROR);
            DB::table('tx_support_audit')->insert(['installation_id' => $installation['installation_id'],
                'ticket_id' => $result['ticket_id'], 'row_version' => $result['row_version'],
                'payload_json' => $auditJson, 'content_hash' => hash('sha256', $auditJson)]);
            $kind = 'support.ticket.'.match ($operation) { 'create' => 'created', 'reply' => 'replied', 'close' => 'closed',
                'claim' => 'claimed', 'unassign' => 'unassigned' }.'.v1';
            $eventId = hash('sha256', $installation['installation_id'].':'.$kind.':'.$result['ticket_id'].':'.$result['row_version']);
            $this->events->appendLocked(new DomainEvent($eventId, $installation['installation_id'], $kind,
                'ticket:'.$result['ticket_id'], $result + ['actor_id' => (string) $actorId,
                    'actor_kind' => $admin ? 'admin' : 'user'], $now, $context->correlationId));
            $responseJson = json_encode($result, JSON_THROW_ON_ERROR);
            DB::table('tx_support_request')->insert(['key_hash' => $keyHash,
                'installation_id' => $installation['installation_id'], 'actor_id' => $actorId,
                'operation' => $scope, 'input_hash' => $inputHash,
                'response_json' => $responseJson, 'response_hash' => hash('sha256', $inputHash.':'.$responseJson), 'created_at' => $now]);
            return $result;
        }, 3);
    }

    private function lockedTicket(int $actorId, array $input, bool $admin): object
    {
        $query = DB::table('tx_ticket')->where('id', $input['ticket_id']);
        if (!$admin) $query->where('user_id', $actorId);
        $ticket = $query->lockForUpdate()->first();
        if ($ticket === null) abort(404);
        if ((string) $ticket->row_version !== $input['expected_version']) abort(409);
        return $ticket;
    }

    private function appendMessage(int $ticketId, int $actorId, string $kind, string $message, int $now): int
    {
        return DB::table('tx_ticket_message')->insertGetId(['ticket_id' => $ticketId, 'user_id' => $actorId,
            'author_kind' => $kind, 'message' => $message, 'created_at' => $now, 'updated_at' => $now]);
    }

    private static function summary(object $row): array
    {
        if (!self::decimal((string) $row->row_version) || !in_array((int) $row->status, [0, 1], true)
            || !in_array((int) $row->reply_status, [0, 1], true)
            || ($row->assigned_admin_id !== null && !self::decimal((string) $row->assigned_admin_id))) {
            throw new LogicException('SUPPORT_TICKET_EVIDENCE_INVALID');
        }
        return ['id' => (string) $row->id, 'user_id' => (string) $row->user_id,
            'subject' => (string) $row->subject, 'level' => (int) $row->level,
            'status' => (int) $row->status, 'reply_status' => (int) $row->reply_status,
            'assigned_admin_id' => $row->assigned_admin_id === null ? null : (string) $row->assigned_admin_id,
            'row_version' => (string) $row->row_version, 'created_at' => self::date($row->created_at),
            'updated_at' => self::date($row->updated_at)];
    }

    private static function fields(array $input, array $fields): void
    {
        if (count($input) !== count($fields) || array_diff($fields, array_keys($input))) {
            throw new InvalidArgumentException('SUPPORT_TICKET_INPUT_INVALID');
        }
    }

    private static function identity(array $input): void
    {
        if (!is_string($input['ticket_id']) || !self::decimal($input['ticket_id'])
            || !is_string($input['expected_version']) || !self::decimal($input['expected_version'])) {
            throw new InvalidArgumentException('SUPPORT_TICKET_INPUT_INVALID');
        }
    }

    private static function decimal(string $value, bool $zero = false): bool
    {
        return preg_match($zero ? '/\A(?:0|[1-9][0-9]{0,18})\z/D' : '/\A[1-9][0-9]{0,18}\z/D', $value) === 1
            && (strlen($value) < 19 || strcmp($value, (string) PHP_INT_MAX) <= 0);
    }

    private static function text(mixed $value, int $bytes, int $characters): void
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $bytes || preg_match('//u', $value) !== 1
            || mb_strlen($value, 'UTF-8') > $characters || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1) {
            throw new InvalidArgumentException('SUPPORT_TICKET_INPUT_INVALID');
        }
    }

    private static function nextVersion(object $ticket): int
    {
        if (!self::decimal((string) $ticket->row_version) || (int) $ticket->row_version === PHP_INT_MAX) {
            throw new LogicException('SUPPORT_TICKET_VERSION_EXHAUSTED');
        }
        return (int) $ticket->row_version + 1;
    }

    private static function date(mixed $value): string { return Carbon::createFromTimestampUTC((int) $value)->toIso8601String(); }

    private function fresh(): void
    {
        if (DB::transactionLevel() !== 0) throw new LogicException('SUPPORT_OUTER_TRANSACTION_UNSUPPORTED');
    }
}
