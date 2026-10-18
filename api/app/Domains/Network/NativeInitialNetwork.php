<?php

namespace App\Domains\Network;

use App\Domains\Network\Internal\InitialNetwork;
use App\Domains\Installation\NativePreparationInput;
use App\Domains\Installation\NativePreparationSteps;
use App\Domains\Installation\NativeRuntimeReadiness;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NativeInitialNetwork implements InitialNetwork
{
    public function prepare(string $key, #[\SensitiveParameter] array $input): array
    {
        NativePreparationInput::validate($input, 'network');
        return app(NativePreparationSteps::class)->execute('network', $key, $input, function () use ($input): array {
            if (DB::table('tx_server_group')->lockForUpdate()->exists() || Server::query()->lockForUpdate()->exists()) {
                throw new RuntimeException('NATIVE_PREPARATION_NETWORK_NOT_EMPTY');
            }
            $groups = [];
            foreach ($input['groups'] as $group) {
                if (isset($groups[$group['id']])) throw new RuntimeException('NATIVE_PREPARATION_INPUT_INVALID');
                DB::table('tx_server_group')->insert($group + ['created_at' => time(), 'updated_at' => time()]);
                $groups[$group['id']] = true;
            }
            $nodes = [];
            foreach ($input['nodes'] as $node) {
                if (isset($nodes[$node['id']]) || array_diff($node['group_ids'], array_keys($groups))) {
                    throw new RuntimeException('NATIVE_PREPARATION_INPUT_INVALID');
                }
                $server = new Server;
                $server->forceFill($node + ['server_port' => $node['port'], 'enabled' => true, 'show' => true,
                    'protocol_settings' => ['network' => 'tcp', 'tls' => 0]]);
                Server::withoutEvents(fn () => $server->save());
                NativeRuntimeReadiness::assertSupportedNode($server->fresh());
                $nodes[$node['id']] = true;
            }
            DB::table('tx_settings')->updateOrInsert(['name' => 'server_token'], ['value' => json_encode($input['node_token'], JSON_THROW_ON_ERROR)]);
            return ['group_ids' => array_map('strval', array_keys($groups)), 'node_ids' => array_map('strval', array_keys($nodes))];
        });
    }
}
