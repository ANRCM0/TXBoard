<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Models\StatUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class TrafficController
{
    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        // Match the existing current-calendar-month policy. Per-user filtering
        // takes place in SQL, never by filtering a global in-memory collection.
        $start = now()->startOfMonth()->timestamp;
        $result = StatUser::query()
            ->where('user_id', Auth::guard('sanctum')->id())
            ->where('record_at', '>=', $start)
            ->orderByDesc('record_at')->orderByDesc('id')
            ->paginate((int) ($params['per_page'] ?? 20),
                ['id', 'u', 'd', 'record_at', 'server_rate'],
                'page', (int) ($params['page'] ?? 1));

        return TxapiResponse::success($request,
            $result->getCollection()->map(static fn (StatUser $item): array => [
                'id' => (int) $item->id,
                'upload_bytes' => (int) $item->u,
                'download_bytes' => (int) $item->d,
                'record_at' => (int) $item->record_at,
                'server_rate' => $item->server_rate === null ? null : (float) $item->server_rate,
            ])->all(),
            ['page' => $result->currentPage(), 'per_page' => $result->perPage(),
                'total' => $result->total(), 'last_page' => $result->lastPage()]
        );
    }
}
