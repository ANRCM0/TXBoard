<?php

namespace App\Extensions\Contracts;

interface ExtensionModule
{
    public function handle(ExtensionRequest $request): ExtensionResponse;
}
