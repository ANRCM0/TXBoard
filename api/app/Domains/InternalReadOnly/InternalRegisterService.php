<?php

namespace App\Domains\InternalReadOnly;

use App\Services\Auth\RegisterService;
use App\Services\CaptchaService;

/** Delegates all native registration rules without changing global service bindings. */
final class InternalRegisterService extends RegisterService
{
    protected function captcha(): CaptchaService
    {
        return app(InternalRegisterCaptcha::class);
    }
}
