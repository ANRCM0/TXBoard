<?php

namespace App\Domains\Network;

use App\Models\Server;
use App\Models\User;
use App\Support\Database\NativeTableName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class NativeAccessAudit
{
    public const MAX_BATCH = 200;
    public const RETENTION_DAYS = 30;

    public static function rulesTable(): string
    {
        return NativeTableName::runtime('v2_access_audit_rule');
    }

    public static function eventsTable(): string
    {
        return NativeTableName::runtime('v2_access_audit_event');
    }

    public function rules(): array
    {
        return DB::table(self::rulesTable())->where('enabled', true)->orderBy('id')
            ->get(['id', 'name', 'match_type', 'match_value'])->map(
                static fn ($row): array => [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'match_type' => (string) $row->match_type,
                    'match_value' => (string) $row->match_value,
                ]
            )->all();
    }

    /**
     * The authenticated Node identity comes solely from TxNodeAuth middleware.
     * Events carry random per-observation IDs so retries cannot duplicate logs.
     * The audit subsystem never mutates user balances or traffic counters.
     */
    public function ingest(Server $node, array $body): array
    {
        $params = Validator::make($body, [
            'protocol_version' => ['required', 'integer', 'in:1'],
            'events' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'events.*.event_id' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/D'],
            'events.*.user_id' => ['required', 'integer', 'min:1'],
            'events.*.target' => ['required', 'string', 'max:255'],
            'events.*.target_ip' => ['nullable', 'ip', 'max:64'],
            'events.*.source_ip' => ['nullable', 'ip', 'max:64'],
            'events.*.matched' => ['required', 'boolean'],
        ])->validate();

        $events = $params['events'];
        $ids = array_column($events, 'event_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['events' => 'Duplicate event_id in a batch']);
        }

        $userIds = array_values(array_unique(array_column($events, 'user_id')));
        $validUserIds = User::query()->whereIn('id', $userIds)->pluck('id')->map(
            static fn ($id): int => (int) $id
        )->all();
        if (count($validUserIds) !== count($userIds)) {
            throw ValidationException::withMessages(['events' => 'Unknown audit user']);
        }

        $now = time();
        $rows = array_map(static fn (array $event): array => [
            'server_id' => (int) $node->id,
            'user_id' => (int) $event['user_id'],
            'event_id' => $event['event_id'],
            'target' => strtolower(trim($event['target'])),
            'target_ip' => $event['target_ip'] ?? null,
            'source_ip' => $event['source_ip'] ?? null,
            'matched' => (bool) $event['matched'],
            'created_at' => $now,
        ], $events);
        $inserted = DB::table(self::eventsTable())->insertOrIgnore($rows);

        return [
            'protocol_version' => 1,
            'accepted' => true,
            'received' => count($rows),
            'inserted' => (int) $inserted,
        ];
    }
}
