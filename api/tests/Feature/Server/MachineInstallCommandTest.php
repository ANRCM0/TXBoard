<?php

namespace Tests\Feature\Server;

use App\Models\ServerMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MachineInstallCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $securePath;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::create([
            'email' => 'machine-install-admin@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000000101',
            'token' => str_repeat('a', 32),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 1,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]));

        $this->securePath = (string) admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );

        admin_setting(['app_url' => 'https://panel.example.com']);
    }

    public function test_machine_install_command_uses_public_installer_noninteractive_mode(): void
    {
        $machine = ServerMachine::create([
            'name' => 'Tokyo-01',
            'token' => 'machine-token-1234567890',
            'is_active' => true,
        ]);

        $response = $this->postJson(
            "/txapi/admin/{$this->securePath}/network-machines/{$machine->id}/credentials"
        );

        $response->assertOk();

        $command = (string) $response->json('data.install_command');

        $this->assertStringContainsString(
            "https://raw.githubusercontent.com/ANRCM0/TX-Node-Installer/main/deploy.sh",
            $command
        );
        $this->assertStringContainsString('install --mode machine --provider txboard --channel', $command);
        $this->assertStringContainsString("--channel 'stable'", $command);
        $this->assertStringContainsString("--panel-url 'https://panel.example.com'", $command);
        $this->assertStringContainsString("--machine-id {$machine->id}", $command);
        $this->assertStringContainsString("--token 'machine-token-1234567890'", $command);
        $this->assertStringNotContainsString('TXBoard/main/node/deploy.sh', $command);
    }
    public function test_machine_creation_with_dev_channel_generates_dev_install_command(): void
    {
        $response = $this->postJson(
            "/txapi/admin/{$this->securePath}/network-machines",
            ['name' => 'Tokyo-dev', 'image_channel' => 'dev']
        );
        $response->assertCreated();
        $command = (string) $response->json('data.install_command');
        $this->assertStringContainsString("--channel 'dev'", $command);
        $id = $response->json('data.id');
        $this->assertSame('dev', ServerMachine::findOrFail($id)->image_channel);
        $this->getJson("/txapi/admin/{$this->securePath}/network-machines")
            ->assertOk()->assertJsonPath('data.0.image_channel', 'dev');
        $this->putJson("/txapi/admin/{$this->securePath}/network-machines/{$id}",
            ['name' => 'Tokyo-dev', 'image_channel' => 'stable'])->assertOk();
        $this->postJson("/txapi/admin/{$this->securePath}/network-machines/{$id}/credentials")
            ->assertOk()->assertJsonPath('data.install_command',
                $this->getStableCommand($id));
        $this->postJson("/txapi/admin/{$this->securePath}/network-machines",
            ['name' => 'Invalid', 'image_channel' => 'random'])->assertStatus(422);
    }

    private function getStableCommand(int $id): string
    {
        $machine = ServerMachine::findOrFail($id);
        return sprintf(
            "curl -fsSL 'https://raw.githubusercontent.com/ANRCM0/TX-Node-Installer/main/deploy.sh' | sudo bash -s -- install --mode machine --provider txboard --channel 'stable' --panel-url 'https://panel.example.com' --machine-id %d --token '%s'",
            $id, $machine->token
        );
    }
}
