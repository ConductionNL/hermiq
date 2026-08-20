#!/bin/sh
# hermiq-runner (combined) — start the speech server and the LLM CLI transport,
# and exit if EITHER of them dies.
#
# ⚠️ THE EXIT-ON-EITHER BEHAVIOUR IS THE POINT, not a nicety.
#
# The obvious `serverA & serverB & wait` makes PID 1 a shell that outlives both
# children. The container then stays "up" — and, because /health only probes one
# port, stays "healthy" — while half the sidecar is dead. That is the failure
# mode nobody notices for a week: agents keep working, dictation silently stops,
# and the restart policy never fires because nothing ever exited.
#
# `wait -n` returns as soon as the FIRST child exits, so we propagate that and
# let the orchestrator restart the whole thing.
#
# SPDX-License-Identifier: EUPL-1.2
# Copyright 2026 Conduction B.V.

set -eu

term() {
	# Forward a stop to both children so a `docker stop` is not a 10s SIGKILL wait.
	[ -n "${SPEECH_PID:-}" ] && kill -TERM "$SPEECH_PID" 2>/dev/null || true
	[ -n "${RUNNER_PID:-}" ] && kill -TERM "$RUNNER_PID" 2>/dev/null || true
	wait || true
	exit 0
}
trap term TERM INT

echo "[combined] starting speech server on :8000"
# ⚠️ THE `cd` IS LOAD-BEARING. speaches.main:create_app mounts
# StaticFiles(directory="realtime-console/dist") — a RELATIVE path — so starting
# it from anywhere else raises `Directory 'realtime-console/dist' does not exist`
# and the server dies at import time. Running from /app (the runner's WORKDIR)
# is exactly that mistake, and it presents as an immediate, confusing exit.
(cd /home/ubuntu/speaches && exec uvicorn --factory --host 0.0.0.0 --port 8000 speaches.main:create_app) &
SPEECH_PID=$!

echo "[combined] starting llm-cli transport on :9000"
node /app/src/server.js &
RUNNER_PID=$!

# `wait -n` needs a shell that supports it; the base image ships bash, but this
# script runs under /bin/sh. Poll instead so the behaviour does not depend on
# which /bin/sh the base image happens to link to — dash does not implement
# `wait -n`, and the failure would be silent (it would wait for BOTH).
while true; do
	if ! kill -0 "$SPEECH_PID" 2>/dev/null; then
		echo "[combined] speech server exited — bringing the container down" >&2
		kill -TERM "$RUNNER_PID" 2>/dev/null || true
		exit 1
	fi
	if ! kill -0 "$RUNNER_PID" 2>/dev/null; then
		echo "[combined] llm-cli transport exited — bringing the container down" >&2
		kill -TERM "$SPEECH_PID" 2>/dev/null || true
		exit 1
	fi
	sleep 5
done
