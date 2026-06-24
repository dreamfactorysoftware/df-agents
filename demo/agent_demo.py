#!/usr/bin/env python3
"""
Agent Access Negotiation — live agentic demo.

A REAL Claude agent (Opus 4.8), holding only its own scoped DreamFactory API
key, is told to do a job it isn't fully permitted to do. It discovers its
access, hits the governance wall, requests the missing access through the
governed flow, waits for a human to approve in the admin UI, then completes the
task on the same key. Tools are wired to the live DreamFactory REST API.

Setup:
    pip install anthropic
    export ANTHROPIC_API_KEY=sk-ant-...
    python agent_demo.py            # human approves in the UI when prompted
    AUTO_APPROVE=1 python agent_demo.py   # unattended (auto-approves) for testing

Env (defaults match the local dev stack):
    DF_BASE_URL=http://localhost:8085  AGENT_ID=2  DF_DB_SERVICE=test_mysql
    DF_ADMIN_EMAIL=admin@dreamfactory.com  DF_ADMIN_PASSWORD=passwordpassword
"""
import json
import os
import sys
import time
import urllib.error
import urllib.request

import anthropic

DF = os.environ.get("DF_BASE_URL", "http://localhost:8085").rstrip("/")
AGENT_ID = os.environ.get("AGENT_ID", "2")
DB = os.environ.get("DF_DB_SERVICE", "test_mysql")
ADMIN_EMAIL = os.environ.get("DF_ADMIN_EMAIL", "admin@dreamfactory.com")
ADMIN_PW = os.environ.get("DF_ADMIN_PASSWORD", "passwordpassword")
AUTO_APPROVE = os.environ.get("AUTO_APPROVE") == "1"
MODEL = os.environ.get("CLAUDE_MODEL", "claude-opus-4-8")
TARGET_ORDER = os.environ.get("TARGET_ORDER", "1")

# tiny ANSI palette so the recording reads well
C = dict(dim="\033[2m", bold="\033[1m", cyan="\033[36m", green="\033[32m",
         red="\033[31m", yellow="\033[33m", reset="\033[0m")


def c(s, color):
    return f"{C[color]}{s}{C['reset']}"


def http(method, path, headers=None, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(DF + path, data=data, method=method, headers=dict(headers or {}))
    if data:
        req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return r.status, json.loads(r.read() or "null")
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read() or "null")
        except Exception:
            return e.code, None


# ---------------------------------------------------------------- admin setup
def admin_token():
    s, b = http("POST", "/api/v2/system/admin/session",
                body={"email": ADMIN_EMAIL, "password": ADMIN_PW})
    if s != 200:
        sys.exit(c(f"admin login failed ({s}). Is the stack up on {DF}?", "red"))
    return b["session_token"]


ATOK = admin_token()
AH = {"X-DreamFactory-Session-Token": ATOK}


def reset_demo():
    """Put the agent back in the blocked state so the demo is repeatable: force
    EVERY access row on the agent's role to GET-only (so no row can grant a
    write, even leftover duplicate rows from prior runs), reset the target
    order, and flush DreamFactory's role-permission cache so it takes effect."""
    _, agent = http("GET", f"/api/v2/agents/agents/{AGENT_ID}?fields=role_id", AH)
    role_id = agent.get("role_id")
    if role_id:
        _, role = http("GET", f"/api/v2/system/role/{role_id}?related=role_service_access_by_role_id", AH)
        rows = role.get("role_service_access_by_role_id") or []
        out = [{**a, "verb_mask": 1} for a in rows]   # 1 == GET; harmless on dups
        if out:
            http("PATCH", f"/api/v2/system/role/{role_id}",
                 AH, {"role_service_access_by_role_id": out})
    http("PATCH", f"/api/v2/{DB}/_table/orders/{TARGET_ORDER}", AH, {"status": "pending"})
    # The role permission set is cached; the backend clears it on grant, but the
    # reset edits rows directly, so flush the cache explicitly.
    http("DELETE", "/api/v2/system/cache", AH)


