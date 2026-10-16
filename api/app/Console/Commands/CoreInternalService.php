<?php

namespace App\Console\Commands;

use App\Domains\InternalApi\OperationRegistry;
use App\Domains\InternalApi\ServiceCredentials;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Host administration only; never exposed as an anonymous HTTP operation. */
final class CoreInternalService extends Command
{
    protected $signature = 'core:internal-service {action : issue|rotate|revoke|list|grant|revoke-grant}
        {--id=} {--domain=} {--certificate-sha256=} {--operation=*} {--expires-at=}
        {--user-id=} {--permission=}';
    protected $description = 'Provision isolated Internal API service credentials and current administrator grants';

    public function handle(ServiceCredentials $credentials): int
    {
        try {
            $action = (string) $this->argument('action');
            $id = (string) $this->option('id');
            $result = match ($action) {
                'issue' => $credentials->issue($id, (string) $this->option('domain'),
                    (string) $this->option('certificate-sha256'), $this->option('operation'),
                    (int) $this->option('expires-at')),
                'rotate' => $credentials->rotate($id, (string) $this->option('certificate-sha256'),
                    (int) $this->option('expires-at')),
                'revoke' => ['revoked' => $credentials->revoke($id)],
                'list' => ['services' => DB::table('tx_internal_service')->orderBy('service_id')
                    ->limit(100)->get(['service_id', 'security_domain', 'epoch', 'expires_at', 'revoked_at',
                        'operations_json'])->all()],
                'grant', 'revoke-grant' => $this->grant($action === 'grant'),
                default => throw new \InvalidArgumentException('ACTION_INVALID'),
            };
            // issue/rotate deliberately return the secret once, to the host
            // operator's protected stdout. No secret is an argument or log.
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('INTERNAL_SERVICE_ADMINISTRATION_REJECTED');
            return self::FAILURE;
        }
    }

    private function grant(bool $enabled): array
    {
        $id = (string) $this->option('user-id');
        $permission = (string) $this->option('permission');
        $permissions = ['identity.sessions.manage'];
        foreach (OperationRegistry::all() as $spec) {
            if ($spec['permission'] !== null) $permissions[] = $spec['permission'];
        }
        if (!preg_match('/\A[1-9][0-9]{0,17}\z/D', $id) || !in_array($permission, $permissions, true)) {
            throw new \InvalidArgumentException('GRANT_INVALID');
        }
        return DB::transaction(function () use ($id, $permission, $enabled): array {
            $user = User::query()->whereKey($id)->lockForUpdate()->first();
            if ($enabled && (!$user || !$user->is_admin || $user->banned)) throw new \InvalidArgumentException('ADMIN_REQUIRED');
            $query = DB::table('tx_internal_actor_grant')->where('user_id', $id)->where('permission', $permission);
            if ($enabled) DB::table('tx_internal_actor_grant')->insertOrIgnore([
                'user_id' => $id, 'permission' => $permission, 'created_at' => time(),
            ]);
            else $query->delete();
            return ['user_id' => $id, 'permission' => $permission, 'granted' => $enabled];
        });
    }
}
