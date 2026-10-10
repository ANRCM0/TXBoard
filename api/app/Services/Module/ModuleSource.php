<?php

namespace App\Services\Module;

enum ModuleSource: string
{
    case SYSTEM = 'system';
    case BUNDLED = 'bundled';
    case USER = 'user';
    case EXTERNAL = 'external';
}
