# hermiq-egress-proxy — the governed CONNECT proxy sidecar.
#
# Deliberately dependency-free: Node's stdlib is the whole implementation, so the
# component that every outbound connection passes through has no supply chain of
# its own to compromise.
#
# SPDX-License-Identifier: EUPL-1.2
# Copyright 2026 Conduction B.V.

FROM node:22-alpine

WORKDIR /app

COPY proxy.js ./proxy.js

# Non-root: the proxy needs no privileges. It binds an unprivileged port and
# opens outbound sockets — both are fine as `node`.
USER node

EXPOSE 3128

CMD ["node", "/app/proxy.js"]
