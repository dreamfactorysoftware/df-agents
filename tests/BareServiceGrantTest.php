<?php

use DreamFactory\Core\Agents\Models\Agent;
use DreamFactory\Core\Agents\Models\AgentAccessRequest;
use DreamFactory\Core\Models\Role;
use DreamFactory\Core\Models\RoleServiceAccess;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\Utility\Session;

/**
 * Regression test for #5.
 *
 * Approving an access request for a bare service name ("db") must grant on the
 * service's _table/* row. Previously the grant OR-ed the verb into whatever rows
 * the role already had for that service, so when the role had rows for some
 * tables but not the one the agent needed, approval changed nothing and the
 * agent kept getting 403.
 */
class BareServiceGrantTest extends \DreamFactory\Core\Testing\TestCase
{
    const ROLE = 'df_test_agents_5_role';
    const AGENT = 'df_test_agents_5_agent';

    public function setUp(): void
    {
        parent::setUp();
        Session::setUserInfoWithJWT(User::find(1));
        $this->cleanup();
    }

    public function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    protected function cleanup()
    {
        if ($agent = Agent::whereName(static::AGENT)->first()) {
            AgentAccessRequest::where('agent_id', $agent->id)->delete();
            $agent->delete();
        }
        Role::whereName(static::ROLE)->delete();
    }

    public function testApprovingBareServiceCreatesWildcardTableRow()
    {
        $serviceId = Service::whereName('db')->value('id');
        $this->assertNotEmpty($serviceId, 'test instance has no db service');

        $role = Role::create(['name' => static::ROLE, 'is_active' => true]);
        // The role already has a row for one table, but not the one the agent needs.
        RoleServiceAccess::create([
            'role_id'        => $role->id,
            'service_id'     => $serviceId,
            'component'      => '_table/todo/*',
            'verb_mask'      => 1,
            'requestor_mask' => 3,
            'filters'        => [],
            'filter_op'      => 'AND',
        ]);

        $agent = Agent::create(['name' => static::AGENT, 'owner_id' => 1, 'role_id' => $role->id]);
        $req = AgentAccessRequest::create([
            'agent_id'             => $agent->id,
            'requested_services'   => ['db'],
            'requested_operations' => ['GET'],
            'status'               => 'pending',
        ]);

        $req->status = 'approved';
        $req->save();

        $rows = RoleServiceAccess::where('role_id', $role->id)
            ->where('service_id', $serviceId)
            ->get()
            ->keyBy('component');

        $this->assertArrayHasKey('_table/*', $rows->all(), 'bare-service approval did not create a _table/* row');
        $this->assertEquals(1, (int)$rows['_table/*']->verb_mask);
        $this->assertEquals(1, (int)$rows['_table/todo/*']->verb_mask, 'existing table row must be left alone');
    }
}
