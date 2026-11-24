#!/bin/bash
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
#
# Re-vendor the flow definitions SeedBundledFlows seeds, from a hydra checkout.
#
#   scripts/sync-bundled-flows.sh <path-to-hydra-checkout>
#
# hydra is the source of truth for these graphs; lib/Settings/flows holds the copy
# an install seeds. Run this after a flow changes in hydra and commit the result.
# Two files are left out on purpose:
#   - hydra-triage.flow.json: hermiq's SeedHydraTriageFlow owns the "Hydra Triage" flow.
#   - hydra-applier-live-4-1-proof.flow.json: a provenance duplicate of hydra-applier.
#
# @spec openspec/changes/archive/2026-11-24-hermiq-seed-hydra-flows/tasks.md
set -euo pipefail
src="${1:?usage: $0 <path-to-hydra-checkout>}/flows"
dest="$(cd "$(dirname "$0")/.." && pwd)/lib/Settings/flows"
[ -d "$src" ] || { echo "no flows directory at $src" >&2; exit 2; }
rm -f "$dest"/*.flow.json
for f in "$src"/*.flow.json; do
	case "$(basename "$f")" in
		hydra-triage.flow.json|hydra-applier-live-4-1-proof.flow.json) continue ;;
	esac
	cp "$f" "$dest/"
done
echo "vendored $(ls "$dest"/*.flow.json | wc -l) flow definitions from $(git -C "$src/.." rev-parse --short HEAD 2>/dev/null || echo "$src")"
