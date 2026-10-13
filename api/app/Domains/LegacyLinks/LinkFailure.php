<?php
namespace App\Domains\LegacyLinks;
use Illuminate\Contracts\Debug\ShouldntReport;
use Symfony\Component\HttpKernel\Exception\HttpException;
final class LinkFailure extends HttpException implements ShouldntReport
{
    public function __construct(string $code) { parent::__construct(409, $code); }
}
