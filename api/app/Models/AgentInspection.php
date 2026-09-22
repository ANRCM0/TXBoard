<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentInspection extends Model
{
    protected $table = 'v2_agent_inspection';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'summary' => 'array',
        'findings' => 'array',
        'started_at' => 'integer',
        'finished_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
