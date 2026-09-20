<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelSubscribePathTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prints_the_configured_subscribe_path(): void
    {
        admin_setting(['subscribe_path' => 'sub-link']);

        $this->artisan('panel:subscribe-path')
            ->expectsOutput('sub-link')
            ->assertSuccessful();
    }

    public function test_it_exports_a_shell_assignment_for_the_gateway(): void
    {
        admin_setting(['subscribe_path' => 'sub-link']);

        $this->artisan('panel:subscribe-path', ['--export' => true])
            ->expectsOutput("TXBOARD_SUBSCRIBE_PATH='sub-link'")
            ->assertSuccessful();
    }

    public function test_it_rejects_a_multi_segment_path(): void
    {
        admin_setting(['subscribe_path' => 'a/b']);

        $this->artisan('panel:subscribe-path')->assertFailed();
    }
}
