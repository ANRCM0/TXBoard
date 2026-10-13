<?php

namespace App\Domains\AuthorityMigration;

use Illuminate\Contracts\Debug\ShouldntReport;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AuthorityFenceException extends HttpException implements ShouldntReport
{
    public function __construct(string $code) { parent::__construct(409, $code); }
}
