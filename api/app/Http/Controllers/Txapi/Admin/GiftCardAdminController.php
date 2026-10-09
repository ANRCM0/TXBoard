<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\GiftCardUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class GiftCardAdminController
{
    public function types(Request $request): JsonResponse
    {
        return TxapiResponse::success($request, GiftCardTemplate::getTypeMap());
    }

    public function templates(Request $request): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'type' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'status' => ['sometimes', 'integer', 'in:0,1'],
        ]);
        $query = GiftCardTemplate::query()->withCount(['codes', 'usages']);
        if (isset($input['type'])) $query->where('type', $input['type']);
        if (isset($input['status'])) $query->where('status', $input['status']);
        $page = $query->orderBy('sort')->orderByDesc('created_at')
            ->paginate((int) ($input['per_page'] ?? 15));
        $rows = $page->getCollection()->map(static fn ($item) => [
            'id' => $item->id, 'name' => $item->name, 'description' => $item->description,
            'type' => $item->type, 'type_name' => $item->type_name,
            'status' => $item->status, 'conditions' => $item->conditions,
            'rewards' => $item->rewards, 'limits' => $item->limits,
            'special_config' => $item->special_config, 'icon' => $item->icon,
            'background_image' => $item->background_image, 'theme_color' => $item->theme_color,
            'sort' => $item->sort, 'codes_count' => $item->codes_count,
            'used_count' => $item->usages_count, 'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
        ])->all();
        return TxapiResponse::success($request, $rows, [
            'page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]);
    }

    public function codes(Request $request): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'template_id' => ['sometimes', 'integer', 'min:1'],
            'batch_id' => ['sometimes', 'string', 'max:128'],
            'status' => ['sometimes', 'integer', 'in:0,1,2,3'],
        ]);
        $query = GiftCardCode::query()->with(['template', 'user']);
        foreach (['template_id', 'batch_id', 'status'] as $key) {
            if (isset($input[$key])) $query->where($key, $input[$key]);
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($input['per_page'] ?? 15));
        $rows = $page->getCollection()->map(static fn ($code) => [
            'id' => $code->id, 'template_id' => $code->template_id,
            'template_name' => $code->template?->name ?? '', 'code' => $code->code,
            'batch_id' => $code->batch_id, 'status' => $code->status,
            'status_name' => $code->status_name, 'user_id' => $code->user_id,
            'user_email' => $code->user ? substr($code->user->email ?? '', 0, 3) . '***@***' : null,
            'used_at' => $code->used_at, 'expires_at' => $code->expires_at,
            'usage_count' => $code->usage_count, 'max_usage' => $code->max_usage,
            'created_at' => $code->created_at,
        ])->all();
        return TxapiResponse::success($request, $rows, [
            'page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'last_page' => $page->lastPage(),
        ])->header('Cache-Control', 'no-store');
    }

    public function usages(Request $request): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'template_id' => ['sometimes', 'integer', 'min:1'],
            'user_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = GiftCardUsage::query()->with(['template', 'code', 'user', 'inviteUser']);
        foreach (['template_id', 'user_id'] as $key) {
            if (isset($input[$key])) $query->where($key, $input[$key]);
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($input['per_page'] ?? 15));
        $rows = $page->getCollection()->map(static fn ($usage) => [
            'id' => $usage->id, 'code' => $usage->code?->code ?? '',
            'template_name' => $usage->template?->name ?? '',
            'user_email' => $usage->user?->email ?? '',
            'invite_user_email' => $usage->inviteUser
                ? substr($usage->inviteUser->email ?? '', 0, 3) . '***@***' : null,
            'rewards_given' => $usage->rewards_given,
            'invite_rewards' => $usage->invite_rewards,
            'multiplier_applied' => $usage->multiplier_applied,
            'created_at' => $usage->created_at,
        ])->all();
        return TxapiResponse::success($request, $rows, [
            'page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'last_page' => $page->lastPage(),
        ])->header('Cache-Control', 'no-store');
    }

    public function deleteCode(Request $request): JsonResponse
    {
        $id = (int) $request->route('id');
        $deleted = DB::transaction(static function () use ($id): bool {
            $code = GiftCardCode::query()->lockForUpdate()->findOrFail($id);
            if ($code->usage_count > 0 || $code->status === GiftCardCode::STATUS_USED
                || $code->usages()->exists()) return false;
            return (bool) $code->delete();
        });
        if (!$deleted) return TxapiResponse::error($request, 'GIFT_CARD_IN_USE',
            'Gift card has redemption history; disable it instead', 409);
        return TxapiResponse::success($request, ['ok' => true]);
    }
    public function createTemplate(Request $request): JsonResponse
    {
        $data = $this->templateInput($request);
        $data['admin_id'] = $request->user()->id;
        $data['status'] = $data['status'] ?? true;
        $data['sort'] = $data['sort'] ?? 0;
        $template = GiftCardTemplate::query()->create($data);
        return TxapiResponse::success($request, ['id' => $template->id], status: 201);
    }

    public function updateTemplate(Request $request): JsonResponse
    {
        $data = $this->templateInput($request, true);
        $template = GiftCardTemplate::query()->findOrFail((int) $request->route('id'));
        $template->fill($data)->saveOrFail();
        return TxapiResponse::success($request, ['id' => $template->id]);
    }

    public function deleteTemplate(Request $request): JsonResponse
    {
        $deleted = DB::transaction(static function () use ($request): bool {
            $template = GiftCardTemplate::query()->lockForUpdate()->findOrFail((int) $request->route('id'));
            if ($template->codes()->exists() || $template->usages()->exists()) return false;
            return (bool) $template->delete();
        });
        if (!$deleted) return TxapiResponse::error($request, 'GIFT_TEMPLATE_IN_USE',
            'Template has issuance or redemption history; disable it instead', 409);
        return TxapiResponse::success($request, ['ok' => true]);
    }

    private function templateInput(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'type' => [$required, 'integer', \Illuminate\Validation\Rule::in(array_keys(GiftCardTemplate::getTypeMap()))],
            'status' => ['sometimes', 'boolean'],
            'conditions' => ['sometimes', 'nullable', 'array'],
            'rewards' => [$required, 'array'],
            'limits' => ['sometimes', 'nullable', 'array'],
            'special_config' => ['sometimes', 'nullable', 'array'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:255'],
            'background_image' => ['sometimes', 'nullable', 'url', 'max:255'],
            'theme_color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sort' => ['sometimes', 'integer', 'min:0'],
        ]);
    }

}
