<?php

namespace Plugin\ModulePortal;

use App\Extensions\Contracts\DeploymentModule;
use App\Extensions\Contracts\ExtensionModule;
use App\Extensions\Contracts\ExtensionRequest;
use App\Extensions\Contracts\ExtensionResponse;
use App\Extensions\Contracts\ExtensionFailure;
use App\Services\Plugin\AbstractPlugin;

final class Plugin extends AbstractPlugin implements DeploymentModule, ExtensionModule
{
    public function packageVersion(): string { return '1.0.0'; }

    public function prepareSchema(string $deploymentId, int $fromVersion, int $toVersion): void
    {
        if ($toVersion !== 1 || !in_array($fromVersion, [0, 1], true)) throw new ExtensionFailure('MODULE_SCHEMA_VERSION_CONFLICT');
    }

    public function handle(ExtensionRequest $request): ExtensionResponse
    {
        if ($request->operation !== 'status.get' || $request->actorKind !== 'admin') throw new ExtensionFailure('MODULE_OPERATION_NOT_FOUND', 404);
        return new ExtensionResponse(['label' => $this->getConfig('label', 'Module portal'), 'version' => $this->packageVersion(),
            'actor_id' => (string) $request->userId]);
    }
}
