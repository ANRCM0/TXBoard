<?php

namespace App\Models;

class MigrationCheckpoint extends SubscriptionShadowModel
{
    protected $table = 'tx_migration_checkpoint';

    protected function casts(): array
    {
        return parent::casts() + [
            'cursor_user_id' => 'integer', 'high_water_user_id' => 'integer',
            'processed_count' => 'integer', 'details' => 'array',
        ];
    }
}
