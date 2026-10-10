<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Exceptions\ApiException;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\Auth\MailLinkService;
use App\Services\Auth\EmailVerificationService;
use App\Services\Auth\RegisterService;
use App\Services\AuthService;
use App\Services\CaptchaService;
use App\Utils\Helper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

final class AuthController
{
    public function login(AuthLogin $request, CaptchaService $captcha, LoginService $service): JsonResponse
    {
        [$valid] = $captcha->verify($request);
        if (!$valid) {
            return TxapiResponse::error($request, 'CAPTCHA_INVALID', 'Captcha verification failed', 400);
        }
        [$success, $result] = $service->login($request->input('email'), $request->input('password'));
        if (!$success) {
            $limited = (int) ($result[0] ?? 400) === 429;
            return TxapiResponse::error($request,
                $limited ? 'RATE_LIMITED' : 'INVALID_CREDENTIALS',
                $limited ? 'Too many attempts' : 'Invalid credentials',
                $limited ? 429 : 401);
        }

        // AuthService issues Sanctum sessions. Its internal response contains the PRIVATE subscription
        // token and admin secure path; neither must enter the native envelope.
        $data = (new AuthService($result))->generateAuthData();
        return TxapiResponse::success($request, ['auth_data' => $data['auth_data']]);
    }

