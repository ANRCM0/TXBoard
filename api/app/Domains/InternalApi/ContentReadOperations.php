<?php

namespace App\Domains\InternalApi;

use App\Domains\Content\Contracts\ContentListQuery;
use App\Domains\Content\Contracts\ContentQueries;
use App\Models\User;

final class ContentReadOperations
{
    public function __construct(private readonly ContentQueries $queries) {}

    public function noticeList(User $actor, array $input): ReadOperationResult { return $this->listing($actor, 'notices', $input, true); }
    public function articleList(User $actor, array $input): ReadOperationResult { return $this->listing($actor, 'knowledge', $input, true); }
    public function userNoticeList(User $actor, array $input): ReadOperationResult { return $this->listing($actor, 'notices', $input, false); }
    public function userArticleList(User $actor, array $input): ReadOperationResult { return $this->listing($actor, 'knowledge', $input, false); }
    public function noticeGet(User $actor, array $input): ?ReadOperationResult { return $this->detail($actor, 'notices', $input, true); }
    public function articleGet(User $actor, array $input): ?ReadOperationResult { return $this->detail($actor, 'knowledge', $input, true); }
    public function userNoticeGet(User $actor, array $input): ?ReadOperationResult { return $this->detail($actor, 'notices', $input, false); }
    public function userArticleGet(User $actor, array $input): ?ReadOperationResult { return $this->detail($actor, 'knowledge', $input, false); }
    public function categories(User $actor, array $input): ReadOperationResult { return new ReadOperationResult($this->queries->categories((int) $actor->id, $input['language'] ?? null, true)); }
    public function userCategories(User $actor, array $input): ReadOperationResult { return new ReadOperationResult($this->queries->categories((int) $actor->id, $input['language'] ?? null)); }
    public function audit(User $actor, array $input): ReadOperationResult
    {
        $page = $this->queries->audit((int) $actor->id, $input['after_id'] ?? '0', (int) ($input['limit'] ?? 10));
        return new ReadOperationResult($page->items, $page->meta);
    }

    private function listing(User $actor, string $kind, array $input, bool $admin): ReadOperationResult
    {
        $page = $this->queries->listing((int) $actor->id, new ContentListQuery($kind,
            (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 20),
            $input['title'] ?? null, $input['category'] ?? null, $input['language'] ?? null), $admin);
        return new ReadOperationResult($page->items, $page->meta);
    }

    private function detail(User $actor, string $kind, array $input, bool $admin): ?ReadOperationResult
    {
        $row = $this->queries->find((int) $actor->id, $kind, $input['content_id'], $admin);
        return $row === null ? null : new ReadOperationResult($row);
    }
}
