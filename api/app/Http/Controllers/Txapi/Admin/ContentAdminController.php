<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Models\Knowledge;
use App\Models\Notice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * TXBoard-owned Admin content CRUD. Never expose administrative content via
 * the subscriber endpoint: drafts are intentionally visible to editors only.
 */
final class ContentAdminController
{
    public function notices(Request $request): JsonResponse
    {
        $params = $request->validate([
            'page' => ['sometimes','integer','min:1'],
            'per_page' => ['sometimes','integer','min:1','max:100'],
            'title' => ['sometimes','nullable','string','max:255'],
        ]);
        $query = Notice::query();
        if (!empty($params['title'])) {
            $query->where('title', 'like', '%' . $params['title'] . '%');
        }
        $page = $query->orderBy('sort')->orderByDesc('id')
            ->paginate((int) ($params['per_page'] ?? 20),
                ['id','title','content','img_url','tags','show','popup','sort','created_at','updated_at'],
                'page', (int) ($params['page'] ?? 1));
        return TxapiResponse::success($request,
            $page->getCollection()->map(static fn (Notice $n): array => self::noticeDto($n))->all(),
            self::meta($page));
    }

    public function notice(Request $request, string $id): JsonResponse
    {
        $id = (string) $request->route('id');
        $notice = Notice::query()->findOrFail((int) $id);
        return TxapiResponse::success($request, self::noticeDto($notice));
    }

    public function saveNotice(Request $request, ?string $id = null): JsonResponse
    {
        // A parent {admin_path} route parameter is not a content resource ID.
        // Read only the explicitly named {id} to distinguish POST create vs PUT update.
        $id = $request->route('id');
        $params = $request->validate([
            'title' => ['required','string','max:255'],
            'content' => ['required','string','max:1000000'],
            'img_url' => ['nullable','url','max:2048'],
            'tags' => ['nullable','array','max:30'],
            'tags.*' => ['string','max:80'],
            'show' => ['sometimes','boolean'],
            'popup' => ['sometimes','boolean'],
        ]);
        $notice = $id === null ? new Notice() : Notice::query()->findOrFail((int) $id);
        $notice->fill($params);
        $notice->saveOrFail();
        return TxapiResponse::success($request, ['id' => (int) $notice->id],
            status: $id === null ? 201 : 200);
    }

    public function showNotice(Request $request, string $id): JsonResponse
    {
        $id = (string) $request->route('id');
        $visible = DB::transaction(static function () use ($id): bool {
            $n = Notice::query()->lockForUpdate()->findOrFail((int) $id);
            $n->show = !$n->show;
            $n->saveOrFail();
            return (bool) $n->show;
        });
        return TxapiResponse::success($request, ['show' => $visible]);
    }

    public function deleteNotice(Request $request, string $id): JsonResponse
    {
        $id = (string) $request->route('id');
        Notice::query()->findOrFail((int) $id)->deleteOrFail();
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function sortNotices(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required','array','min:1','max:5000'],
            'ids.*' => ['required','integer','min:1','distinct']])['ids'];
        self::sortModel(Notice::class, $ids);
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function knowledge(Request $request): JsonResponse
    {
        $params = $request->validate([
            'page' => ['sometimes','integer','min:1'],
            'per_page' => ['sometimes','integer','min:1','max:100'],
            'title' => ['sometimes','nullable','string','max:255'],
            'category' => ['sometimes','nullable','string','max:255'],
        ]);
        $query = Knowledge::query();
        if (!empty($params['title'])) $query->where('title', 'like', '%' . $params['title'] . '%');
        if (!empty($params['category'])) $query->where('category', $params['category']);
        $page = $query->orderBy('sort')->orderBy('id')
            ->paginate((int) ($params['per_page'] ?? 20),
                ['id','title','category','language','show','sort','updated_at'],
                'page', (int) ($params['page'] ?? 1));
        return TxapiResponse::success($request,
            $page->getCollection()->map(static fn (Knowledge $k): array => self::knowledgeDto($k, false))->all(),
            self::meta($page));
    }

