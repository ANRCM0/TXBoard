<?php

namespace App\Services\Module;

enum ModuleHealth: string
{
    case HEALTHY = 'healthy';
    case DEGRADED = 'degraded';
    case DISABLED = 'disabled';
    case FAILED = 'failed';
    case INCOMPATIBLE = 'incompatible';
    case MISSING_DEPENDENCY = 'missing_dependency';
}
