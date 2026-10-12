<?php
require getenv('USAGE_CORE_API').'/vendor/autoload.php';
$app = require getenv('USAGE_CORE_API').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$fixture = new class {
    use Tests\Support\UsageV2Fixture;
    public function run(): array { $this->usageConfig(); return $this->usageFixture(); }
};
$f = $fixture->run();
admin_setting(['server_token' => 'usage-interop-local-test-only']);
echo json_encode(['node_id' => (string) $f['node']->id, 'started_at' => $f['now'] - 60, 'ended_at' => $f['now'] - 30], JSON_THROW_ON_ERROR);