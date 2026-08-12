<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Task routing (AAN). skills is a JSON array of capability keywords that the
 * route resource matches against a free-form task description (+3 per hit,
 * vs +1 for name/description token hits). chat_service_id points at the
 * agent's conversational persona service so a routing decision can hand the
 * caller straight to a chat endpoint without a second lookup.
 */
class AddRoutingColumnsToAgents extends Migration
{
    public function up()
    {
        $driver = Schema::getConnection()->getDriverName();
        $onDelete = (('sqlsrv' === $driver) ? 'no action' : 'set null');

        Schema::table('agents', function (Blueprint $t) use ($onDelete) {
            // JSON array of capability keyword strings (mirrors the
            // requested_services convention on agent_access_requests).
            $t->mediumText('skills')->nullable();
            // The agent's chat persona service, surfaced by the router.
            $t->integer('chat_service_id')->unsigned()->nullable();
            $t->foreign('chat_service_id')->references('id')->on('service')->onDelete($onDelete);
        });
    }

    public function down()
    {
        Schema::table('agents', function (Blueprint $t) {
            $t->dropForeign(['chat_service_id']);
            $t->dropColumn(['chat_service_id', 'skills']);
        });
    }
}
