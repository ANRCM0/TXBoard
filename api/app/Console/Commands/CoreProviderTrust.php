<?php

namespace App\Console\Commands;

use App\Domains\CredentialProvider\NativeProviderTrust;
use Illuminate\Console\Command;
use Throwable;

final class CoreProviderTrust extends Command
{
    protected $signature = 'core:provider-trust {action : preflight|rotate} {--key=} {--expected-digest=} {--configuration-digest=}';
    protected $description = 'Probe and explicitly commit operator-provisioned native Provider/signing trust';

    public function handle(NativeProviderTrust $trust): int
    {
        try {
            $result = match ($this->argument('action')) {
                'preflight' => $trust->preflight(),
                'rotate' => $trust->rotate((string) $this->option('key'), (string) $this->option('expected-digest'),
                    (string) $this->option('configuration-digest')),
                default => throw new \DomainException('NATIVE_PROVIDER_TRUST_INPUT_INVALID'),
            };
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (Throwable $error) {
            $code = $error->getMessage();
            $this->line(json_encode(['error_code' => preg_match('/\ANATIVE_PROVIDER_TRUST_[A-Z_]+\z/D', $code) === 1
                ? $code : 'NATIVE_PROVIDER_TRUST_UNAVAILABLE'], JSON_THROW_ON_ERROR));
            return self::FAILURE;
        }
    }
}
