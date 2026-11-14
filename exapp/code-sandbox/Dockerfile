# SPDX-License-Identifier: EUPL-1.2
# Copyright (C) 2026 Conduction B.V.
#
# hermiq-code-sandbox — runs short Python and JavaScript programs for Hermiq
# (tools-code-sandbox D2). The image pins its languages and packages; nothing
# is installed at run time, and the container has no network route out
# (deploy/docker-compose.yml).
#
# Two users: `sandbox` (uid 10000) runs the server, `runner` (uid 10001) runs each
# program, so a program cannot signal or read the server.

FROM node:20.18.1-bookworm-slim

RUN apt-get update \
	&& apt-get install -y --no-install-recommends python3 python3-pip python3-venv util-linux \
	&& rm -rf /var/lib/apt/lists/*

# Python with the standard library and a fixed set of packages.
RUN python3 -m venv /opt/sandbox-python \
	&& /opt/sandbox-python/bin/pip install --no-cache-dir \
		numpy==2.1.3 \
		pandas==2.2.3 \
		python-dateutil==2.9.0.post0 \
	&& ln -s /opt/sandbox-python/bin/python3 /usr/local/bin/python3

RUN groupadd --gid 10000 sandbox && useradd --uid 10000 --gid 10000 --home /app sandbox \
	&& groupadd --gid 10001 runner && useradd --uid 10001 --gid 10001 --no-create-home --shell /usr/sbin/nologin runner

WORKDIR /app
COPY package.json ./
COPY src ./src
COPY test ./test

ENV NODE_ENV=production \
	SANDBOX_WORK_ROOT=/work \
	SANDBOX_RUN_UID=10001 \
	SANDBOX_IN_CONTAINER=1 \
	SANDBOX_PYTHON=/usr/local/bin/python3

# The server needs to switch to the runner's uid for each run, and nothing else.
USER root
RUN mkdir -p /work && chown sandbox:sandbox /work && chmod 0711 /work

EXPOSE 9000
CMD ["setpriv", "--reuid=sandbox", "--regid=sandbox", "--init-groups", "--inh-caps=-all,+setuid,+setgid", "--ambient-caps=+setuid,+setgid", "--", "node", "src/server.js"]
