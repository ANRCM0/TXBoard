<?php

namespace App\Extensions\Contracts;

interface VersionedModule
{
    public function packageVersion(): string;
}
