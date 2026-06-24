<?php

namespace DreamFactory\Core\Agents\Models;

use DreamFactory\Core\Models\App;
use DreamFactory\Core\Models\BaseSystemModel;
use DreamFactory\Core\Models\Role;
use DreamFactory\Core\Models\User;

/**
 * An AI agent: a first-class API consumer with its own identity, a Role
 * (existing RBAC) and a short-lived API key.
 *
 * Auth reuse: rather than build a parallel auth stack, each agent provisions a
 * backing App named "agent:{id}" carrying the same role_id + api_key. The whole
 * existing api_key -> App -> role.services pipeline (REST RBAC, MCP service
 * discovery, audit tagging) then works unchanged. The ONLY net-new auth code is
 * the TTL gate (AgentKeyTtl middleware), since DF has no key-expiry concept.
 *
 * @property int    $id
 * @property string $name
 * @property string $api_key
 * @property int    $role_id
 * @property int    $key_ttl_hours
 * @property string $key_issued_at
 */
class Agent extends BaseSystemModel
{
    protected $table = 'agents';

    protected $fillable = [
        'name',
        'description',
        'owner_id',
        'role_id',
        'api_key',
        'key_ttl_hours',
        'is_active',
    ];

    protected $guarded = [
        'id',
        'key_issued_at',
        'last_active_at',
        'created_date',
        'last_modified_date',
        'created_by_id',
        'last_modified_by_id',
    ];

    protected $casts = [
        'id'            => 'integer',
        'owner_id'      => 'integer',
        'role_id'       => 'integer',
        'key_ttl_hours' => 'integer',
        'is_active'     => 'boolean',
    ];

    // Defaults present in memory on create, so the saved hook syncs an ACTIVE
    // backing app (DB-level defaults don't reach the model before the hook runs).
    protected $attributes = [
        'is_active'     => true,
        'key_ttl_hours' => 4,
    ];

    protected $rules = [
        'name'          => 'required|regex:/(^[A-Za-z0-9_\-]+$)+/',
        'role_id'       => 'required|integer',
        'key_ttl_hours' => 'integer|min:1|max:24',
    ];

    public static function boot()
    {
        parent::boot();

        // Mint a key + start the TTL clock on creation.
        static::creating(function (Agent $agent) {
            if (empty($agent->api_key)) {
                $agent->api_key = App::generateApiKey($agent->name);
            }
            $agent->key_issued_at = $agent->key_issued_at ?? now();
            return true;
        });

        // Keep the backing App in lock-step with the agent's role + key.
        static::saved(function (Agent $agent) {
            $agent->syncBackingApp();
            return true;
        });

        static::deleted(function (Agent $agent) {
            $agent->removeBackingApp();
            return true;
        });
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function access_requests()
    {
        return $this->hasMany(AgentAccessRequest::class, 'agent_id');
    }

    /** Stable name for this agent's backing App, independent of key rotation. */
    public function backingAppName(): string
    {
        return 'agent:' . $this->id;
    }

    /** True once key_issued_at + key_ttl_hours has elapsed. */
    public function keyExpired(): bool
    {
        if (empty($this->key_issued_at)) {
            return false;
        }
        $issued = $this->key_issued_at instanceof \DateTimeInterface
            ? $this->key_issued_at
            : new \DateTime((string)$this->key_issued_at);
        $expiresAt = (clone $issued)->modify('+' . (int)$this->key_ttl_hours . ' hours');
        return now() > $expiresAt;
    }

    /** Issue a fresh key and restart the TTL clock (used on access approval). */
    public function rotateKey(): void
    {
        $this->api_key = App::generateApiKey($this->name);
        $this->key_issued_at = now();
        $this->save();
    }

    /**
     * Provision/update the hidden backing App so existing auth+RBAC+audit treat
     * the agent key like any app key.
     *
     * ponytail: backing apps surface in the API Keys list as "agent:N". Harmless;
     * hide them with a list filter only if it actually bothers anyone.
     */
    public function syncBackingApp(): void
    {
        $app = App::whereName($this->backingAppName())->first() ?: new App();
        $app->name = $this->backingAppName();
        $app->description = 'Backing app for agent "' . $this->name . '" (managed by df-agents).';
        $app->role_id = $this->role_id;
        $app->api_key = $this->api_key;
        $app->is_active = (bool)$this->is_active;
        $app->type = 0;
        $app->save();
    }

    public function removeBackingApp(): void
    {
        App::whereName($this->backingAppName())->delete();
    }
}