def agent_key():
    _, a = http("GET", f"/api/v2/agents/agents/{AGENT_ID}?fields=name,api_key,key_ttl_hours", AH)
    return a["name"], a["api_key"], a.get("key_ttl_hours")


# ------------------------------------------------------------------- the tools
AGENT_NAME, AGENT_KEY, AGENT_TTL = "", "", None
KH = {}  # agent key headers — set in main()


def t_discover(_inp):
    s, b = http("GET", "/api/v2/agent/catalog", KH)
    return json.dumps(b if s == 200 else {"http_status": s, "body": b})


def t_query(inp):
    table = inp["table"]
    qs = f"?limit={int(inp.get('limit', 5))}"
    if inp.get("id") is not None:
        path = f"/api/v2/{DB}/_table/{table}/{inp['id']}"
        qs = ""
    else:
        path = f"/api/v2/{DB}/_table/{table}"
        if inp.get("filter"):
            qs += "&filter=" + urllib.request.quote(inp["filter"])
    s, b = http("GET", path + qs, KH)
    return json.dumps({"http_status": s, "result": b})


def t_update(inp):
    s, b = http("PATCH", f"/api/v2/{DB}/_table/{inp['table']}/{inp['id']}", KH, inp["fields"])
    if s == 200:
        return json.dumps({"http_status": 200, "result": b})
    return json.dumps({"http_status": s,
                       "error": "Access denied — your role does not permit this operation on this table.",
                       "body": b})


def t_request_access(inp):
    services = inp.get("services") or [DB]
    operations = inp.get("operations") or []
    note = inp.get("note", "")
    s, b = http("POST", "/api/v2/agent/request_access", KH,
                {"services": services, "operations": operations, "note": note})
    if s != 200:
        return json.dumps({"http_status": s, "error": "request_access failed", "body": b})
    rid = b["request_id"]
    print(c(f"\n   🔔 Slack alert fired · pending request #{rid}: "
            f"{AGENT_NAME} wants {','.join(operations)} on {','.join(services)}", "yellow"))
    if AUTO_APPROVE:
        time.sleep(1.5)
        http("PATCH", f"/api/v2/agents/requests/{rid}", AH, {"status": "approved"})
        print(c("   ✅ [auto] administrator approved the request", "green"))
    else:
        print(c(f"   ⏳ waiting for a human to APPROVE request #{rid} in the admin UI "
                f"(Agents → Pending access requests)…", "yellow"))
        while True:
            time.sleep(2)
            _, r = http("GET", f"/api/v2/agents/requests/{rid}?fields=status", AH)
            st = (r or {}).get("status")
            if st == "approved":
                print(c("   ✅ administrator approved the request", "green"))
                break
            if st == "denied":
                return json.dumps({"status": "denied",
                                   "message": "The administrator denied this access request. Do not retry; explain that you are blocked."})
    return json.dumps({"status": "approved",
                       "message": "Access granted on your role. Retry the operation that was denied — it will now succeed with your existing key."})


TOOLS = [
    {"name": "discover_services",
     "description": "Discover which services, tables and operations YOUR role permits. Call this first to learn your boundaries.",
     "input_schema": {"type": "object", "properties": {}}},
    {"name": "query_records",
     "description": "Read records from a table in the database. Use to inspect data you have read access to.",
     "input_schema": {"type": "object", "properties": {
         "table": {"type": "string"},
         "id": {"type": ["string", "integer"], "description": "Fetch a single record by primary key (optional)."},
         "filter": {"type": "string", "description": "Optional SQL-style filter, e.g. status=pending."},
         "limit": {"type": "integer"}},
         "required": ["table"]}},
    {"name": "update_record",
     "description": "Update (PATCH) a single record by id. Returns http_status 200 on success, or an error if your role does not permit writes on this table.",
     "input_schema": {"type": "object", "properties": {
         "table": {"type": "string"},
         "id": {"type": ["string", "integer"]},
         "fields": {"type": "object", "description": "Columns to update, e.g. {\"status\": \"refunded\"}"}},
         "required": ["table", "id", "fields"]}},
    {"name": "request_access",
     "description": "Request access you do not currently have. Creates a pending request for human approval and BLOCKS until an administrator approves or denies it. On approval, retry the blocked operation.",
     "input_schema": {"type": "object", "properties": {
         "services": {"type": "array", "items": {"type": "string"}},
         "operations": {"type": "array", "items": {"type": "string"}, "description": "GET, POST, PUT, PATCH, DELETE"},
         "note": {"type": "string", "description": "Why you need this (shown to the approver)."}},
         "required": ["operations"]}},
]
DISPATCH = {"discover_services": t_discover, "query_records": t_query,
            "update_record": t_update, "request_access": t_request_access}


