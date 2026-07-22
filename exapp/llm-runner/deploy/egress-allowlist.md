# Governed egress — hermiq-llm-runner

The runner container has **no route to the internet**. Its only way out is the
`egress-proxy` sidecar, which asks Hermiq's Policy Decision Point (PDP) about
**every single connection** before opening it.

> **Migration notice (operator-visible).** Earlier revisions offered two
> interchangeable options and recommended **Option A**, an in-container
> `iptables` jail with a static host allowlist (`RUNNER_ALLOWED_HOSTS`). It is
> **replaced** by the governed proxy below, which is now the required posture.
> If you deployed Option A, see [Migrating off the iptables jail](#migrating-off-the-iptables-jail).
>
> **Why:** the jail carried its own copy of the allowlist. A second copy is a
> second policy — it drifts from the one the agent's `webFetch` tool obeys, and
> `iptables` cannot express *"may **this run** reach that host?"* because it has
> no idea what a run is. `egress-entrypoint.sh` is retained only to support the
> migration and is no longer part of the recommended posture.

## The model

Two complementary layers, **one** policy source:

| Layer | Governs | Enforced by | Asks |
|---|---|---|---|
| Per-agent authorization | what the **agent** may *do* | Hermiq's governed MCP endpoint | is this tool granted to this agent? |
| Network backstop | what the **container** may *reach* | this proxy + `internal: true` | may this run open this connection? |

They are not redundant. The MCP grant cannot stop traffic the agent never asked
for: a CLI auto-update check, a built-in web fetch that a future flag fails to
disable, a compromised dependency. The backstop cannot tell a legitimate tool
call from an exfiltration attempt to an allowed host. You want both.

Both resolve their answer from **`WebResearchEgressGuard`** — the same
allowlist/denylist the `webFetch` tool obeys. If you find yourself writing a
second allowlist anywhere, stop.

## What the container may reach

Exactly three origins, and nothing else:

| Origin | Why |
|---|---|
| `api.anthropic.com` | the provider the CLI talks to |
| Hermiq's **tools** origin | the governed MCP endpoint (`/apps/hermiq/api/mcp/run`) |
| Hermiq's **egress** origin | the PDP itself (`/apps/hermiq/api/egress/authorize`) |

Configure these in Hermiq's web-research allowlist (Admin → Hermiq); the PDP
reads them from there. Every other host is denied at the network layer.

## How a connection is authorized

1. Hermiq mints a per-run token and sends it with the turn — on **every** turn,
   because a text-only turn needs egress to the provider just as much as a
   governed one.
2. The runner builds `HTTPS_PROXY=http://run:<token>@egress-proxy:3128` in the
   CLI's **environment** — never on argv, where the process table would expose
   it. `NO_PROXY` is deliberately never set: an exemption list would be a hole
   in the only route out.
3. The CLI issues `CONNECT host:443`; the proxy reads the token from
   `Proxy-Authorization` and asks the PDP: `POST {host, port}`, token as bearer.
4. `allowed: true` is the **only** permit signal. Then the tunnel opens.
5. When the run closes its token is consumed, so both capabilities — tools and
   egress — die together.

### Fail-closed

An egress proxy that fails open is not a control. Every failure path denies, and
each is covered by an explicit test in `test/egress.proxy.test.js`:

| Situation | Result | Deny code |
|---|---|---|
| PDP unreachable | DENY | `pdp_unreachable` |
| PDP times out | DENY | `pdp_timeout` |
| PDP returns 5xx/4xx | DENY | `pdp_rejected` |
| PDP answer isn't JSON | DENY | `pdp_unparseable` |
| `allowed` truthy but not `true` (e.g. `"yes"`) | DENY | `denied` |
| No run token presented | DENY — PDP not even consulted | `no_run_token` |
| `EGRESS_PDP_URL` unset | proxy refuses to **start** | — |

That last row matters: a proxy with no policy that still forwards traffic is an
open relay, so it exits at boot rather than run without a PDP.

### Known limitation: CONNECT is host-granular

`CONNECT` gives `host:port`, not a URL. The PDP therefore decides on the host,
and **any path on an allowed host is reachable**. Narrowing that would mean
terminating TLS inside the jail — a MITM with the CLI's credentials in scope —
a worse trade than the granularity it buys. The per-agent MCP grant is the layer
that constrains *what* is done at an allowed origin.

## Hardening posture (what the deployment guarantees)

- **Non-root**, `cap_drop: [ALL]`, `no-new-privileges`, `read_only` filesystem
  with `tmpfs` scratch.
- **No host or user mounts** — the runner sees no Nextcloud data, no host paths.
- **No default route** (`internal: true`); the proxy is the only egress.
- **Credentials per call, env-only** — never argv, never logged, never
  persisted; the scratch dir is removed on every exit path.
- **Two token-gated Hermiq origins**, both requiring the per-run bearer token.

## Claude Max is PERSONAL SCOPE ONLY

A Claude Max subscription may be used **only** with the subscriber's own personal
token, from their own personal settings, for their own turns. Do not configure a
Max credential at organisation scope, do not share one between users, and do not
point a shared or service agent at one. This is an Anthropic Terms of Service
constraint, not a Hermiq limitation. For shared or automated use, configure an
API-key credential instead.

## Migrating off the iptables jail

1. Remove the `entrypoint: ["/app/deploy/egress-entrypoint.sh"]` override,
   `user: root`, and the `NET_ADMIN`/`NET_RAW` capabilities from the runner
   service. The governed posture needs none of them.
2. Drop `RUNNER_ALLOWED_HOSTS` — the allowlist now lives in Hermiq's
   web-research settings, read by the PDP.
3. Add the `egress-proxy` service plus the `jailed` (`internal: true`) and
   `egress` networks, and set `EGRESS_PROXY_AUTHORITY` and `EGRESS_PDP_URL`
   (see `docker-compose.yml`).
4. Verify: a turn succeeds, **and**
   `docker exec <runner> wget -qO- https://example.com` fails. If it succeeds the
   runner still has a default route — check it is attached to `jailed` **only**.

## What is NOT mounted

The runner declares **no** volumes. It cannot read Nextcloud user files, the
OpenRegister object store, or any host path. Its only writable surface is a
per-call temp scratch dir (`tmpfs`), wiped when the process exits.
