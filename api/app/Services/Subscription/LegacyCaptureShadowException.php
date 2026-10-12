<?php

namespace App\Services\Subscription;

use Illuminate\Contracts\Debug\ShouldntReport;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Fixed non-secret failure, deliberately without the original SQL exception. */
final class LegacyCaptureShadowException extends HttpException implements ShouldntReport
{
    public function __construct(string $code)
    {
        parent::__construct(409, $code);
    }
}
