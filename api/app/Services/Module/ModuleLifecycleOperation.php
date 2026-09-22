<?php

namespace App\Services\Module;

enum ModuleLifecycleOperation: string
{
    case INSTALL = 'install';
    case ENABLE = 'enable';
    case DISABLE = 'disable';
    case UPGRADE = 'upgrade';
    case UNINSTALL = 'uninstall';
}
