#!/usr/bin/env python3
"""
Agent Access Negotiation — multi-agent, cross-database demo.

Two REAL Claude agents, each with its own scoped key into a *different* database:

  • sales-agent  → MySQL  (orders)            [Agent A]
  • crm-agent    → Postgres (customer CRM)     [Agent B, owns/brokers that domain]

A is asked for a report that needs data from BOTH databases (revenue by customer
city: order totals live in MySQL, the customer→city map lives in Postgres). A has
no Postgres access, so it requests it through AAN — and the request is brokered
not by a human but by crm-agent, the agent that owns the Postgres domain. B
reviews the request against its policy and grants read-only access to its
customers table. A then joins across both databases and produces the report.

This is agent-to-agent access negotiation: agents are the new developers, and
they get the same governed, least-privilege access a developer would — granted
by the domain owner, fully audited. DreamFactory's multi-connector platform is
what makes one governed request span MySQL and Postgres at once.

Setup:
    pip install anthropic         # or: already installed system-wide
    export ANTHROPIC_API_KEY=sk-ant-...
    python3 agent_demo2_multiagent.py
"""
import json
import os
import sys
import urllib.error
import urllib.request

import anthropic

DF = os.environ.get("DF_BASE_URL", "http://localhost:8085").rstrip("/")
ADMIN_EMAIL = os.environ.get("DF_ADMIN_EMAIL", "admin@dreamfactory.com")
ADMIN_PW = os.environ.get("DF_ADMIN_PASSWORD", "passwordpassword")
MYSQL = os.environ.get("DF_MYSQL_SERVICE", "test_mysql")
PG = os.environ.get("DF_PG_SERVICE", "test_pgsql")
MODEL = os.environ.get("CLAUDE_MODEL", "claude-opus-4-8")

C = dict(dim="\033[2m", bold="\033[1m", cyan="\033[36m", green="\033[32m",
         red="\033[31m", yellow="\033[33m", mag="\033[35m", reset="\033[0m")


def col(s, k):
    return f"{C[k]}{s}{C['reset']}"


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


def admin_token():
    s, b = http("POST", "/api/v2/system/admin/session",
                body={"email": ADMIN_EMAIL, "password": ADMIN_PW})
    if s != 200:
        sys.exit(col(f"admin login failed ({s}). Is the stack up on {DF}?", "red"))
    return b["session_token"]


ATOK = admin_token()
AH = {"X-DreamFactory-Session-Token": ATOK}


def service_id(name):
    _, b = http("GET", f"/api/v2/system/service?fields=id&filter=name%3D{name}", AH)
    r = (b or {}).get("resource") or []
    return r[0]["id"] if r else None


def admin_user_id():
    out = []
    _, b = http("GET", "/api/v2/system/admin?fields=id", AH)
    out = (b or {}).get("resource") or []
    return out[0]["id"] if out else None


def find_by_name(path, name):
    _, b = http("GET", f"{path}?fields=id&filter=name%3D{name}", AH)
    r = (b or {}).get("resource") or []
    return r[0] if r else None


def access_row(svc_id, component, verb_mask=1):
    return {"service_id": svc_id, "component": component, "verb_mask": verb_mask,
            "requestor_mask": 3, "filters": [], "filter_op": "AND"}


def ensure_role(name, rows):
    existing = find_by_name("/api/v2/system/role", name)
    if existing:
        return existing["id"]
    s, b = http("POST", "/api/v2/system/role?fields=id", AH,
                {"resource": [{"name": name, "description": name, "is_active": True,
                               "role_service_access_by_role_id": rows}]})
    return b["resource"][0]["id"]


def ensure_agent(name, role_id, owner):
    existing = find_by_name("/api/v2/agents/agents", name)
    if existing:
        return existing["id"]
    s, b = http("POST", "/api/v2/agents/agents?fields=id", AH,
                {"resource": [{"name": name, "description": name, "role_id": role_id,
                               "owner_id": owner, "key_ttl_hours": 4}]})
    return b["resource"][0]["id"]


def agent_key(aid):
    _, a = http("GET", f"/api/v2/agents/agents/{aid}?fields=name,api_key", AH)
    return a["name"], a["api_key"]


