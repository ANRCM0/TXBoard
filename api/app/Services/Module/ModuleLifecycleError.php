<?php

namespace App\Services\Module;

final readonly class ModuleLifecycleError
{
    public function __construct(
        public ModuleLifecycleErrorCode $code,
        public string $message,
        public ?string $adapter = null,
    ) {
    }

    public function toArray(): array
    {
        $error = [
            'code' => $this->code->value,
            'message' => $this->message,
        ];

        if ($this->adapter !== null) {
            $error['adapter'] = $this->adapter;
        }

        return $error;
    }
}
