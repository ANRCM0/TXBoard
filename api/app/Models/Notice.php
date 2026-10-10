<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notice extends Model
{
    
    use \App\Support\Database\ResolvesNativeEloquentTable;
protected $table = 'v2_notice';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'tags' => 'array',
        'show' => 'boolean',
    ];
}
