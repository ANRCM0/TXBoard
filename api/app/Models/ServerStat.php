<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerStat extends Model
{
    
    use \App\Support\Database\ResolvesNativeEloquentTable;
protected $table = 'v2_server_stat';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
