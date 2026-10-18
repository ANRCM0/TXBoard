<?php

namespace App\Extensions\Contracts;

/** Trusted package code. Schema work must resume safely with the SAME deployment ID after interruption. */
interface DeploymentModule extends VersionedModule
{
    public function prepareSchema(string $deploymentId, int $fromVersion, int $toVersion): void;
}
