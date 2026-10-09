<?php

namespace App\Domains\Content;

use App\Models\Knowledge;
use App\Models\Notice;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Support\Carbon;

/**
 * P2 content read model. User-scoped article bodies are never shared/cached.
 * Preserve legacy placeholder & subscription gating semantics, including
 * knowledge.resource filter used by installed extensions.
 */
final class ContentReader
{
    public function notices(int $page, int $perPage): array
    {
        $paginator = Notice::query()->where('show', true)
            ->orderBy('sort')->orderByDesc('id')
            ->paginate($perPage, ['id', 'title', 'content', 'img_url', 'tags', 'created_at'], 'page', $page);
        $items = $paginator->getCollection()->map(static fn (Notice $n): array => [
            'id' => (int) $n->id,
            'title' => (string) $n->title,
            'content' => (string) ($n->content ?? ''),
            'img_url' => $n->img_url,
            'tags' => $n->tags ?? [],
            'created_at' => Carbon::createFromTimestampUTC((int) $n->created_at)->toIso8601String(),
        ])->all();
        return [$items, $this->meta($paginator)];
    }

    public function categories(?string $language): array
    {
        return $this->query($language)->whereNotNull('category')
            ->orderBy('sort')->orderBy('id')
            ->pluck('category')->filter()->unique()->values()->all();
    }

    public function articles(User $user, ?string $language, ?string $keyword): array
    {
        $q = $this->query($language)->orderBy('sort')->orderBy('id');
        if ($keyword !== null && $keyword !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
            $q->where(static fn ($query) => $query->where('title', 'like', '%'.$escaped.'%')
                ->orWhere('body', 'like', '%'.$escaped.'%'));
        }
        return $q->get()->map(fn (Knowledge $article): array => $this->articleDto($article, $user))->all();
    }

    public function article(int $articleId, User $user): ?array
    {
        $article = Knowledge::query()->where('show', true)->find($articleId);
        return $article ? $this->articleDto($article, $user) : null;
    }

    private function query(?string $language)
    {
        return Knowledge::query()->where('show', true)->where('language', $language);
    }

    private function articleDto(Knowledge $article, User $user): array
    {
        $body = (string) ($article->body ?? '');
        if (!app(UserService::class)->isAvailable($user)) {
            $body = preg_replace('/<!--access start-->(.*?)<!--access end-->/s',
                '<div class="v2board-no-access">'.__('You must have a valid subscription to view content in this area').'</div>',
                $body);
        }
        $subscription = Helper::getSubscribeUrl($user->token);
        $body = str_replace(
            ['{{siteName}}', '{{subscribeUrl}}', '{{urlEncodeSubscribeUrl}}', '{{safeBase64SubscribeUrl}}'],
            [admin_setting('app_name', 'TXBoard'), $subscription, urlencode($subscription),
                str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($subscription))],
            $body
        );

        $data = [
            'id' => (int) $article->id,
            'category' => (string) ($article->category ?? ''),
            'title' => (string) $article->title,
            'body' => $body,
            'updated_at' => Carbon::createFromTimestampUTC((int) $article->updated_at)->toIso8601String(),
        ];
        // Existing extensions may customize article resources. Preserve hook
        // semantics while applying an explicit public-field whitelist.
        $filtered = HookManager::filter('user.knowledge.resource', $data);
        if (!is_array($filtered)) {
            $filtered = $data;
        }
        return array_intersect_key($filtered, array_flip(array_keys($data)));
    }

    private function meta($paginator): array
    {
        return [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