    public function categories(Request $request): JsonResponse
    {
        return TxapiResponse::success($request,
            Knowledge::query()->whereNotNull('category')->distinct()->orderBy('category')
                ->pluck('category')->all());
    }

    public function article(Request $request, string $id): JsonResponse
    {
        $id = (string) $request->route('id');
        return TxapiResponse::success($request,
            self::knowledgeDto(Knowledge::query()->findOrFail((int) $id), true));
    }

    public function saveArticle(Request $request, ?string $id = null): JsonResponse
    {
        // A parent {admin_path} route parameter is not a content resource ID.
        // Read only the explicitly named {id} to distinguish POST create vs PUT update.
        $id = $request->route('id');
        $params = $request->validate([
            'title' => ['required','string','max:255'],
            'category' => ['required','string','max:255'],
            'language' => ['required','string','max:32'],
            'body' => ['required','string','max:1000000'],
            'show' => ['sometimes','boolean'],
        ]);
        $article = $id === null ? new Knowledge() : Knowledge::query()->findOrFail((int) $id);
        $article->fill($params);
        $article->saveOrFail();
        return TxapiResponse::success($request, ['id' => (int) $article->id],
            status: $id === null ? 201 : 200);
    }

    public function showArticle(Request $request, string $id): JsonResponse
    {
        $id = (string) $request->route('id');
        $visible = DB::transaction(static function () use ($id): bool {
            $k = Knowledge::query()->lockForUpdate()->findOrFail((int) $id);
            $k->show = !$k->show;
            $k->saveOrFail();
            return (bool) $k->show;
        });
        return TxapiResponse::success($request, ['show' => $visible]);
    }

    public function deleteArticle(Request $request, string $id): JsonResponse
    {
        $id = (string) $request->route('id');
        Knowledge::query()->findOrFail((int) $id)->deleteOrFail();
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function sortArticles(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required','array','min:1','max:5000'],
            'ids.*' => ['required','integer','min:1','distinct']])['ids'];
        self::sortModel(Knowledge::class, $ids);
        return TxapiResponse::success($request, ['ok' => true]);
    }

    private static function sortModel(string $model, array $ids): void
    {
        DB::transaction(static function () use ($model, $ids): void {
            // Refuse partial sorts and missing IDs instead of partly changing
            // the admin ordering. Lock rows in stable ID order.
            $records = $model::query()->whereIn('id', $ids)->orderBy('id')
                ->lockForUpdate()->get()->keyBy('id');
            if ($records->count() !== count($ids)) {
                abort(404);
            }
            foreach ($ids as $i => $id) {
                /** @var Model $record */
                $record = $records->get((int) $id);
                $record->sort = $i + 1;
                $record->saveOrFail();
            }
        });
    }

    private static function meta($p): array
    {
        return ['page' => $p->currentPage(), 'per_page' => $p->perPage(),
            'total' => $p->total(), 'last_page' => $p->lastPage()];
    }

    private static function noticeDto(Notice $n): array
    {
        return ['id' => (int) $n->id, 'title' => (string) $n->title,
            'content' => (string) $n->content, 'img_url' => $n->img_url,
            'tags' => $n->tags ?? [], 'show' => (bool) $n->show,
            'popup' => (bool) $n->popup, 'sort' => (int) $n->sort,
            'created_at' => (int) $n->created_at, 'updated_at' => (int) $n->updated_at];
    }

    private static function knowledgeDto(Knowledge $k, bool $detail): array
    {
        $row = ['id' => (int) $k->id, 'title' => (string) $k->title,
            'category' => (string) $k->category, 'language' => (string) $k->language,
            'show' => (bool) $k->show, 'sort' => (int) $k->sort,
            'updated_at' => (int) $k->updated_at];
        if ($detail) $row['body'] = (string) $k->body;
        return $row;
    }
}
