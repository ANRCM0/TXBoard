<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Http\Requests\Admin\ConfigSave;
use App\Models\SubscribeTemplate;
use App\Services\AdminSettingsProjection;
use App\Services\ThemeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SettingsAdminController
{
    public function index(Request $request, AdminSettingsProjection $projection): JsonResponse
    {
        return TxapiResponse::success($request, $projection->getConfigMappings())
            ->header('Cache-Control', 'no-store');
    }

    public function group(Request $request, AdminSettingsProjection $projection): JsonResponse
    {
        $group = (string) $request->route('group');
        $groups = $projection->getConfigMappings();
        if (!array_key_exists($group, $groups)) {
            return TxapiResponse::error($request, 'SETTINGS_GROUP_NOT_FOUND',
                'Unknown settings group', 404);
        }
        return TxapiResponse::success($request, [$group => $groups[$group]])
            ->header('Cache-Control', 'no-store');
    }

    public function save(ConfigSave $request): JsonResponse
    {
        $data = $request->validated();
        $templateKeys = [
            'subscribe_template_singbox' => 'singbox',
            'subscribe_template_clash' => 'clash',
            'subscribe_template_clashmeta' => 'clashmeta',
            'subscribe_template_stash' => 'stash',
            'subscribe_template_surge' => 'surge',
            'subscribe_template_surfboard' => 'surfboard',
        ];

        // Match the established V2 save semantics so no admin setting is
        // silently dropped or reinterpreted during the API migration.
        foreach ($data as $key => $value) {
            if (isset($templateKeys[$key])) {
                SubscribeTemplate::setContent($templateKeys[$key], $value);
                continue;
            }
            if ($key === 'frontend_theme') {
                app(ThemeService::class)->switch($value);
                continue;
            }
            admin_setting([$key => $value]);
        }

        return TxapiResponse::success($request, ['ok' => true]);
    }
}
