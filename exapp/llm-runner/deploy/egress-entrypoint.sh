#!/bin/bash
# SPDX-License-Identifier: EUPL-1.2
# Copyright 2026 Conduction B.V.
#
# Optional in-container egress jail for hermiq-llm-runner (defense-in-depth).
#
# Starts as ROOT, installs an iptables allowlist that DROPs all outbound traffic
# except DNS + the provider API hosts on 443, then drops privileges and execs
# the runner as the unprivileged `node` user (Hydra's builder entrypoint uses
# the same root-set-iptables-then-drop pattern).
#
# Requires the container to be granted NET_ADMIN:
#   docker run --cap-add=NET_ADMIN --entrypoint /app/deploy/egress-entrypoint.sh ...
# or the equivalent compose `cap_add: [NET_ADMIN]` + `entrypoint:` override.
#
# If you cannot grant NET_ADMIN, enforce egress at the network layer instead
# (see deploy/docker-compose.yml + deploy/egress-allowlist.md) and use the
# image's default unprivileged CMD.

set -euo pipefail

# Provider API hosts the runner is permitted to reach. Keep in sync with the
# apiHost values in src/providers.js. Override with a space-separated list in
# RUNNER_ALLOWED_HOSTS to add/remove providers.
ALLOWED_HOSTS="${RUNNER_ALLOWED_HOSTS:-api.anthropic.com api.openai.com api.x.ai}"

echo "[egress-jail] installing iptables allowlist for: ${ALLOWED_HOSTS}"

# Default-deny outbound; allow loopback, DNS, and established/related first.
iptables -F OUTPUT
iptables -P OUTPUT DROP
iptables -A OUTPUT -o lo -j ACCEPT
iptables -A OUTPUT -m state --state ESTABLISHED,RELATED -j ACCEPT
iptables -A OUTPUT -p udp --dport 53 -j ACCEPT
iptables -A OUTPUT -p tcp --dport 53 -j ACCEPT

# Allow each allowlisted entry (resolved at start-up). DNS may return multiple A
# records; allow each.
#
# An entry is `host` (defaults to 443, the provider APIs) or `host:port`. The
# port suffix exists because the governed Hermiq origins are NOT necessarily
# HTTPS: a Nextcloud reachable from this container over plain HTTP (`nextcloud:80`
# on the container network) is the normal dev shape, and hardcoding 443 silently
# blackholed it — the CLI's MCP calls just timed out, which reads like a broken
# endpoint rather than a blocked port. Default-DROP still applies to everything
# else, so this widens nothing beyond the named entry.
for entry in ${ALLOWED_HOSTS}; do
    case "${entry}" in
        *:*)
            host="${entry%:*}"
            port="${entry##*:}"
            ;;
        *)
            host="${entry}"
            port="443"
            ;;
    esac

    ips="$(getent ahostsv4 "${host}" | awk '{print $1}' | sort -u || true)"
    if [ -z "${ips}" ]; then
        echo "[egress-jail] WARNING: could not resolve ${host} — its egress will be blocked"
        continue
    fi
    for ip in ${ips}; do
        iptables -A OUTPUT -p tcp -d "${ip}" --dport "${port}" -j ACCEPT
        echo "[egress-jail] allow ${port} -> ${host} (${ip})"
    done
done

echo "[egress-jail] allowlist active; dropping to non-root 'node' user"
exec gosu node node /app/src/server.js
