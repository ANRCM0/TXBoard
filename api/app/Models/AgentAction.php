<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentAction extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_TIMED_OUT = 'timed_out';
    public const STATUS_UNKNOWN = 'unknown';

    protected $table = 'v2_agent_action';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'input' => 'array',
        'result' => 'array',
        'approved_at' => 'integer',
        'started_at' => 'integer',
        'finished_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function node()
    {
        return $this->belongsTo(Server::class, 'node_id');
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