def main():
    global AGENT_NAME, AGENT_KEY, AGENT_TTL, KH
    if not os.environ.get("ANTHROPIC_API_KEY"):
        sys.exit(c("Set ANTHROPIC_API_KEY first (export ANTHROPIC_API_KEY=sk-ant-...).", "red"))

    print(c("\n  Resetting demo to the blocked state…", "dim"))
    reset_demo()
    AGENT_NAME, AGENT_KEY, AGENT_TTL = agent_key()
    KH = {"X-DreamFactory-API-Key": AGENT_KEY}
    print(c(f"  Agent: {AGENT_NAME}   key {AGENT_KEY[:8]}…   TTL {AGENT_TTL}h   "
            f"DB: {DB}   approval: {'AUTO' if AUTO_APPROVE else 'human-in-the-UI'}\n", "dim"))

    # Confirm the agent really starts blocked, or the governance beat won't show.
    probe, _ = http("PATCH", f"/api/v2/{DB}/_table/orders/{TARGET_ORDER}", KH, {"status": "pending"})
    if probe == 200:
        print(c("  ⚠ reset did not revoke write access — the agent will NOT hit the wall.\n"
                "    Clear caches and retry: docker exec df-development-web-1 \\\n"
                "      php artisan cache:clear\n", "red"))

    task = (f"A customer has been refunded for order #{TARGET_ORDER}. "
            f"Update that order's status to 'refunded' in the {DB} database. "
            f"You are an autonomous agent with your own scoped API key — you can only do what your role allows.")
    client = anthropic.Anthropic()
    system = (
        f"You are '{AGENT_NAME}', an autonomous AI agent acting against a DreamFactory data platform "
        "through your own short-lived, role-scoped API key. Work the task step by step. "
        "FIRST call discover_services to learn your boundaries. Then do the work. "
        "If an operation is denied (http_status 401/403), do NOT give up: call request_access for exactly "
        "the operation and service you need, with a short business reason. That tool blocks until a human "
        "approves; once it returns approved, retry the denied operation. "
        "Narrate each step in one short sentence so an observer can follow along. Stop when the task is done.")
    messages = [{"role": "user", "content": task}]

    print(c("  ── TASK ", "bold") + c(task, "cyan") + "\n")
    while True:
        resp = client.messages.create(model=MODEL, max_tokens=4000,
                                      system=system, tools=TOOLS,
                                      # effort via extra_body so this works on older
                                      # SDKs too; the server applies it (Opus 4.8).
                                      extra_body={"output_config": {"effort": "low"}},
                                      messages=messages)
        for block in resp.content:
            if block.type == "text" and block.text.strip():
                print("  " + c("agent▸ ", "bold") + block.text.strip())
            elif block.type == "tool_use":
                print(c(f"   ↳ {block.name}({json.dumps(block.input)})", "dim"))
        if resp.stop_reason != "tool_use":
            break
        messages.append({"role": "assistant", "content": resp.content})
        results = []
        for block in resp.content:
            if block.type == "tool_use":
                out = DISPATCH[block.name](block.input)
                results.append({"type": "tool_result", "tool_use_id": block.id, "content": out})
        messages.append({"role": "user", "content": results})

    _, o = http("GET", f"/api/v2/{DB}/_table/orders/{TARGET_ORDER}?fields=id,status", KH)
    print("\n  " + c("RESULT ", "bold") + c(f"orders/{TARGET_ORDER} → {o}", "green") + "\n")


if __name__ == "__main__":
    main()
