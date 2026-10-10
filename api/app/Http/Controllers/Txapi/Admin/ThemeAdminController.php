<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Services\ThemeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ThemeAdminController
{
    public function index(Request $request, ThemeService $themes): JsonResponse
    {
        return TxapiResponse::success($request, [
            'themes' => $themes->getList(),
            'active' => $themes->getActiveTheme(),
        ])->header('Cache-Control', 'no-store');
    }

    public function config(Request $request, ThemeService $themes): JsonResponse
    {
        $name = $this->name($request);
        if (!$themes->exists($name)) return $this->missing($request);
        return TxapiResponse::success($request, $themes->getConfig($name) ?? [])
            ->header('Cache-Control', 'no-store');
    }

    public function saveConfig(Request $request, ThemeService $themes): JsonResponse
    {
        $name = $this->name($request);
        if (!$themes->exists($name)) return $this->missing($request);
        $data = $request->validate(['config' => ['required', 'array', 'max:200']]);
        try {
            $themes->updateConfig($name, $data['config']);
            return TxapiResponse::success($request, $themes->getConfig($name) ?? []);
        } catch (\Throwable $e) {
            return TxapiResponse::error($request, 'THEME_CONFIG_FAILED',
                'Unable to save theme configuration', 422);
        }
    }

    public function upload(Request $request, ThemeService $themes): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:zip', 'max:10240'],
        ]);
        try {
            // ThemeService validates ZIP entry traversal, package metadata,
            // system theme protections and rollback-safe upgrade promotion.
            $themes->upload($data['file']);
        } catch (\Throwable $e) {
            return TxapiResponse::error($request, 'THEME_PACKAGE_REJECTED',
                'Theme package rejected or installation failed', 422);
        }
        return TxapiResponse::success($request, ['ok' => true])
            ->header('Cache-Control', 'no-store');
    }

    public function delete(Request $request, ThemeService $themes): JsonResponse
    {
        $name = $this->name($request);
        if (!$themes->exists($name)) return $this->missing($request);
        if ($name === $themes->getActiveTheme()) {
            return TxapiResponse::error($request, 'THEME_ACTIVE',
                'Active theme cannot be deleted', 409);
        }
        try {
            $themes->delete($name);
        } catch (\Throwable $e) {
            return TxapiResponse::error($request, 'THEME_PROTECTED',
                'Theme is protected or deletion failed', 409);
        }
        return TxapiResponse::success($request, ['ok' => true]);
    }

    private function name(Request $request): string
    {
        $name = (string) $request->route('name');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D', $name)) {
            throw ValidationException::withMessages([
                'name' => 'Invalid theme identifier',
            ]);
        }
        return $name;
    }

    private function missing(Request $request): JsonResponse
    {
        return TxapiResponse::error($request, 'THEME_NOT_FOUND',
            'Theme not found', 404);
    }
}
