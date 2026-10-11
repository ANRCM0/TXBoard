<?php

namespace App\Console\Commands;

use App\Models\Setting as SettingModel;
use App\Services\InstallState;
use Illuminate\Console\Command;
use Throwable;

/**
 * Recover an inaccessible administrator entry without touching credentials.
 *
 * The web SPA and /txapi/admin/{admin_path} middleware both resolve the
 * live secure_path setting. Use admin_setting() so its Redis cache key is
 * invalidated instead of editing the database behind the settings service.
 */
final class TxboardAdminPathReset extends Command
{
    protected $signature = 'txboard:admin-path-reset';

    protected $description = 'Rotate the administrator security path for a deployed TXBoard installation';

    public function handle(InstallState $installState): int
    {
        if (!$installState->isInstalled()) {
            $this->error('TXBoard 尚未完成管理员初始化，不能重置后台路径。');
            return self::FAILURE;
        }

        try {
            $old = (string) admin_setting(
                'secure_path',
                admin_setting('frontend_admin_path', hash('crc32b', (string) config('app.key')))
            );

            // Sixteen CSPRNG bytes (128 bits), 32 URL-safe hex characters.
            // No username, credentials, password or secret is taken on argv.
            do {
                $new = bin2hex(random_bytes(16));
            } while ($new === $old);

            admin_setting(['secure_path' => $new]);

            // Verify the authoritative persistent state, not a stale cached
            // copy. An error must not be reported as a successful rotation.
            $persisted = (string) SettingModel::query()
                ->where('name', 'secure_path')->value('value');
            if (!hash_equals($new, $persisted)) {
                $this->error('后台路径持久化校验失败；请检查数据库和设置缓存。');
                return self::FAILURE;
            }
        } catch (Throwable) {
            $this->error('后台安全路径重置失败，请检查数据库连接和设置缓存。');
            return self::FAILURE;
        }

        // This is a privileged local Artisan invocation. The deploy manager
        // redirects this output to /dev/tty instead of CI/service logs.
        $this->info('管理面板安全路径已更新。');
        $this->line('新管理入口路径：/' . $new . '/');
        $this->warn('旧入口已失效。请保存新路径，并重新打开管理后台。');
        return self::SUCCESS;
    }
}