def reset_sales_role(agent_id, mysql_id):
    """Strip any cross-domain grant from prior runs by rebuilding the sales
    agent's role from scratch (the REST API can't delete individual access rows,
    so we delete + recreate the role and reassign the agent)."""
    name = "demo2-mysql-orders-read"
    old = find_by_name("/api/v2/system/role", name)
    if old:
        http("DELETE", f"/api/v2/system/role/{old['id']}", AH)
    s, b = http("POST", "/api/v2/system/role?fields=id", AH,
                {"resource": [{"name": name, "description": "read MySQL orders", "is_active": True,
                               "role_service_access_by_role_id": [access_row(mysql_id, "_table/orders/*")]}]})
    rid = b["resource"][0]["id"]
    http("PATCH", f"/api/v2/agents/agents/{agent_id}", AH, {"role_id": rid})
    http("DELETE", "/api/v2/system/cache", AH)  # role permissions are cached
    return rid


# ------------------------------------------------------------- generic agent loop
def run_loop(client, name, color, system, first_msg, tools, dispatch, max_turns=12):
    messages = [{"role": "user", "content": first_msg}]
    for _ in range(max_turns):
        resp = client.messages.create(model=MODEL, max_tokens=4000, system=system,
                                      tools=tools, extra_body={"output_config": {"effort": "low"}},
                                      messages=messages)
        for blk in resp.content:
            if blk.type == "text" and blk.text.strip():
                print("  " + col(f"{name}▸ ", "bold") + col(blk.text.strip(), color))
            elif blk.type == "tool_use":
                print(col(f"     ↳ {blk.name}({json.dumps(blk.input)})", "dim"))
        if resp.stop_reason != "tool_use":
            return resp
        messages.append({"role": "assistant", "content": resp.content})
        results = []
        for blk in resp.content:
            if blk.type == "tool_use":
                results.append({"type": "tool_result", "tool_use_id": blk.id,
                                "content": dispatch[blk.name](blk.input)})
        messages.append({"role": "user", "content": results})
    return None


