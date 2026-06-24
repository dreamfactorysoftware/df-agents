# Agent Access Negotiation — live agentic demos

Two scripts:

- **`agent_demo.py`** — single agent, agent-to-**human** negotiation (below).
- **`agent_demo2_multiagent.py`** — two agents across two databases, agent-to-**agent** negotiation (see "Demo 2" at the bottom). This is the headline NHI demo.

---

## Demo 1 — single agent, human approval

A **real Claude agent** (Opus 4.8) is given a job it isn't fully permitted to do.
Holding only its own short-lived, role-scoped DreamFactory API key, it:

1. **discovers** what its role allows (`discover_services`),
2. reads the order it needs,
3. tries to write → **blocked** (read-only role, HTTP 401),
4. calls **`request_access`** → a pending request + Slack alert fires,
5. **waits** while a human approves in the admin UI,
6. retries on the **same key** → succeeds; the order is marked refunded.

Every tool is wired to the live DreamFactory REST API. The agent never holds
admin credentials — it only ever uses its own scoped key.

## Run it

```bash
pip install -r requirements.txt          # anthropic SDK
export ANTHROPIC_API_KEY=sk-ant-...

# Human-in-the-loop (recommended for the recording):
python agent_demo.py
#   → when it prints "waiting for a human to APPROVE", go to the admin UI
#     (Agents → Pending access requests) and click Approve. The agent continues.

# Unattended (auto-approves, for a dry run):
AUTO_APPROVE=1 python agent_demo.py
```

The script **resets itself to the blocked state on every run** (forces the
agent's role back to read-only and resets the order), so it's repeatable.

## Config (env, defaults match the local dev stack)

| Var | Default |
|---|---|
| `DF_BASE_URL` | `http://localhost:8085` |
| `AGENT_ID` | `2` (sales-report-bot) |
| `DF_DB_SERVICE` | `test_mysql` |
| `TARGET_ORDER` | `1` |
| `DF_ADMIN_EMAIL` / `DF_ADMIN_PASSWORD` | `admin@dreamfactory.com` / `passwordpassword` |
| `AUTO_APPROVE` | unset (human approves in UI) |

> The admin login is used only for demo *setup* (fetch the agent's key, reset
> state, and — when `AUTO_APPROVE=1` — approve). The agent's actual work runs
> entirely on its own API key.

---

## Demo 2 — multi-agent, cross-database (agent-to-agent negotiation)

`agent_demo2_multiagent.py` — **two real Claude agents**, each scoped to a
different database, negotiating access between themselves:

- **sales-agent** → MySQL (`test_mysql` / orders)
- **crm-agent** → Postgres (`test_pgsql` / customers) — and it **owns/brokers**
  that domain.

The task given to sales-agent — *"total order value by customer city"* — needs
order totals (MySQL) joined with each customer's city (Postgres). sales-agent
has no Postgres access, so it requests it through AAN. The request is brokered
**not by a human but by crm-agent**, which reviews it against its own policy
(grant read-only to internal reporting agents; deny writes / out-of-domain) and
approves it. sales-agent then joins across both databases and produces the report.

```bash
export ANTHROPIC_API_KEY=sk-ant-...
python3 agent_demo2_multiagent.py
```

No human in the loop — the approval is an **agent** deciding for its own domain.
The script creates the two agents/roles if they don't exist and resets
sales-agent to MySQL-only each run, so it's repeatable. Only the domain owner can
broker a domain (`/api/v2/agent/resolve` is authorized against the broker's own
role), so crm-agent can approve Postgres requests but not MySQL ones.

**Why only DreamFactory can do this demo:** the single governed request spans two
different database engines because DreamFactory already fronts both with one
unified RBAC. The agents are the new developers; they get the same scoped,
least-privilege, audited access — granted by whoever owns the data domain.
