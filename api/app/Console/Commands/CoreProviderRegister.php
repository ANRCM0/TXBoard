<?php

namespace App\Console\Commands;

use App\Domains\CredentialProvider\ProviderError;
use App\Domains\Installation\InstallationAuthorityException;
use App\Domains\Subscription\Services\NativeCredentialProviderService;
use Illuminate\Console\Command;
use Throwable;

final class CoreProviderRegister extends Command
{
    protected $signature = 'core:provider-register {provider} {--key=}';
    protected $description = 'Register an operator-configured native credential provider without activating native runtime';

    public function handle(NativeCredentialProviderService $service): int
    {
        try {
            $provider = $this->argument('provider');
            $key = $this->option('key');
            if (!is_string($provider) || !is_string($key) || $key === '') {
                $this->line('{"error_code":"PROVIDER_INPUT_INVALID"}');
                return self::FAILURE;
            }
            $this->line(json_encode($service->register($provider, $key), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (Throwable $error) {
            $code = $error instanceof ProviderError ? $error->codeName
                : ($error instanceof InstallationAuthorityException ? $error->getMessage() : 'PROVIDER_REGISTRATION_FAILED');
            $this->line(json_encode(['error_code' => $code], JSON_THROW_ON_ERROR));
            return self::FAILURE;
        }
    }
}
