<?php

namespace App\Services\Module;

enum ModuleType: string
{
    case CORE = 'core';
    case PLUGIN = 'plugin';
    case THEME = 'theme';
    case INTEGRATION = 'integration';
    case PROVIDER = 'provider';
    case AGENT = 'agent';
}
