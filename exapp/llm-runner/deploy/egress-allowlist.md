# Egress allowlist — hermiq-llm-runner

The runner must reach **only** the LLM provider API hosts. Everything else —
Nextcloud, OpenRegister, the host filesystem, the general internet — is denied.

## Allowlisted hosts (443/tcp only)

| Provider  | Host               |
|-----------|--------------------|
| Anthropic | `api.anthropic.com` |
| OpenAI    | `api.openai.com`    |
| Grok/xAI  | `api.x.ai`          |

DNS (53/udp+tcp) and loopback are allowed so the hosts can be resolved and the
health probe can hit `127.0.0.1`. Nothing else is permitted outbound.

Keep this list in sync with the `apiHost` values in `src/providers.js` and the
`RUNNER_ALLOWED_HOSTS` default in `deploy/egress-entrypoint.sh`.

## How the deployer enforces it

Pick ONE (see `deploy/docker-compose.yml`):

### Option A — in-container iptables jail (defense-in-depth)
`deploy/egress-entrypoint.sh` starts as root, sets `iptables -P OUTPUT DROP`,
allows DNS + loopback + established, then allows 443 to each resolved provider
IP, and finally `exec gosu node` — the runner process itself is unprivileged.
Requires `--cap-add=NET_ADMIN`. This is what the `egress-allowlist` test
exercises (it asserts the generated ruleset denies a non-allowlisted host).

### Option B — network-layer enforcement (no NET_ADMIN)
Attach the runner to an `internal: true` Docker network (no gateway → no
default internet route) and route provider traffic through an explicit egress
proxy (Squid/tinyproxy/cloud egress firewall) whose allowlist is exactly the
hosts above. The container never has a path to any other host.

## What is NOT mounted

The runner declares **no** volumes. It cannot read Nextcloud user files, the
OpenRegister object store, or any host path. Its only writable surface is a
per-call temp scratch dir (`tmpfs`), wiped when the process exits.
