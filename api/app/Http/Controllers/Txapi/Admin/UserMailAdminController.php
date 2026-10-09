<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Jobs\SendEmailJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class UserMailAdminController
{
    private const MAX_RECIPIENTS = 500;

    /**
     * Bound both recipient discovery and queue fan-out. The legacy endpoint
     * removed PHP's memory limit and streamed arbitrarily large campaigns
     * synchronously through a single administrator HTTP request.
     */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'in:selected,filtered,all'],
            'user_ids' => ['required_if:scope,selected', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['integer', 'distinct', 'min:1'],
            'filter' => ['required_if:scope,filtered', 'array', 'min:1', 'max:4'],
            'filter.*.id' => ['required_with:filter', 'in:email,plan_id,banned'],
            'filter.*.value' => ['required_with:filter'],
            'subject' => ['required', 'string', 'min:1', 'max:200'],
            'content' => ['required', 'string', 'min:1', 'max:10000'],
        ]);
        $query = User::query();

        if ($data['scope'] === 'selected') {
            $query->whereIn('id', $data['user_ids']);
        } elseif ($data['scope'] === 'filtered') {
            foreach ($data['filter'] as $filter) {
                if ($filter['id'] === 'email' && is_string($filter['value']) &&
                    mb_strlen($filter['value']) <= 254) {
                    $query->where('email', 'like', '%' . $filter['value'] . '%');
                } elseif (in_array($filter['id'], ['plan_id', 'banned'], true) &&
                    is_string($filter['value']) && preg_match('/^eq:\d+$/', $filter['value'])) {
                    $query->where($filter['id'], (int) substr($filter['value'], 3));
                } else {
                    throw ValidationException::withMessages(['filter' => 'Unsupported recipient filter']);
                }
            }
        }

        $ids = $query->orderBy('id')->limit(self::MAX_RECIPIENTS + 1)
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        if (count($ids) > self::MAX_RECIPIENTS) {
            return TxapiResponse::error($request, 'MAIL_BATCH_TOO_LARGE',
                'Narrow the recipients to at most 500 users per request', 422);
        }
        if ($data['scope'] === 'selected' && count($ids) !== count($data['user_ids'])) {
            return TxapiResponse::error($request, 'MAIL_RECIPIENT_MISSING',
                'Some selected recipients do not exist', 422);
        }
        if (!$ids) return TxapiResponse::success($request, ['queued' => 0]);

        $appName = (string) admin_setting('app_name', 'TXBoard');
        $appUrl = (string) admin_setting('app_url', '');
        $queued = 0;
        User::query()->whereIn('id', $ids)->with('plan:id,name')
            ->orderBy('id')->chunkById(100, function ($users) use ($data, $appName, $appUrl, &$queued) {
                foreach ($users as $user) {
                    $vars = [
                        'app.name' => $appName,
                        'app.url' => $appUrl,
                        'now' => now()->format('Y-m-d H:i:s'),
                        'user.id' => $user->id,
                        'user.email' => $user->email,
                        'user.uuid' => $user->uuid,
                        'user.plan_name' => $user->plan?->name ?? '',
                        'user.expired_at' => $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '',
                        'user.transfer_enable' => (int) ($user->transfer_enable ?? 0),
                        'user.transfer_used' => (int) (($user->u ?? 0) + ($user->d ?? 0)),
                        'user.transfer_left' => (int) (($user->transfer_enable ?? 0) -
                            (($user->u ?? 0) + ($user->d ?? 0))),
                    ];
                    dispatch(new SendEmailJob([
                        'email' => $user->email,
                        'subject' => $data['subject'],
                        'template_name' => 'notify',
                        'template_value' => [
                            'name' => $appName, 'url' => $appUrl,
                            'content' => $data['content'],
                            'vars' => $vars, 'content_mode' => 'text',
                        ],
                    ], 'send_email_mass'));
                    $queued++;
                }
            });
        return TxapiResponse::success($request, ['queued' => $queued], [], 202)
            ->header('Cache-Control', 'no-store');
    }
}
