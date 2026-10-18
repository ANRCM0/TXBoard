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
            $request->has('plan_id') ? (int) $request->input('plan_id') : null);
        return TxapiResponse::success($request, $page['items'], $page['meta']);
    }
}
