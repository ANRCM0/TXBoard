<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerRoute extends Model
{
    
    use \App\Support\Database\ResolvesNativeEloquentTable;
protected $table = 'v2_server_route';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'match' => 'array',
        'enabled' => 'boolean',
        'sort' => 'integer',
    ];
}
