#!/usr/bin/env bash
#
# Give the llm-runner container a restart policy, and prove it has one.
#
# WHY THIS EXISTS
#
# The runner reaches an instance by one of two routes, and only one of them can
# declare a restart policy:
#
#   1. docker compose (deploy/docker-compose.yml) — declares `restart: always`.
#      Nothing to do; this script confirms it and exits.
#   2. AppAPI (appinfo/info.xml `<docker-install>`) — the ExApp manifest schema
#      exposes registry/image/image-tag and nothing else. There is NO element for
#      a restart policy, and the container is created by AppAPI's deploy daemon,
#      which lives in the `app_api` app, not here. A container that arrives this
#      way gets Docker's default, `RestartPolicy: no`.
#
# Route 2 is not hypothetical. Measured 2026-08-02: the live `hermiq-llm-runner`
# had `RestartPolicy: no` and no compose labels at all, and had been sitting
# Exited(255) for 13 hours. It is the host for every `hermiq.workload-step`, so
# the pipeline's entire execution plane was down — and down silently, because a
# step whose host is gone does not fail loudly, it just never runs.
#
# So: run this after any AppAPI deploy, and in any health check that wants to
# assert the runner will still be there after a reboot. It is idempotent and
# exits non-zero when it cannot make the guarantee, so it is usable as a gate.
#
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2

set -euo pipefail

CONTAINER="${1:-hermiq-llm-runner}"
WANT="${RESTART_POLICY:-always}"
CHECK_ONLY="${CHECK_ONLY:-0}"

if command -v docker >/dev/null 2>&1; then
    :
else
    echo "ensure-restart-policy: docker not on PATH" >&2
    exit 2
fi

if docker inspect "$CONTAINER" >/dev/null 2>&1; then
    :
else
    echo "ensure-restart-policy: no container named '$CONTAINER'." >&2
    echo "  Deploy it first (docker compose -f deploy/docker-compose.yml up -d," >&2
    echo "  or register the ExApp through AppAPI), then re-run." >&2
    exit 1
fi

current="$(docker inspect "$CONTAINER" --format '{{.HostConfig.RestartPolicy.Name}}')"
# Docker reports an unset policy as the empty string on some versions and as
# "no" on others. Both mean the same thing: it will not come back.
if [ -z "$current" ]; then
    current="no"
fi

if [ "$current" = "$WANT" ]; then
    echo "ensure-restart-policy: '$CONTAINER' already has RestartPolicy=$current"
    exit 0
fi

if [ "$CHECK_ONLY" = "1" ]; then
    echo "ensure-restart-policy: '$CONTAINER' has RestartPolicy=$current, wanted $WANT" >&2
    echo "  It will NOT come back after a crash or a reboot, and nothing will say so." >&2
    exit 1
fi

echo "ensure-restart-policy: '$CONTAINER' has RestartPolicy=$current — setting $WANT"
docker update --restart="$WANT" "$CONTAINER" >/dev/null

# Verify rather than trust. `docker update` reporting the container name is not
# proof the policy took.
after="$(docker inspect "$CONTAINER" --format '{{.HostConfig.RestartPolicy.Name}}')"
if [ "$after" != "$WANT" ]; then
    echo "ensure-restart-policy: FAILED — '$CONTAINER' still reports RestartPolicy=$after" >&2
    exit 1
fi

echo "ensure-restart-policy: '$CONTAINER' now has RestartPolicy=$after"
