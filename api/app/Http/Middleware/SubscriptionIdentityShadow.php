<?php

namespace App\Http\Middleware;

use App\Services\Subscription\LegacySubscriptionIdentityReader;
use Closure;
use Illuminate\Http\Request;
use Throwable;

/** Optional request-local observation after native Client authentication. */
final class SubscriptionIdentityShadow
{
    public function handle(Request $request, Closure $next)
    {
        if (config('core_shadow.subscription_identity_read')) {
            $reader = app(LegacySubscriptionIdentityReader::class);
            $id = $request->user()?->getAuthIdentifier();
            $userId = is_numeric($id) && (int) $id > 0 ? (int) $id : null;
            try {
                $observation = $userId === null ? $reader->blocked(null, 'IDENTITY_USER_MISSING')
                    : $reader->forLegacyUser($userId);
            } catch (Throwable) {
                $observation = $reader->blocked($userId, 'IDENTITY_READ_FAILED');
            }
            $request->attributes->set(LegacySubscriptionIdentityReader::ATTRIBUTE, $observation);
        }

        return $next($request);
    }
}
