# SPDX-License-Identifier: EUPL-1.2
# Copyright (C) 2026 Conduction B.V.
#
# The browser's egress proxy (tools-browser-and-computer-use D1) is the same
# policy enforcement point as the llm-runner's: one proxy, one PDP, one policy.
# It holds no allowlist of its own and denies every connection the PDP does not
# answer `allowed: true` for. Built separately so the browser and the runner
# never share a proxy instance or a network.
FROM ghcr.io/conductionnl/hermiq-egress-proxy:latest
