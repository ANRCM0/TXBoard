<?php

namespace App\Domains\Installation;

use App\Domains\Identity\Internal\InitialIdentity;
use App\Domains\Network\Internal\InitialNetwork;
use App\Domains\Subscription\Internal\InitialCatalog;
use RuntimeException;

final class NativePreparation
{
    public function prepare(#[\SensitiveParameter] array $input): array
    {
        NativePreparationInput::validate($input);
        if (config('core_installation.mode') !== 'native') throw new RuntimeException('NATIVE_PREPARATION_MODE_REQUIRED');
        $authority = app(InstallationAuthority::class);
        $state = $authority->initialize($input['installation_id'], 'native', $input['bootstrap_key']);
        if ($state['phase'] !== 'initializing') throw new RuntimeException('NATIVE_PREPARATION_ALREADY_ACTIVATED');
        app(NativePreparationSteps::class)->execute('manifest', $input['operation_key'], $input,
            static fn (): array => ['schema' => 'native-preparation-intent/v1']);
        return ['schema' => 'native-preparation-result/v1', 'installation_id' => $state['installation_id'],
            'identity' => app(InitialIdentity::class)->prepare($input['operation_key'], $input['identity']),
            'network' => app(InitialNetwork::class)->prepare($input['operation_key'], $input['network']),
            'catalog' => app(InitialCatalog::class)->prepare($input['operation_key'], $input['catalog'])];
    }
}
