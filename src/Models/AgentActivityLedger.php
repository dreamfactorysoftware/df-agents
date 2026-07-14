<?php

namespace DreamFactory\Core\Agents\Models;

use Illuminate\Database\Eloquent\Model;

class AgentActivityLedger extends Model
{
    protected $table = 'agent_activity_ledger';
    public $timestamps = false;

    protected $fillable = [
        'trace_id', 'agent_id', 'user_id', 'role_id', 'app_id',
        'service_name', 'resource', 'table_name', 'verb',
        'status_code', 'row_count', 'duration_ms', 'occurred_at',
    ];
}
