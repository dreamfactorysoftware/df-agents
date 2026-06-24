<?php

namespace DreamFactory\Core\Agents\Services;

use DreamFactory\Core\Agents\Resources\AgentAccessRequestResource;
use DreamFactory\Core\Agents\Resources\AgentResource;
use DreamFactory\Core\Contracts\ServiceRequestInterface;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Services\BaseRestService;
use DreamFactory\Core\Utility\Session;

/**
 * The agents management service. Backs the admin UI:
 *   GET/POST/PATCH/DELETE /api/v2/agents/agents      (agent CRUD)
 *   GET/PATCH             /api/v2/agents/requests     (access-request queue)
 *
 * Agent self-service (catalog, request_access) is NOT here — those live on
 * auth-only routes under /api/v2/agent/* so an agent can reach them with its own
 * scoped key without holding admin rights to this management service.
 */
class Agents extends BaseRestService
{
    protected static $resources = [
        AgentResource::RESOURCE_NAME => [
            'name'       => AgentResource::RESOURCE_NAME,
            'class_name' => AgentResource::class,
            'label'      => 'Agents',
        ],
        AgentAccessRequestResource::RESOURCE_NAME => [
            'name'       => AgentAccessRequestResource::RESOURCE_NAME,
            'class_name' => AgentAccessRequestResource::class,
            'label'      => 'Access Requests',
        ],
    ];

    public function handleRequest(ServiceRequestInterface $request, $resource = null)
    {
        // Managing agent identities, keys and approvals is an administrator
        // capability — gate the whole management surface to sysadmins.
        if ($resource !== '_spec' && !Session::isSysAdmin()) {
            throw new ForbiddenException('Agent management is restricted to system administrators.');
        }

        return parent::handleRequest($request, $resource);
    }

    public function getApiDocInfo()
    {
        return [
            'openapi' => '3.0.0',
            'info'    => [
                'title'       => 'Agents',
                'description' => 'AI agent identity and governed access management.',
                'version'     => '1.0.0',
            ],
            'paths'   => new \stdClass(),
        ];
    }
}
