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
| No run token presented | **407 challenge** — no tunnel, PDP not consulted | `no_run_token` |
| `EGRESS_PDP_URL` unset | proxy refuses to **start** | — |

That last row matters: a proxy with no policy that still forwards traffic is an
open relay, so it exits at boot rather than run without a PDP.

### Why the no-token answer is a 407 and not a 403

⚠️ **It was a 403, and that made the proxy unusable by `git` — invisibly.**

`HTTPS_PROXY=http://run:<token>@egress-proxy:3128` does not make every client
present the credential:

- **curl's CLI** defaults to Basic and sends `Proxy-Authorization` *preemptively*.
- **git** sets libcurl's proxy auth to `CURLAUTH_ANY`, which waits for a **407
  challenge** before sending anything.

So a flat 403 told git there was nothing to offer, and it never offered the token
it already held. Measured 2026-08-02 inside the jailed container, same proxy,
same URL:

```
curl --proxy http://run:tok@egress-proxy:3128 https://github.com/
    => Proxy-Authorization sent   => 200 Connection Established
git  HTTPS_PROXY=http://run:tok@egress-proxy:3128 ls-remote https://github.com/…
    => no Proxy-Authorization     => 403 no_run_token, every time
```

The proxy now answers `407 Proxy Authentication Required` with
`Proxy-Authenticate: Basic realm="hermiq-egress"` when **no** credential is
presented, and keeps the 403 for a credential that IS presented and refused by
policy — retrying that cannot help, and challenging again would loop. A 407
opens no tunnel, so default-deny is unchanged.

After the change, six consecutive clones of a public repository through the
proxy: 6/6 OK, 855–1123 ms. (The iptables jail it replaces gave 2-in-3 failures
at ~135 s each.)

### Four things an operator must get right, or every stage is denied

**0. An EMPTY allowlist is not a closed door — it is an open one.**
`WebResearchEgressGuard::rejectionForAllowDenyLists()` only applies the list when
it is non-empty (`if ($allowlist !== [] && ...)`), so an instance that has never
configured `fetchAllowlist` allows **every** resolvable public host. Measured
2026-08-02 against a live PDP with no allowlist set: `github.com`,
`api.github.com`, `codeload.github.com`, `objects.githubusercontent.com`,
`raw.githubusercontent.com` and `codeberg.org` all returned `allowed: true` —
and so would anything else, because the only refusal in that run was
`evil.example.com` failing **DNS resolution**, not policy.

That matters more than it looks: `allowed: true` for the forge is **not**
evidence that the forge is allowlisted. Verify the fence with a host that
resolves and should be refused (`gitlab.com`), never with one that does not
resolve. Set the list explicitly before relying on it.

⚠️ The list is a single **global** app setting shared with the agent's
`web.fetch` tool. "Allow the forge host only" therefore also restricts every
agent's web research on that instance; there is currently no way to express a
narrower allowlist for the CONNECT proxy alone.

**1. The allowlist is EXACT hostnames — no wildcards, no subdomains.**
`WebResearchEgressGuard::matchesHostList()` is case-insensitive string equality.
Measured through the proxy: with `github.com` allowlisted, `api.github.com` is
**denied** (`not_allowlisted`). Allow every host the workload actually touches —
`github.com` for a clone or push, and `codeload.github.com` too if anything
fetches a tarball.

**2. The run-token store must be shared with the process that answers the PDP.**
`RunTokenService` keeps tokens in `ICacheFactory::createDistributed()`. With no
`memcache.distributed` configured this falls back to the local cache — APCu on a
default install — which is **per process pool**. A token minted in a CLI process
(cron-mode background jobs, `occ`) is then invalid at the web PDP.

Measured on a live instance: a token minted via CLI and POSTed within the same
second to `/apps/hermiq/api/egress/authorize` came back **401 `invalid_token`**.

So on an instance running background jobs in `cron` mode — which is what
Nextcloud recommends — every flow-dispatched stage would be denied egress, and
the symptom is a `git clone` failure, not a policy error. **Configure
`memcache.distributed` (Redis/Memcached) before putting the sidecar behind the
proxy.**

⚠️ `ICacheFactory::isAvailable()` returns **true** in the fallback case, so
nothing in the instance reports that the distributed store is missing. The only
reliable check is to read the class back:
`createDistributed()` handing you `OC\Memcache\APCu` is the fault.

**3. The scratch tmpfs must be mounted `exec`. Docker's default is `noexec`.**
The scratch tree is executed from twice — git runs the `GIT_ASKPASS` helper
written into it, and the stage's command child is a script in the cloned tree.
Measured on one image with only the mount options varying:

| `/tmp` options | `GIT_ASKPASS` | command child |
|---|---|---|
| `rw,nosuid,nodev,noexec` (Docker default) | `fatal: cannot exec '…/askpass.sh': Permission denied` → `could not read Username …: terminal prompts disabled` | `EACCES` |
| `rw,exec,nosuid,nodev,size=2g` | credential reaches the forge | runs |

So the hardened posture as first shipped dropped the credential on the floor and
could not run a gate suite at all, while every `docker inspect` assertion about
it still passed. The default 64 M size is also too small for a repository clone.

### The live container is not the compose container

`docker inspect` on the *compose* stack proves nothing about a sidecar deployed
another way. An ExApp registered through AppAPI's `manual_install` deploy
daemon — which is how the shared dev instance runs it — carries only
`appid`/`port`/`secret` in `oc_ex_apps`; **AppAPI does not own the container
lifecycle**, and a plain `docker run` gives you `CapDrop=[]`, a writable root and
whatever network the operator picked.

Measured 2026-08-02, the live `hermiq-llm-runner` had exactly that. `cap_drop`,
`read_only`, tmpfs and network attachment are all **create-time** flags, so there
is no `docker update` remedy: the container must be **recreated**. Because the
daemon is `manual_install`, recreation is safe — keep the name, the port
(`RUNNER_PORT`, `oc_ex_apps.port`), the `APP_SECRET` and a network the Nextcloud
container can resolve, and AppAPI keeps dispatching to it unchanged.

Assert the result on the RUNNING container, and from inside it:

```
docker inspect <name> --format '{{json .HostConfig.CapDrop}} {{.HostConfig.ReadonlyRootfs}}'
docker exec <name> sh -c 'grep ^CapEff /proc/1/status; touch /nope; touch /tmp/ok && echo tmp-writable'
```

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
