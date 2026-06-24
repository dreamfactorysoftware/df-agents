<?php

namespace DreamFactory\Core\Agents\Models;

use DreamFactory\Core\Models\BaseServiceConfigNoDbModel;

/**
 * The agents management service is a singleton with no configurable settings;
 * an empty schema keeps the service-config UI happy.
 */
class AgentsConfig extends BaseServiceConfigNoDbModel
{
    public static function getSchema()
    {
        return [];
    }
}
