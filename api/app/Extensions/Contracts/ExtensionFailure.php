<?php

namespace App\Extensions\Contracts;

final class ExtensionFailure extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status = 409)
    {
        if (preg_match('/\AMODULE_[A-Z0-9_]{1,64}\z/D', $errorCode) !== 1 || !in_array($status, [400, 403, 404, 409, 422, 503], true)) {
            throw new \InvalidArgumentException('MODULE_ERROR_INVALID');
        }
        parent::__construct($errorCode);
    }
}
