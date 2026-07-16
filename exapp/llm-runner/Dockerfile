# hermiq-llm-runner — hardened LLM-CLI-runner ExApp sidecar
#
# Runs the vendor LLM CLIs (claude, OpenAI Codex, and — when available — a Grok
# CLI) as a stateless HTTP transport for Hermiq. Modeled on Hydra's hardened
# runner image (images/builder/Dockerfile): CLIs installed via npm, a non-root
# runtime user, and (optionally) an iptables egress allowlist applied by the
# root entrypoint before dropping privileges.
#
# SPDX-License-Identifier: EUPL-1.2
# Copyright 2026 Conduction B.V.

# Pin by digest in production (PA3 — a floating tag lets a poisoned upstream
# rebuild propagate on the next CI run). node:22-bookworm gives a Debian base
# with a stable glibc for the vendor CLIs (Codex is a native binary). Use the
# `-slim` variant in CI for a leaner image; the full tag is used here because it
# is what the build host has cached.
FROM node:22-bookworm

# --- System tooling -----------------------------------------------------------
# - iptables + gosu: only needed by the OPTIONAL in-container egress-jail
#   entrypoint (deploy/egress-entrypoint.sh), which starts as root, installs the
#   allowlist, then drops to the non-root user. The default CMD does NOT use
#   them and runs entirely unprivileged.
# - ca-certificates: TLS trust for the provider API hosts.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        iptables \
        gosu \
    && rm -rf /var/lib/apt/lists/*

# --- Vendor LLM CLIs ----------------------------------------------------------
# @anthropic-ai/claude-code is the supported distribution path for the `claude`
# CLI (same install Hydra uses). @openai/codex is the OpenAI Codex CLI; its
# non-interactive mode is `codex exec`.
#
# Grok: xAI ships no verified official CLI on npm at the time of writing, so it
# is a documented PLACEHOLDER — installing a guessed package would be wrong.
# A deployer with a Grok CLI mounts/installs it and sets RUNNER_GROK_BIN. See
# README.md "Providers" and providers.js.
RUN npm install -g --no-fund --no-audit \
        @anthropic-ai/claude-code \
        @openai/codex \
    && claude --version || true

# --- Non-root runtime user ----------------------------------------------------
# The node:22 image already ships a `node` user at UID 1000. We reuse it as the
# unprivileged runtime identity.
WORKDIR /app
COPY package.json ./
COPY src/ ./src/
# deploy/ carries the OPTIONAL in-container egress jail (egress-entrypoint.sh)
# that the CMD comment below and deploy/docker-compose.yml's Option A both
# select via `--entrypoint /app/deploy/egress-entrypoint.sh`. Without it in the
# image that override cannot resolve — the container dies at start with
# `stat /app/deploy/egress-entrypoint.sh: no such file or directory`. The
# unprivileged default CMD keeps working, so this only ever breaks for whoever
# opts INTO the hardening the README calls recommended.
COPY deploy/ ./deploy/

# Persistent-storage / scratch dir owned by the runtime user.
RUN mkdir -p /app/scratch && chmod +x /app/deploy/*.sh && chown -R node:node /app

ENV NODE_ENV=production \
    RUNNER_HOST=0.0.0.0 \
    RUNNER_PORT=9000 \
    APP_ID=hermiq-llm-runner

EXPOSE 9000

# Default: run UNPRIVILEGED. Egress hardening is applied at the deployment /
# network layer (see deploy/docker-compose.yml). For the optional in-container
# iptables jail, override the entrypoint with deploy/egress-entrypoint.sh and
# grant --cap-add=NET_ADMIN (that variant starts as root, installs the
# allowlist, then execs node as the `node` user via gosu).
USER node

HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD node -e "require('http').get('http://127.0.0.1:'+(process.env.RUNNER_PORT||9000)+'/heartbeat',r=>process.exit(r.statusCode===200?0:1)).on('error',()=>process.exit(1))"

CMD ["node", "src/server.js"]
