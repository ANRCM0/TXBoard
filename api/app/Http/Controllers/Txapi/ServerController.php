<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Models\User;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class ServerController
{
    public function index(Request $request, UserService $users): JsonResponse
    {
        $user = User::query()->findOrFail(Auth::guard('sanctum')->id());
        $servers = $users->isAvailable($user)
            ? ServerService::getAvailableServers($user) : [];
        // Never serialize ServerService's generated passwords, raw node keys,
        // hostnames, private ports, certificates or provider configuration.
        return TxapiResponse::success($request,
            array_values(array_map([self::class, 'toPublicNode'], $servers)));
    }

    public static function toPublicNode(array $item): array
    {
        return [
            'id' => (int) $item['id'],
            'type' => (string) $item['type'],
            'version' => isset($item['version']) ? (string) $item['version'] : null,
            'name' => (string) $item['name'],
            'rate' => $item['rate'],
            'tags' => $item['tags'] ?? [],
            'is_online' => (bool) ($item['is_online'] ?? false),
            'last_check_at' => isset($item['last_check_at']) ? (int) $item['last_check_at'] : null,
        ];
    }
}
