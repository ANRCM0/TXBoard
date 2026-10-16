<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Shared canonical storage model. Only versioned native services may grant authority. */
abstract class SubscriptionRecord extends Model
{
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'integer', 'updated_at' => 'integer', 'row_version' => 'integer'];
    }

    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();
        foreach ($attributes as $key => $value) {
            if ($value !== null && ($key === 'id' || str_ends_with($key, '_id')
                || str_ends_with($key, '_bytes') || str_ends_with($key, '_version')
                || in_array($key, ['version_no', 'period_no', 'processed_count', 'legacy_reset_count', 'attempts'], true))) {
                $attributes[$key] = (string) $value;
            }
        }
        return $attributes;
    }
}
