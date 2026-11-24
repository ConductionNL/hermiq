# SPDX-License-Identifier: EUPL-1.2
# Copyright (C) 2026 Conduction B.V.
#
# The installer's egress proxy (tools-agent-workspace-and-installed-tools D4):
# an HTTPS CONNECT proxy that tunnels only to the exact hosts on
# EGRESS_ALLOWED_HOSTS and only on EGRESS_ALLOWED_PORTS. It is the sandbox
# image's src/egressAllowlistProxy.js, run as the unprivileged sandbox uid.
# Built separately so the installer never shares a proxy with the browser.
FROM ghcr.io/conductionnl/hermiq-code-sandbox:latest
USER 10000:10000
EXPOSE 3128
CMD ["node", "src/egressAllowlistProxy.js"]
