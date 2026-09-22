<?php

namespace App\Services\Module;

enum ModuleLifecycleErrorCode: string
{
    case MODULE_NOT_FOUND = 'module_not_found';
    case UNSUPPORTED_MODULE_TYPE = 'unsupported_module_type';
    case RUNTIME_ERROR = 'runtime_error';
    case STATE_REFRESH_FAILED = 'state_refresh_failed';
}
