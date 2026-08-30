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
# Requires the container to be granted NET_ADMIN *and started as UID 0*:
#   docker run --cap-add=NET_ADMIN --user 0:0 \
#              --entrypoint /app/deploy/egress-entrypoint.sh ...
# or the equivalent compose `cap_add: [NET_ADMIN]` + `user: "0:0"` + `entrypoint:`.
#
# ⚠️ `--user 0:0` IS NOT OPTIONAL and is easy to miss: the image sets `USER node`,
# so without it this script runs unprivileged, `iptables` fails with
# "Permission denied (you must be root)", and the container EXITS. NET_ADMIN
# alone does not help — the capability is not in an unprivileged user's
# permitted set. Measured 2026-08-01: with NET_ADMIN but no `--user 0:0` the
# container died on its first iptables call; with both, the jail installed, the
# forbidden host was blocked and the allowed host connected. Dropping back to
# `node` still happens, at the end, via gosu — root is held only long enough to
# write the rules.
#
# ⚠️⚠️ DO NOT USE THIS JAIL FOR A CDN-FRONTED HOST (github.com, and most SaaS
# APIs). It resolves each allowed host ONCE, at boot, and pins the resulting
# IPs. A host behind a rotating pool then works only when DNS happens to hand
# back a pinned address; every other attempt hangs until the TCP timeout.
#
# Measured 2026-08-01 against github.com, which rotates 140.82.121.3/.4/.9:
# three identical `git clone` runs through this jail gave
#   run 1: FAILED  Failed to connect to github.com port 443 after 134815 ms
#   run 2: FAILED  Failed to connect to github.com port 443 after 135073 ms
#   run 3: OK      (36s)
# — a 2-in-3 failure rate, each costing 135 SECONDS, and the failure looks like
# a flaky network rather than a policy decision. That is worse than no fence:
# it is a control that intermittently breaks legitimate traffic while teaching
# operators to distrust the error.
#
# For anything CDN-fronted use the GOVERNED CONNECT PROXY instead
# (deploy/egress-proxy). It resolves per connection and asks Hermiq's PDP by
# HOSTNAME, so rotation cannot defeat it, and a denial is an immediate, legible
# policy answer rather than a timeout. This jail is the fallback for fixed-IP
# destinations and for environments where the proxy cannot run.
#
# If you cannot grant NET_ADMIN, enforce egress at the network layer instead
# (see deploy/docker-compose.yml + deploy/egress-allowlist.md) and use the
# image's default unprivileged CMD.

set -euo pipefail

# Fail LEGIBLY rather than on a raw iptables error. Without this the operator
# sees "iptables v1.8.9 (nf_tables): Could not fetch rule set generation id:
# Permission denied" and a container that exited — which reads like a broken
# image rather than a missing flag, and buries the one-word fix.
if [ "$(id -u)" -ne 0 ]; then
    echo "[egress-jail] FATAL: this entrypoint must start as root (uid 0) so it can install" >&2
    echo "[egress-jail]        the iptables allowlist; it drops to the 'node' user afterwards." >&2
    echo "[egress-jail]        Running as uid $(id -u). Add --user 0:0 (compose: user: \"0:0\")" >&2
    echo "[egress-jail]        alongside --cap-add=NET_ADMIN. Refusing to start UNFENCED." >&2
    exit 1
fi

if ! iptables -L OUTPUT -n >/dev/null 2>&1; then
    echo "[egress-jail] FATAL: cannot manage iptables. Add --cap-add=NET_ADMIN." >&2
    echo "[egress-jail]        Refusing to start UNFENCED." >&2
    exit 1
fi

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
