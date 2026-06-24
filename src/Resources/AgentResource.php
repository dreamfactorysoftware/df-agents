<?php

namespace DreamFactory\Core\Agents\Resources;

use DreamFactory\Core\Agents\Models\Agent;
use DreamFactory\Core\System\Resources\BaseSystemResource;

class AgentResource extends BaseSystemResource
{
    const RESOURCE_NAME = 'agents';

    protected static $model = Agent::class;
}
