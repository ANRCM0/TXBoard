<?php

return ['enabled' => env('AUTHORITY_WRITER_FENCE_ENABLED', false),
    'worker_epoch' => env('AUTHORITY_WRITER_EPOCH', '1'),
    'worker_role' => env('AUTHORITY_WRITER_ROLE', 'legacy'),
    'worker_scope' => env('AUTHORITY_WRITER_SCOPE', 'admin-user-editor/v1'),
    'worker_version' => env('AUTHORITY_WRITER_VERSION', 'authority-writer-fence/v1')];
