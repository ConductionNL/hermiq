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
# - jq: required by hydra's gate runner (run-hydra-gates.sh) — see the PHP
#   toolchain block below for why this image now carries a stage toolchain.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        iptables \
        gosu \
        jq \
    && rm -rf /var/lib/apt/lists/*

# --- PHP toolchain for agent-run pipeline stages -------------------------------
# hydra's flows-first port (hydra openspec/changes/hydra-flows-first-port,
# decisions D5/D6) makes EVERY pipeline stage a `hermiq.agent-step` instead of a
# hydra-owned container. That only works if the agent's workload can run the
# quality gates, and `run-hydra-gates.sh` shells out to `composer check:strict`
# (PHPCS, PHPMD, Psalm, PHPStan) for PHP targets.
#
# Measured 2026-07-30, this image had claude/git/node/npm/python3/iptables/gosu
# and was missing exactly php, composer and jq — so the Python `check_*.py`
# gates would have run and every PHP gate would have failed. That gap was the
# whole distance between the port's design and its gates.
#
# PHP 8.3, NOT bookworm's default 8.2. The Conduction fleet's composer.json
# requires "php": "^8.3"; on 8.2 `composer install` aborts on the platform check
# and no gate can pass. Mirrors images/builder/Dockerfile in hydra deliberately:
# a stage that runs here must behave the same as one that ran in hydra's own
# container, and a version skew between the two would show up as gates that pass
# in one place and fail in the other.
#
# The extension set matches hydra's builder for the same parity reason. The
# postgresql SERVER packages are not copied — those exist there for the prebuilt
# Nextcloud test environment, which an agent-step does not carry.
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
    && mkdir -p /etc/apt/keyrings \
    && curl -fsSL https://packages.sury.org/php/apt.gpg -o /etc/apt/keyrings/sury-php.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/sury-php.gpg] https://packages.sury.org/php bookworm main" \
        > /etc/apt/sources.list.d/sury-php.list \
    && apt-get update && apt-get install -y --no-install-recommends \
        php8.3-cli \
        php8.3-xml \
        php8.3-mbstring \
        php8.3-curl \
        php8.3-zip \
        php8.3-intl \
        php8.3-bcmath \
        php8.3-sqlite3 \
        php8.3-pgsql \
        php8.3-gd \
    && rm -rf /var/lib/apt/lists/* \
    && php -v | head -1

# --- Composer -----------------------------------------------------------------
RUN curl -sS https://getcomposer.org/installer \
    | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer --version

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
# that the CMD comment below selects via
# `--entrypoint /app/deploy/egress-entrypoint.sh`. Without it in the image that
# override cannot resolve — the container dies at start with
# `stat /app/deploy/egress-entrypoint.sh: no such file or directory`. The
# unprivileged default CMD keeps working, so this only ever breaks for whoever
# opts INTO the hardening the docs point at, which is why it went unnoticed.
COPY deploy/ ./deploy/

# --- hydra's gate package -------------------------------------------------------
# The image already carries jq, php and composer BECAUSE a stage runs
# `scripts/run-hydra-gates.sh` — and that script cannot run without the
# `conduction/hydra-gates` package, which lived nowhere in this image.
#
# `resolve_hydra_gates()` searches four locations and the SECOND is
# `/usr/local/lib/hydra-gates/hydra-gates`, commented in that script as the
# "container image layout". This is that layout; until now the candidate
# pointed at nothing.
#
# Measured 2026-08-12 on a live stage: the sequencer reached `run-stage`,
# cloned the repo and the hydra tool tree, ran the command, and got
#
#     [hydra-gates] FATAL: the conduction/hydra-gates package could not be located.
#     /stage finished exit=99
#
# 99 is that script's "the gates could not be run AT ALL" code, and it is
# deliberately not a failure count — a missing package is a configuration
# error and never degrades into a pass. So the whole pipeline worked and the
# stage could not check anything.
#
# Fetched at BUILD time, not at run time: the runtime filesystem is read-only
# and the workload command is allowlisted to `scripts/run-hydra-gates.sh`, so a
# stage cannot fetch this for itself — correctly, since a workload that could
# install its own gate runner could install any gate runner.
ARG HYDRA_GATES_REF=main
RUN git clone --depth 1 --branch "${HYDRA_GATES_REF}" \
        https://github.com/ConductionNL/.github /tmp/conduction-github \
    && mkdir -p /usr/local/lib/hydra-gates \
    && cp -a /tmp/conduction-github/hydra-gates /usr/local/lib/hydra-gates/hydra-gates \
    && rm -rf /tmp/conduction-github \
    # A path that exists is not the same claim as "the gates are there" — the
    # resolver only counts a candidate that carries the runner script, so the
    # build fails here rather than shipping an image that exits 99 in production.
    && test -f /usr/local/lib/hydra-gates/hydra-gates/scripts/run-hydra-gates.sh

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
