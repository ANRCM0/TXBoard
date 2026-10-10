<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Domains\Content\ContentReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class ContentController
{
    public function notices(Request $request, ContentReader $reader): JsonResponse
    {
        $params = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        [$items, $meta] = $reader->notices(
            (int) ($params['page'] ?? 1), (int) ($params['per_page'] ?? 20)
        );
        return TxapiResponse::success($request, $items, $meta);
    }

    public function knowledge(Request $request, ContentReader $reader): JsonResponse
    {
        $params = $request->validate([
            'language' => ['nullable', 'string', 'max:10'],
            'keyword' => ['nullable', 'string', 'max:255'],
        ]);
        $items = $reader->articles(Auth::guard('sanctum')->user(),
            $params['language'] ?? null, $params['keyword'] ?? null);
        return TxapiResponse::success($request, $items);
    }

    public function categories(Request $request, ContentReader $reader): JsonResponse
    {
        $params = $request->validate(['language' => ['nullable', 'string', 'max:10']]);
        return TxapiResponse::success($request, $reader->categories($params['language'] ?? null));
    }

    public function article(Request $request, ContentReader $reader, int $articleId): JsonResponse
    {
        $item = $reader->article($articleId, Auth::guard('sanctum')->user());
        if ($item === null) {
            abort(404);
        }
        return TxapiResponse::success($request, $item);
    }
}
