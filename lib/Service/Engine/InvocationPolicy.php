<?php

/**
 * Hermiq InvocationPolicy
 *
 * The tool policy, descriptors and argument constraints an invocation enforces.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

/**
 * The tool policy, descriptors and argument constraints an invocation enforces.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class InvocationPolicy {

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $mcpIdByName Map of LLPhant-safe function name to the
	 *                                          dotted `mcpId` — resolves the id the
	 *                                          approval gate classifies/checks (LLPhant
	 *                                          calls back with the safe name, which may
	 *                                          have dots replaced by underscores).
	 * @param array<string,string> $toolPolicy The effective GuardrailPolicy's
	 *                                         `toolId => classification` map
	 *                                         (agent-guardrails), resolved
	 *                                         ONCE per turn by
	 *                                         `ToolLoop::resolveToolPolicy()`.
	 *                                         A tool absent from this map is
	 *                                         `auto` (zero behavior change);
	 *                                         an empty map (no
	 *                                         `GuardrailPolicyService`, or no
	 *                                         policy configured) disables
	 *                                         this short-circuit entirely.
	 * @param array<string,array<string,mixed>> $descriptorsByName Map of LLPhant-safe function
	 *                                                             name to its full catalog
	 *                                                             descriptor
	 *                                                             (run-replay-and-dry-run), so
	 *                                                             the classifier can consult a
	 *                                                             tool's declared `scope`/
	 *                                                             `destructiveHint`/`readOnlyHint`
	 *                                                             when available. Empty for
	 *                                                             existing callers — the
	 *                                                             classifier then falls back
	 *                                                             to id-only classification.
	 * @param array<string,array<int,array>> $argumentConstraints Per-tool ALTERNATIVE argument-constraint
	 *                                                            sets from an argument-scoped grant
	 *                                                            (`ToolGrantResolver::argumentConstraints()`,
	 *                                                            hydra-console-agent-leaves). Each inner
	 *                                                            entry is an `argument => {mode, values}`
	 *                                                            map. A tool absent from this map is
	 *                                                            unconstrained; an empty map (every
	 *                                                            pre-existing caller) disables the check
	 *                                                            entirely — zero behaviour change.
	 * @param array<string,array<int,array>> $waivedConstraintSets The subset of those alternative sets
	 *                                                             belonging to grant entries that carried
	 *                                                             a `#noapproval` fragment
	 *                                                             (`ToolGrantResolver::waivedConstraintSets()`,
	 *                                                             agent-capability-reach). Shaped
	 *                                                             identically so the same pure checker
	 *                                                             decides conformance. Empty (every
	 *                                                             pre-existing caller) waives nothing.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly array $mcpIdByName = [],
		public readonly array $toolPolicy = [],
		public readonly array $descriptorsByName = [],
		public readonly array $argumentConstraints = [],
		public readonly array $waivedConstraintSets = [],
	) {
	}//end __construct()
}//end class
