<?php

namespace Tests\Unit\Plugin;

use App\Models\Plugin;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CorePluginInventoryTest extends TestCase
{
    public function test_only_supported_plugins_are_bundled_in_core(): void
    {
        $directories = array_map('basename', File::directories(base_path('plugins-core')));
        sort($directories);

        $this->assertSame(['AlipayF2f', 'Epay', 'Telegram'], $directories);
        $this->assertSame(['epay', 'alipay_f2f', 'telegram'], Plugin::PROTECTED_PLUGINS);
    }
}
