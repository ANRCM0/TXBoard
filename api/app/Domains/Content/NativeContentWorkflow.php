<?php

namespace App\Domains\Content;

use App\Core\Kernel\Execution\CurrentInvocation;
use App\Domains\Content\Contracts\ContentCommands;
use App\Domains\Content\Contracts\ContentListQuery;
use App\Domains\Content\Contracts\ContentPage;
use App\Domains\Content\Contracts\ContentQueries;
use App\Domains\Identity\Internal\CurrentSessionAuthorization;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Notifications\Internal\EventJournal;
use App\Domains\Subscription\Internal\LockedEntitlementQueries;
use App\Extensions\Contracts\DomainEvent;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/** Owns content rows, scheduling, visibility, permanent receipts and the audit trail. */
final class NativeContentWorkflow implements ContentQueries, ContentCommands
{
    public function __construct(private readonly CurrentSessionAuthorization $sessions,
        private readonly LockedEntitlementQueries $entitlements, private readonly EventJournal $events,
        private readonly CurrentInvocation $invocation) {}

    public function listing(int $actorId, ContentListQuery $query, bool $admin = false): ContentPage
    {
        return $this->read($actorId, $admin, function (bool $subscribed) use ($query, $admin): ContentPage {
            $rows = $this->rows($query->kind);
            if (!$admin) $this->visible($rows, $subscribed);
            if ($query->title !== null && $query->title !== '') {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query->title);
                $rows->where('title', 'like', '%'.$escaped.'%');
            }
            if ($query->kind === 'knowledge') {
                if ($query->language !== null) $rows->where('language', $query->language);
                if ($query->category !== null) $rows->where('category', $query->category);
            }
            // Lists deliberately omit bodies: drafts and subscriber sections never enter search summaries.
            $fields = ['id', 'title', 'show', 'sort', 'row_version', 'audience', 'publish_at', 'expire_at', 'created_at', 'updated_at'];
            $fields = array_merge($fields, $query->kind === 'notices' ? ['img_url', 'tags', 'popup'] : ['category', 'language']);
            $page = $rows->orderBy('sort')->orderByDesc('id')->paginate($query->perPage, $fields, 'page', $query->page);
            return new ContentPage(array_map(fn ($row) => $this->dto($query->kind, $row, false, $admin, $subscribed), $page->items()),
                ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]);
        });
    }

    public function find(int $actorId, string $kind, string $id, bool $admin = false): ?array
    {
        ContentInput::table($kind);
        ContentInput::id($id);
        return $this->read($actorId, $admin, function (bool $subscribed) use ($kind, $id, $admin): ?array {
            $rows = $this->rows($kind)->where('id', $id);
            if (!$admin) $this->visible($rows, $subscribed);
            $row = $rows->sharedLock()->first();
            return $row === null ? null : $this->dto($kind, $row, true, $admin, $subscribed);
        });
    }

    public function categories(int $actorId, ?string $language, bool $admin = false): array
    {
        if ($language !== null) ContentInput::text($language, 32);
        return $this->read($actorId, $admin, function (bool $subscribed) use ($language, $admin): array {
            $rows = $this->rows('knowledge');
            if (!$admin) $this->visible($rows, $subscribed);
            if ($language !== null) $rows->where('language', $language);
            $values = $rows->whereNotNull('category')->distinct()->orderBy('category')->limit(1001)->pluck('category')->all();
            if (count($values) > 1000) throw new LogicException('CONTENT_CATEGORY_LIMIT');
            return ['categories' => array_values(array_filter($values, static fn ($value) => is_string($value) && $value !== ''))];
        });
    }

    public function audit(int $actorId, string $afterId, int $limit): ContentPage
    {
        ContentInput::id($afterId, true);
        if ($limit < 1 || $limit > 10) throw new InvalidArgumentException('CONTENT_INPUT_INVALID');
        return $this->read($actorId, true, function () use ($afterId, $limit): ContentPage {
            $rows = DB::table('tx_content_audit')->useWritePdo()->where('id', '>', $afterId)->orderBy('id')->limit($limit + 1)->get();
            $items = $rows->take($limit)->map(static function ($row): array {
                $payload = self::auditPayload($row);
                if (!hash_equals($row->content_hash, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)))) {
                    throw new LogicException('CONTENT_AUDIT_EVIDENCE_INVALID');
                }
                return $payload;
            })->all();
            return new ContentPage($items, ['next_after_id' => $items === [] ? $afterId : $items[array_key_last($items)]['id'],
                'has_more' => $rows->count() > $limit]);
        });
    }

    public function execute(int $actorId, string $kind, string $operation, array $input, string $key): array
    {
        $this->fresh();
        ContentInput::command($kind, $operation, $input, $key);
        return DB::transaction(function () use ($actorId, $kind, $operation, $input, $key): array {
            // Order: installation -> actor/session/grant -> collection -> rows by id -> receipt/audit/event.
            $installation = app(InstallationAuthority::class)->assertNativeMutation();
            $this->sessions->assertCurrentLocked($actorId, 'content.manage');
            // Create the serialization row only after admission; migrations keep fresh databases empty.
            DB::table('tx_content_collection')->insertOrIgnore(['kind' => $kind]);
            $collection = DB::table('tx_content_collection')->where('kind', $kind)->lockForUpdate()->first();
            if ($collection === null) throw new LogicException('CONTENT_COLLECTION_MISSING');
            $scope = $kind.'.'.$operation;
            $keyHash = hash('sha256', $installation['installation_id'].':'.$actorId.':'.$scope.':'.strtolower($key));
            $inputHash = hash('sha256', ContentInput::canonical($input));
            $old = DB::table('tx_content_request')->where('key_hash', $keyHash)->lockForUpdate()->first();
            if ($old !== null) {
                if ($old->installation_id !== $installation['installation_id'] || (int) $old->actor_id !== $actorId
                    || $old->operation !== $scope || !hash_equals($old->response_hash, hash('sha256', $old->input_hash.':'.$old->response_json))) {
                    throw new LogicException('CONTENT_RECEIPT_EVIDENCE_INVALID');
                }
                if (!hash_equals($old->input_hash, $inputHash)) abort(409);
                return json_decode($old->response_json, true, 32, JSON_THROW_ON_ERROR);
            }
            $now = time();
            $changed = [];
            if ($operation === 'sort') {
                $rows = $this->rows($kind)->orderBy('id')->lockForUpdate()->limit(1001)->get()->keyBy('id');
                if ($rows->count() !== count($input['items'])) abort(409);
                foreach ($input['items'] as $item) {
                    $row = $rows->get($item['id']);
                    if ($row === null || (string) $row->row_version !== $item['expected_version']) abort(409);
                }
                foreach ($input['items'] as $position => $item) {
                    $row = $rows->get($item['id']); $version = $this->nextVersion($row);
                    $this->table($kind)->where('id', $row->id)->update(['sort' => $position + 1, 'row_version' => $version, 'updated_at' => $now]);
                    $changed[] = ['id' => (string) $row->id, 'row_version' => (string) $version];
                }
                $result = ['ok' => true, 'items' => $changed];
            } elseif ($operation === 'create') {
                $values = ContentInput::values($kind, $input);
                $id = $this->table($kind)->insertGetId($values + ['row_version' => 1, 'sort' => 0, 'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now]);
                $changed[] = ['id' => (string) $id, 'row_version' => '1'];
                $result = ['id' => (string) $id, 'row_version' => '1'];
            } else {
                $row = $this->rows($kind)->where('id', $input['content_id'])->lockForUpdate()->first();
                if ($row === null) abort(404);
                if ((string) $row->row_version !== $input['expected_version']) abort(409);
                $version = $this->nextVersion($row);
                $values = match ($operation) {
                    'update' => ContentInput::values($kind, $input),
                    'visibility' => ['show' => $input['show']],
                    'delete' => ['show' => false, 'deleted_at' => $now],
                };
                $this->table($kind)->where('id', $row->id)->update($values + ['row_version' => $version, 'updated_at' => $now]);
                $changed[] = ['id' => (string) $row->id, 'row_version' => (string) $version];
                $result = ['id' => (string) $row->id, 'row_version' => (string) $version];
                if ($operation === 'visibility') $result['show'] = $values['show'];
                if ($operation === 'delete') $result['ok'] = true;
            }
            $context = $this->invocation->current();
            if ($context === null) throw new LogicException('CONTENT_INVOCATION_REQUIRED');
            $auditId = DB::table('tx_content_audit')->insertGetId([
                'installation_id' => $installation['installation_id'], 'actor_id' => $actorId, 'kind' => $kind,
                'operation' => $operation, 'changes_json' => json_encode($changed, JSON_THROW_ON_ERROR),
                'input_hash' => $inputHash, 'correlation_id' => $context->correlationId, 'occurred_at' => $now, 'content_hash' => str_repeat('0', 64)]);
            $audit = DB::table('tx_content_audit')->where('id', $auditId)->first();
            DB::table('tx_content_audit')->where('id', $auditId)->update(['content_hash' => hash('sha256', json_encode(self::auditPayload($audit), JSON_THROW_ON_ERROR))]);
            $eventId = hash('sha256', $installation['installation_id'].':content:'.$auditId);
            $this->events->appendLocked(new DomainEvent($eventId, $installation['installation_id'],
                'content.changed.v1', 'content:'.$kind, ['audit_id' => (string) $auditId, 'kind' => $kind,
                    'operation' => $operation, 'changes_count' => count($changed),
                    'changes_hash' => hash('sha256', ContentInput::canonical($changed)),
                    'actor_id' => (string) $actorId], $now, $context->correlationId));
            $responseJson = json_encode($result, JSON_THROW_ON_ERROR);
            DB::table('tx_content_request')->insert(['key_hash' => $keyHash, 'installation_id' => $installation['installation_id'],
                'actor_id' => $actorId, 'operation' => $scope, 'input_hash' => $inputHash, 'response_json' => $responseJson,
                'response_hash' => hash('sha256', $inputHash.':'.$responseJson), 'created_at' => $now]);
            return $result;
        }, 3);
    }

    private function read(int $actorId, bool $admin, \Closure $read): mixed
    {
        $this->fresh();
        return DB::transaction(function () use ($actorId, $admin, $read): mixed {
            app(InstallationAuthority::class)->assertNativeRead();
            $this->sessions->assertCurrentLocked($actorId, $admin ? 'content.read' : null);
            $subscribed = !$admin && ($this->entitlements->resolveLocked($actorId, time())['authorized'] ?? false) === true;
            return $read($subscribed);
        }, 3);
    }

    private function table(string $kind): Builder
    {
        return DB::table(ContentInput::table($kind))->useWritePdo();
    }

    private function rows(string $kind): Builder { return $this->table($kind)->whereNull('deleted_at'); }

    private function visible(Builder $rows, bool $subscribed): void
    {
        $now = time();
        $rows->where('show', true)->whereIn('audience', $subscribed ? ['authenticated', 'subscribed'] : ['authenticated'])
            ->where(static fn ($q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', $now))
            ->where(static fn ($q) => $q->whereNull('expire_at')->orWhere('expire_at', '>', $now));
    }

    private function dto(string $kind, object $row, bool $detail, bool $admin, bool $subscribed): array
    {
        ContentInput::id((string) $row->row_version);
        if (!in_array($row->audience, ['authenticated', 'subscribed'], true)) throw new LogicException('CONTENT_EVIDENCE_INVALID');
        $result = ['id' => (string) $row->id, 'title' => (string) $row->title, 'sort' => (int) $row->sort,
            'row_version' => (string) $row->row_version, 'audience' => $row->audience,
            'publish_at' => $row->publish_at === null ? null : (int) $row->publish_at,
            'expire_at' => $row->expire_at === null ? null : (int) $row->expire_at,
            'created_at' => (int) $row->created_at, 'updated_at' => (int) $row->updated_at];
        if ($admin) $result['show'] = (bool) $row->show;
        if ($kind === 'notices') {
            $result += ['img_url' => $row->img_url, 'tags' => $row->tags === null ? [] : json_decode($row->tags, true, 8, JSON_THROW_ON_ERROR), 'popup' => (bool) $row->popup];
        } else $result += ['category' => (string) $row->category, 'language' => (string) $row->language];
        if ($detail) {
            $field = $kind === 'notices' ? 'content' : 'body';
            $body = (string) $row->$field;
            // Malformed legacy access markers fail closed too; no hidden body is sent to the browser.
            if (!$admin && !$subscribed && (str_contains($body, '<!--access start-->') || str_contains($body, '<!--access end-->'))) {
                $body = '此内容需要有效订阅。';
            }
            $result[$field] = $body;
        }
        return $result;
    }

    private static function auditPayload(object $row): array
    {
        return ['id' => (string) $row->id, 'installation_id' => $row->installation_id, 'actor_id' => (string) $row->actor_id,
            'kind' => $row->kind, 'operation' => $row->operation, 'changes' => json_decode($row->changes_json, true, 32, JSON_THROW_ON_ERROR),
            'input_hash' => $row->input_hash, 'correlation_id' => $row->correlation_id, 'occurred_at' => (int) $row->occurred_at];
    }

    private function nextVersion(object $row): int
    {
        ContentInput::id((string) $row->row_version);
        if ((int) $row->row_version === PHP_INT_MAX) throw new LogicException('CONTENT_VERSION_EXHAUSTED');
        return (int) $row->row_version + 1;
    }

    private function fresh(): void
    {
        if (DB::transactionLevel() !== 0) throw new LogicException('CONTENT_OUTER_TRANSACTION_UNSUPPORTED');
    }
}
