<?php

namespace App\Domains\Installation\Contracts;

/** Current installation evidence and per-operation admission; values never authorize a future operation. */
interface InstallationAdmission
{
    public function inspect(): array;
    public function assertRuntime(): void;
    public function assertLegacyWriter(): void;
    public function assertNativeRuntime(): array;
    public function assertHttp(string $method, string $path): void;
    public function assertCatalogMutation(): void;
    public function assertNativeMutation(): array;
    public function assertNativeRead(): array;
}
