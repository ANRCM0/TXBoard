<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\Subscription\Contracts\AdminCatalogQueries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NativeCatalogReadController
{
    public function offers(Request $request, AdminCatalogQueries $catalog): JsonResponse
    {
        $page = $catalog->offers((int) $request->input('page', 1), (int) $request->input('per_page', 20),
            $request->has('plan_id') ? (int) $request->input('plan_id') : null, $request->input('status'));
        return TxapiResponse::success($request, $page['items'], $page['meta']);
    }

    public function plans(Request $request, AdminCatalogQueries $catalog): JsonResponse
    {
        $page = $catalog->plans((int) $request->input('page', 1), (int) $request->input('per_page', 20));
        return TxapiResponse::success($request, $page['items'], $page['meta']);
    }

    public function publication(Request $request, AdminCatalogQueries $catalog): JsonResponse
    {
        $data = $catalog->publication((int) $request->input('plan_id'),
            $request->has('revision_id') ? (int) $request->input('revision_id') : null);
        if ($data === null) abort(404);
        return TxapiResponse::success($request, $data);
    }

    public function history(Request $request, AdminCatalogQueries $catalog): JsonResponse
    {
        $page = $catalog->history((int) $request->input('plan_id'),
            (int) $request->input('page', 1), (int) $request->input('per_page', 20));
        if ($page === null) abort(404);
        return TxapiResponse::success($request, $page['items'], $page['meta']);
    }
}
