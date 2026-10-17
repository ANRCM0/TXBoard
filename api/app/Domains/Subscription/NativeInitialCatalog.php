<?php

namespace App\Domains\Subscription;

use App\Domains\Subscription\Internal\InitialCatalog;
use App\Domains\Subscription\Services\PlanPublicationService;
use App\Domains\Installation\NativePreparationInput;
use App\Domains\Installation\NativePreparationSteps;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NativeInitialCatalog implements InitialCatalog
{
    public function prepare(string $key, array $input): array
    {
        NativePreparationInput::validate($input, 'catalog');
        return app(NativePreparationSteps::class)->execute('subscription', $key, $input, function () use ($input, $key): array {
            if (Plan::query()->lockForUpdate()->exists()) throw new RuntimeException('NATIVE_PREPARATION_CATALOG_NOT_EMPTY');
            $result = [];
            foreach ($input['plans'] as $definition) {
                if (isset($result[$definition['id']]) || !DB::table('tx_server_group')->where('id', $definition['group_id'])->exists()) {
                    throw new RuntimeException('NATIVE_PREPARATION_INPUT_INVALID');
                }
                $prices = [];
                foreach ($definition['prices_minor'] as $period => $minor) {
                    $padded = str_pad($minor, 3, '0', STR_PAD_LEFT);
                    $prices[$period] = substr($padded, 0, -2).'.'.substr($padded, -2);
                }
                $plan = new Plan;
                $plan->forceFill(['id' => $definition['id'], 'name' => $definition['name'], 'group_id' => $definition['group_id'],
                    'transfer_enable' => $definition['quota_gib'], 'reset_traffic_method' => $definition['reset_method'],
                    'prices' => $prices, 'capacity_limit' => null, 'show' => true, 'sell' => true, 'renew' => true]);
                Plan::withoutEvents(fn () => $plan->save());
                $result[$definition['id']] = app(PlanPublicationService::class)->publish($plan->id,
                    'prepare-'.substr(hash('sha256', $key.':'.$plan->id), 0, 48), $definition['currency'], 'UTC');
            }
            return ['publications' => array_values($result)];
        });
    }
}