    /**
     * First-party management login: issue a bearer only to an active admin.
     * Unlike the generic account login, the native response includes the
     * rotating admin path exclusively after the administrator role check.
     */
    public function adminLogin(AuthLogin $request, CaptchaService $captcha, LoginService $service): JsonResponse
    {
        [$valid] = $captcha->verify($request);
        if (!$valid) {
            return TxapiResponse::error($request, 'CAPTCHA_INVALID',
                'Captcha verification failed', 400)->header('Cache-Control', 'no-store');
        }
        [$success, $result] = $service->login(
            (string) $request->input('email'),
            (string) $request->input('password')
        );
        if (!$success) {
            $limited = (int) ($result[0] ?? 400) === 429;
            return TxapiResponse::error($request,
                $limited ? 'RATE_LIMITED' : 'INVALID_CREDENTIALS',
                $limited ? 'Too many attempts' : 'Invalid credentials',
                $limited ? 429 : 401)->header('Cache-Control', 'no-store');
        }

        // Check BEFORE creating an administrator bearer. Do not leak either
        // the admin path or subscription token to regular users.
        if (!$result instanceof User || !$result->is_admin || $result->banned) {
            return TxapiResponse::error($request, 'ADMIN_ACCESS_DENIED',
                'Administrator access required', 403)->header('Cache-Control', 'no-store');
        }
        $auth = (new AuthService($result))->generateAuthData();
        return TxapiResponse::success($request, [
            'auth_data' => $auth['auth_data'],
            'is_admin' => true,
            'secure_path' => (string) $auth['secure_path'],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function register(AuthRegister $request, RegisterService $service): JsonResponse
    {
        try {
            [$success, $result] = $service->register($request);
        } catch (ApiException) {
            return TxapiResponse::error($request, 'REGISTRATION_REJECTED', 'Registration rejected', 422);
        }
        if (!$success) {
            $status = match ((int) ($result[0] ?? 422)) {
                429 => 429,
                400201 => 409,
                default => 422,
            };
            return TxapiResponse::error($request,
                match ($status) { 429 => 'RATE_LIMITED', 409 => 'CONFLICT',
                    default => 'REGISTRATION_REJECTED' },
                match ($status) { 429 => 'Too many requests', 409 => 'Registration conflict',
                    default => 'Registration rejected' }, $status);
        }
        $data = (new AuthService($result))->generateAuthData();
        return TxapiResponse::success($request, ['auth_data' => $data['auth_data']], status: 201);
    }

    public function mailLink(Request $request, CaptchaService $captcha, MailLinkService $service): JsonResponse
    {
        $input = $request->validate(['email' => ['required', 'email:strict', 'max:255']]);
        [$valid] = $captcha->verify($request);
        if (!$valid) {
            return TxapiResponse::error($request, 'CAPTCHA_INVALID', 'Captcha verification failed', 400);
        }
        // This shared service enforces the admin feature flag, per-email cooldown
        // and privacy-safe success on unknown addresses.
        [$ok, $result] = $service->handleMailLink($input['email']);
        if (!$ok) {
            $code = (int) ($result[0] ?? 400);
            if ($code === 404) {
                return TxapiResponse::error($request, 'FEATURE_DISABLED',
                    'Mail-link login disabled', 404);
            }
            if ($code === 429) {
                return TxapiResponse::error($request, 'RATE_LIMITED',
                    'Please try again later', 429);
            }
            return TxapiResponse::error($request, 'REQUEST_REJECTED',
                'Unable to request login link', 422);
        }
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function oneTimeToken(Request $request, MailLinkService $service): JsonResponse
    {
        $input = $request->validate(['verify' => ['required', 'string', 'max:200']]);
        // The disposable TEMP_TOKEN cache is atomically consumed by this native endpoint.
        $id = $service->handleTokenLogin($input['verify']);
        if (!$id) {
            return TxapiResponse::error($request, 'TOKEN_INVALID',
                'Invalid or expired login link', 401);
        }
        $user = User::query()->find($id);
        if ($user === null || $user->banned) {
            return TxapiResponse::error($request, 'TOKEN_INVALID',
                'Invalid or expired login link', 401);
        }
        $auth = (new AuthService($user))->generateAuthData();
        return TxapiResponse::success($request, ['auth_data' => $auth['auth_data']]);
    }

    public function sendEmailCode(CommSendEmailVerify $request, CaptchaService $captcha,
        EmailVerificationService $issuer): JsonResponse
    {
        [$valid] = $captcha->verify($request);
        if (!$valid) {
            return TxapiResponse::error($request, 'CAPTCHA_INVALID', 'Captcha verification failed', 400);
        }
        [$ok] = $issuer->send((string) $request->input('email'));
        if (!$ok) {
            return TxapiResponse::error($request, 'EMAIL_CODE_REJECTED',
                'Unable to send verification code', 400);
        }
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function forgotPassword(AuthForget $request, CaptchaService $captcha,
        LoginService $service): JsonResponse
    {
        [$valid] = $captcha->verify($request);
        if (!$valid) {
            return TxapiResponse::error($request, 'CAPTCHA_INVALID', 'Captcha verification failed', 400);
        }
        [$ok, $result] = $service->resetPassword(
            (string) $request->input('email'),
            (string) $request->input('email_code'),
            (string) $request->input('password')
        );
        if (!$ok) {
            if ((int) ($result[0] ?? 400) === 429) {
                return TxapiResponse::error($request, 'RATE_LIMITED',
                    'Please try again later', 429);
            }
            return TxapiResponse::error($request, 'RESET_REJECTED',
                'Unable to reset password', 400);
        }
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function logout(Request $request): JsonResponse
    {
        $current = Auth::guard('sanctum')->user()->currentAccessToken();
        if ($current && method_exists($current, 'delete')) {
            $current->delete();
        }
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $items = Auth::guard('sanctum')->user()->tokens()
            ->orderByDesc('id')->get(['id', 'name', 'abilities', 'last_used_at',
                'created_at', 'updated_at', 'expires_at'])
            ->map(static fn ($token): array => [
                'id' => (int) $token->id,
                'name' => (string) $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
                'updated_at' => $token->updated_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ])->all();
        return TxapiResponse::success($request, $items);
    }

    public function revoke(Request $request, int $sessionId): JsonResponse
    {
        $deleted = Auth::guard('sanctum')->user()->tokens()
            ->whereKey($sessionId)->delete();
        if (!$deleted) {
            abort(404);
        }
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function quickLogin(Request $request, LoginService $service): JsonResponse
    {
        $input = $request->validate([
            'redirect' => ['sometimes', 'string', 'max:200'],
        ]);
        $redirect = $input['redirect'] ?? 'dashboard';
        // Only internal Vue route paths. Avoid injecting scheme, host, fragment,
        // query or parent-directory segments into a bearer-bearing login link.
        if (!preg_match('~\\A/?[A-Za-z0-9][A-Za-z0-9/_-]*\\z~D', $redirect)
            || str_contains($redirect, '//') || str_contains($redirect, '..')) {
            return TxapiResponse::error($request, 'INVALID_REDIRECT',
                'Redirect must be a local application route', 422);
        }
        $url = $service->generateQuickLoginUrl(Auth::guard('sanctum')->user(), $redirect);
        if (!is_string($url) || $url === '') {
            return TxapiResponse::error($request, 'LINK_UNAVAILABLE',
                'Quick login unavailable', 409);
        }
        // The URL holds a one-time credential. Do not log it or embed it into
        // URL query parameters of this API request.
        return TxapiResponse::success($request, ['url' => $url]);
    }

    public function password(Request $request): JsonResponse
    {
        $params = $request->validate([
            'old_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'max:255'],
        ]);
        $user = Auth::guard('sanctum')->user();
        if (!Helper::multiPasswordVerify($user->password_algo, $user->password_salt,
            $params['old_password'], $user->password)) {
            return TxapiResponse::error($request, 'INVALID_CREDENTIALS',
                'Invalid current password', 400);
        }
        $user->password = password_hash($params['new_password'], PASSWORD_DEFAULT);
        $user->password_algo = null;
        $user->password_salt = null;
        $user->saveOrFail();

        $current = $user->currentAccessToken();
        if ($current && method_exists($current, 'getKey')) {
            $user->tokens()->where('id', '!=', $current->getKey())->delete();
        } else {
            $user->tokens()->delete();
        }
        return TxapiResponse::success($request, ['ok' => true]);
    }
}
