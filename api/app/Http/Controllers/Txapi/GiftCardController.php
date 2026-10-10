<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Models\GiftCardUsage;
use App\Services\GiftCardService;
use App\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class GiftCardController
{
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:255']]);
        try {
            $service = new GiftCardService($data['code']);
            $service->setUser(Auth::guard('sanctum')->user());
            $service->validateIsActive();
            $eligibility = $service->checkUserEligibility();
            return TxapiResponse::success($request, [
                'code_info' => $service->getCodeInfo(),
                'reward_preview' => $service->previewRewards(),
                'can_redeem' => $eligibility['can_redeem'],
                'reason' => $eligibility['reason'],
            ]);
        } catch (ApiException) {
            return TxapiResponse::error($request, 'GIFT_CARD_INVALID', 'Gift card unavailable', 422);
        }
    }

    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:255']]);
        try {
            $service = new GiftCardService($data['code']);
            $service->setUser(Auth::guard('sanctum')->user());
            $service->validate();
            $result = $service->redeem(['user_agent' => $request->userAgent()]);
            return TxapiResponse::success($request, [
                'message' => '兑换成功！',
                'rewards' => $result['rewards'],
                'invite_rewards' => $result['invite_rewards'],
                'template_name' => $result['template_name'],
            ]);
        } catch (ApiException) {
            return TxapiResponse::error($request, 'GIFT_CARD_REDEEM_REJECTED', 'Gift card cannot be redeemed', 409);
        }
    }

    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = GiftCardUsage::query()->with(['template', 'code'])
            ->where('user_id', Auth::guard('sanctum')->id())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 15), ['*'], 'page', (int) ($data['page'] ?? 1));
        $items = $page->getCollection()->map(static fn (GiftCardUsage $usage): array => [
            'id' => $usage->id,
            'code' => $usage->code?->code ? substr($usage->code->code, 0, 8) . '****' : '',
            'template_name' => $usage->template?->name ?? '',
            'template_type' => $usage->template?->type ?? '',
            'template_type_name' => $usage->template?->type_name ?? '',
            'rewards_given' => $usage->rewards_given,
            'invite_rewards' => $usage->invite_rewards,
            'multiplier_applied' => $usage->multiplier_applied,
            'created_at' => $usage->created_at,
        ])->all();
        return TxapiResponse::success($request, [
            'data' => $items,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function detail(Request $request, int $id): JsonResponse
    {
        $usage = GiftCardUsage::query()->with(['template', 'code', 'inviteUser'])
            ->where('user_id', Auth::guard('sanctum')->id())->find($id);
        if (!$usage) {
            return TxapiResponse::error($request, 'NOT_FOUND', 'Gift card record not found', 404);
        }
        return TxapiResponse::success($request, [
            'id' => $usage->id,
            'code' => $usage->code?->code ?? '',
            'template' => [
                'name' => $usage->template?->name ?? '',
                'description' => $usage->template?->description ?? '',
                'type' => $usage->template?->type ?? '',
                'type_name' => $usage->template?->type_name ?? '',
            ],
            'rewards_given' => $usage->rewards_given,
            'invite_rewards' => $usage->invite_rewards,
            'invite_user' => $usage->inviteUser ? [
                'id' => $usage->inviteUser->id,
                'email' => substr((string) $usage->inviteUser->email, 0, 3) . '***@***',
            ] : null,
            'user_level_at_use' => $usage->user_level_at_use,
            'plan_id_at_use' => $usage->plan_id_at_use,
            'multiplier_applied' => $usage->multiplier_applied,
            'notes' => $usage->notes,
            'created_at' => $usage->created_at,
        ]);
    }

    public function types(Request $request): JsonResponse
    {
        return TxapiResponse::success($request, [
            'types' => \App\Models\GiftCardTemplate::getTypeMap(),
        ]);
    }
}
