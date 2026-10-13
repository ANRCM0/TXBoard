<?php

namespace App\Services\Subscription;

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/** Only the already-validated native Admin ban transaction may call this adapter. */
final class LegacyBulkBanCapture
{
    public function apply(Collection $users, int $adminId): void
    {
        $enabled = config('legacy_bulk_ban_capture.enabled') !== false;
        try {
            $capture = $enabled ? app(LegacyUserMutationCapture::class) : null;
            $tickets = [];
            foreach ($users as $user) {
                if ($enabled) $tickets[] = $capture->beginBulkBan((int) $user->id, $adminId);
            }
            User::query()->whereIn('id', $users->modelKeys())->update(['banned' => 1]);
            foreach ($users as $user) (new AuthService($user))->removeAllSessions();
            foreach ($tickets as $ticket) $capture->appendBulkBan($ticket);
        } catch (LegacyCaptureShadowException $error) {
            throw $error;
        } catch (Throwable $error) {
            if (!$enabled) throw $error;
            throw new LegacyCaptureShadowException('CAPTURE_BULK_BAN_WRITE_FAILED');
        }
    }
}
