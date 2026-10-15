<?php

namespace App\Domains\Installation;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class InstallationAuthorityException extends RuntimeException implements ShouldntReport
{
}
