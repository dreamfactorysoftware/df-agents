<?php

namespace DreamFactory\Core\Agents\Resources;

use DreamFactory\Core\Agents\Models\AgentAccessRequest;
use DreamFactory\Core\System\Resources\BaseSystemResource;

/**
 * CRUD for the pending-request queue. Approve/deny is just a PATCH that sets
 * status to "approved"/"denied"; the model applies the side-effects (resolve
 * stamp, key rotation on approval, alert). So the admin UI's one-click approve
 * is PATCH /agents/requests/{id} {"status":"approved"} — no special verb needed.
 */
class AgentAccessRequestResource extends BaseSystemResource
{
    const RESOURCE_NAME = 'requests';

    protected static $model = AgentAccessRequest::class;
}