# --------------------------------------------------------------------- the demo
def main():
    if not os.environ.get("ANTHROPIC_API_KEY"):
        sys.exit(col("Set ANTHROPIC_API_KEY first.", "red"))
    client = anthropic.Anthropic()

    mysql_id, pg_id = service_id(MYSQL), service_id(PG)
    owner = admin_user_id()
    if not (mysql_id and pg_id):
        sys.exit(col(f"Need services '{MYSQL}' and '{PG}' configured in DreamFactory.", "red"))

    print(col("\n  Setting up two agents in two databases…", "dim"))
    role_a = ensure_role("demo2-mysql-orders-read", [access_row(mysql_id, "_table/orders/*")])
    role_b = ensure_role("demo2-pg-crm-read", [access_row(pg_id, "_table/customers/*")])
    a_id = ensure_agent("sales-agent", role_a, owner)
    b_id = ensure_agent("crm-agent", role_b, owner)
    reset_sales_role(a_id, mysql_id)              # back to MySQL-only for a clean run
    A_NAME, KA = agent_key(a_id)
    B_NAME, KB = agent_key(b_id)
    AKH = {"X-DreamFactory-API-Key": KA}
    BKH = {"X-DreamFactory-API-Key": KB}
    print(col(f"  {A_NAME}: MySQL ({MYSQL}/orders)      crm-agent owns Postgres ({PG}/customers)\n", "dim"))

    # ---- crm-agent (broker) tools ----
    def b_inbox(_):
        _, b = http("GET", "/api/v2/agent/inbox", BKH)
        return json.dumps(b)

    def b_resolve(inp):
        _, b = http("POST", "/api/v2/agent/resolve", BKH,
                    {"request_id": inp["request_id"], "decision": inp["decision"],
                     "note": inp.get("note", "")})
        return json.dumps(b)

    B_TOOLS = [
        {"name": "check_inbox", "description": "List pending access requests from other agents into the data domain you own.",
         "input_schema": {"type": "object", "properties": {}}},
        {"name": "resolve_request", "description": "Approve or deny a pending request into your domain.",
         "input_schema": {"type": "object", "properties": {
             "request_id": {"type": "integer"},
             "decision": {"type": "string", "enum": ["approve", "deny"]},
             "note": {"type": "string"}}, "required": ["request_id", "decision"]}},
    ]
    B_SYS = (f"You are '{B_NAME}', the AI agent that OWNS and governs the Postgres customer-CRM "
             "database. Other agents may request access into your domain; you are the broker. "
             "Policy: GRANT read-only (GET) access to a specific table when an internal agent needs it "
             "for a legitimate reporting/analytics task; DENY write access, and DENY anything outside the "
             "customer CRM. Review every pending request in your inbox and resolve it. Narrate your reasoning "
             "in one short sentence per request.")

    def run_broker():
        print(col("\n  ── routing to the domain owner ──", "mag"))
        print(col(f"  ┌─ AGENT B · {B_NAME} (owns the Postgres CRM domain) is reviewing the request\n", "mag"))
        run_loop(client, B_NAME, "mag", B_SYS,
                 "You have access request(s) waiting. Review your inbox and decide each one per your policy.",
                 B_TOOLS, {"check_inbox": b_inbox, "resolve_request": b_resolve})
        print(col("  └─ back to AGENT A\n", "mag"))

    # ---- sales-agent (A) tools ----
    def a_discover(_):
        _, b = http("GET", "/api/v2/agent/catalog", AKH)
        return json.dumps(b)

    def a_query(inp):
        svc = inp.get("service", MYSQL)
        if inp.get("id") is not None:
            path, qs = f"/api/v2/{svc}/_table/{inp['table']}/{inp['id']}", ""
        else:
            path = f"/api/v2/{svc}/_table/{inp['table']}"
            qs = f"?limit={int(inp.get('limit', 50))}"
            if inp.get("filter"):
                qs += "&filter=" + urllib.request.quote(inp["filter"])
        s, b = http("GET", path + qs, AKH)
        if s != 200:
            return json.dumps({"http_status": s, "error": "Access denied — your role does not permit reading that service/table."})
        return json.dumps({"http_status": 200, "result": b})

    def a_request_access(inp):
        s, b = http("POST", "/api/v2/agent/request_access", AKH,
                    {"services": inp.get("services", []), "operations": inp.get("operations", ["GET"]),
                     "note": inp.get("note", "")})
        if s != 200:
            return json.dumps({"http_status": s, "error": "request_access failed", "body": b})
        rid = b["request_id"]
        print(col(f"\n   🔔 pending request #{rid}: {A_NAME} → {','.join(inp.get('operations', ['GET']))} "
                  f"on {','.join(inp.get('services', []))}", "yellow"))
        run_broker()                                   # crm-agent decides, autonomously
        _, r = http("GET", f"/api/v2/agents/requests/{rid}?fields=status", AH)
        st = (r or {}).get("status")
        if st == "approved":
            return json.dumps({"status": "approved",
                               "message": "The domain owner granted your request. Retry the read — it will now succeed with your key."})
        return json.dumps({"status": st or "pending",
                           "message": "The domain owner did not grant access. Explain that you are blocked."})

    A_TOOLS = [
        {"name": "discover_services", "description": "Discover which services/tables/operations YOUR role permits.",
         "input_schema": {"type": "object", "properties": {}}},
        {"name": "query_records", "description": "Read records from a table. 'service' selects the database (e.g. test_mysql or test_pgsql).",
         "input_schema": {"type": "object", "properties": {
             "service": {"type": "string"}, "table": {"type": "string"},
             "id": {"type": ["integer", "string"]}, "filter": {"type": "string"}, "limit": {"type": "integer"}},
             "required": ["table"]}},
        {"name": "request_access", "description": "Request access you lack. Creates a pending request that is BROKERED by the agent that owns the target domain; blocks until it decides. Provide service targets like 'test_pgsql/_table/customers'.",
         "input_schema": {"type": "object", "properties": {
             "services": {"type": "array", "items": {"type": "string"}},
             "operations": {"type": "array", "items": {"type": "string"}},
             "note": {"type": "string"}}, "required": ["services", "operations"]}},
    ]
    A_SYS = (f"You are '{A_NAME}', an autonomous AI agent with your own scoped API key. You can read MySQL "
             f"order data. The databases available on this platform include '{MYSQL}' (orders) and '{PG}' "
             "(customer CRM, including each customer's city). FIRST call discover_services to learn your "
             "boundaries. To build the report you will need order totals (MySQL) AND each customer's city "
             "(Postgres). If a read is denied, do NOT give up: call request_access for exactly the service/table "
             "and operation you need (e.g. service target 'test_pgsql/_table/customers', operation GET), with a "
             "short business reason — the agent that owns that domain will broker it. Once granted, retry. "
             "When you have both datasets, JOIN them by customer id and produce the final report. Narrate each "
             "step in one short sentence.")
    task = ("Produce a 'total order value by customer city' report. Order totals are in the MySQL orders table "
            "(each order has a customer_id and a total); the customer→city mapping is in the Postgres customers "
            "table. Join across both and give me the total order value grouped by city.")

    print(col("  ┌─ AGENT A · " + A_NAME + " (MySQL orders)", "cyan"))
    print(col("  ── TASK ", "bold") + col(task, "cyan") + "\n")
    run_loop(client, A_NAME, "cyan", A_SYS, task, A_TOOLS,
             {"discover_services": a_discover, "query_records": a_query, "request_access": a_request_access})
    print(col("\n  ✔ Two agents, two databases, one governed cross-domain request — brokered agent-to-agent.\n", "green"))


if __name__ == "__main__":
    main()
