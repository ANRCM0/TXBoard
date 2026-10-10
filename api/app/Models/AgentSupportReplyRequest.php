<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentSupportReplyRequest extends Model
{

protected $table = 'tx_agent_support_reply_request';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['message'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'approved_at' => 'integer',
    ];
}
