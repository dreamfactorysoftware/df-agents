<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Agent identity (AAN MVP). An agent is a first-class API consumer that owns a
 * Role (existing RBAC) and a short-lived API key. The key is enforced with a
 * TTL by the AgentKeyTtl middleware. agent_access_requests holds the pending
 * human-approval queue an agent files via the request_access MCP tool.
 */
class CreateAgentsTables extends Migration
{
    public function up()
    {
        $driver = Schema::getConnection()->getDriverName();
        $onDelete = (('sqlsrv' === $driver) ? 'no action' : 'set null');

        Schema::create('agents', function (Blueprint $t) use ($onDelete) {
            $t->increments('id');
            $t->string('name', 64)->unique();
            // Free-form, plausibly LLM-written at registration — overflows varchar(255).
            $t->text('description')->nullable();
            // The human who owns/manages this agent.
            $t->integer('owner_id')->unsigned()->nullable();
            $t->foreign('owner_id')->references('id')->on('user')->onDelete($onDelete);
            // The Role that scopes what this agent can access (existing RBAC).
            $t->integer('role_id')->unsigned()->nullable();
            $t->foreign('role_id')->references('id')->on('role')->onDelete($onDelete);
            // Short-lived credential. Uniqueness enforced in the model (mirrors App).
            $t->string('api_key')->nullable();
            $t->integer('key_ttl_hours')->unsigned()->default(4);
            $t->timestamp('key_issued_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_active_at')->nullable();
            $t->timestamp('created_date')->nullable();
            $t->timestamp('last_modified_date')->useCurrent();
            $t->integer('created_by_id')->unsigned()->nullable();
            $t->foreign('created_by_id')->references('id')->on('user')->onDelete($onDelete);
            $t->integer('last_modified_by_id')->unsigned()->nullable();
            $t->foreign('last_modified_by_id')->references('id')->on('user')->onDelete($onDelete);
        });

        Schema::create('agent_access_requests', function (Blueprint $t) use ($onDelete) {
            $t->increments('id');
            $t->integer('agent_id')->unsigned();
            $t->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
            // JSON arrays of requested service names / operations (GET, POST, ...).
            $t->mediumText('requested_services')->nullable();
            $t->mediumText('requested_operations')->nullable();
            // Free-form: the requester's reason, then the broker's decision note
            // appended on resolve. Both are LLM-generated and overflow varchar(255).
            $t->text('note')->nullable();
            $t->string('status', 32)->default('pending');
            $t->integer('resolved_by_id')->unsigned()->nullable();
            $t->foreign('resolved_by_id')->references('id')->on('user')->onDelete($onDelete);
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('created_date')->nullable();
            $t->timestamp('last_modified_date')->useCurrent();
        });
    }

    public function down()
    {
        Schema::dropIfExists('agent_access_requests');
        Schema::dropIfExists('agents');
    }
}
