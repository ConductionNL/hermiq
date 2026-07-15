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

# Allow HTTPS to each allowlisted provider host (resolved at start-up). DNS may
# return multiple A records; allow each.
for host in ${ALLOWED_HOSTS}; do
    ips="$(getent ahostsv4 "${host}" | awk '{print $1}' | sort -u || true)"
    if [ -z "${ips}" ]; then
        echo "[egress-jail] WARNING: could not resolve ${host} — its egress will be blocked"
        continue
    fi
    for ip in ${ips}; do
        iptables -A OUTPUT -p tcp -d "${ip}" --dport 443 -j ACCEPT
        echo "[egress-jail] allow 443 -> ${host} (${ip})"
    done
done

echo "[egress-jail] allowlist active; dropping to non-root 'node' user"
exec gosu node node /app/src/server.js
