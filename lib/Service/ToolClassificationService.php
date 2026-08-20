<?php

/**
 * Hermiq ToolClassificationService (run-replay-and-dry-run).
 *
 * Answers ONE narrow question for dry-run: "would invoking this tool cause a
 * real-world side effect?" This is a DIFFERENT axis from
 * `GuardrailPolicyService::classifyTool()`'s `auto`/`confirm`/`deny` — that is
 * an org-configurable RISK/APPROVAL policy (defaulting OPEN to `auto`, i.e.
 * "no human needed"), not an objective fact about the tool itself. An org can
 * mark a genuinely side-effecting tool `auto` (meaning: no human confirmation
 * required) without that tool ceasing to have a real side effect — so reusing
 * `GuardrailPolicyService` here would silently let an org's risk tolerance
 * decide whether a PREVIEW actually previews anything, which is the opposite
 * of what a fail-safe-closed dry-run needs. This class is deliberately NOT
 * org-configurable and defaults CLOSED (side-effecting) instead.
 *
 * Rather than inventing a second hand-maintained classification map, this
 * DELEGATES to `Engine\ToolGrantResolver::isWriteOrDestructive()` —
 * `agent-tool-governance-and-disclosure`/`hermiq-prefer-tool-hints`'s existing
 * write/destructive classifier, which already implements exactly the
 * hints-first (`scope`/`destructiveHint`/`readOnlyHint`, forwarded from
 * OpenRegister's MCP annotations), then verb-suffix-fallback (`{app}.{schema}.
 * {verb}`), then FAIL-CLOSED-on-anything-else precedence a "does this write or
 * destroy data" classifier needs. "Write/destructive" and "side-effecting" are
 * the same underlying question for dry-run's purposes: a tool that writes or
 * destroys data is exactly the kind of tool a preview must neutralise; a
 * read-only tool is exactly the kind safe to invoke for real so the preview
 * reflects accurate data. Reusing this one classifier means Hermiq maintains
 * a single write/destructive taxonomy, not two that could silently drift
 * apart.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Service\Engine\ToolGrantResolver;

/**
 * Fail-safe-closed side-effect classification for dry-run tool neutralisation.
 *
 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls
 */
class ToolClassificationService {
	/**
	 * Whether a tool id is side-effecting (and must therefore be neutralised,
	 * never invoked for real, during a dry-run).
	 *
	 * An empty/malformed id, or any id `ToolGrantResolver::requiresGrant()`
	 * cannot positively classify as a low-reach read (no hint, not a
	 * `{app}.{schema}.{verb}` id with a read verb, or a reach of `instance` or
	 * higher), is treated as side-effecting — the fail-safe-closed default the
	 * spec requires.
	 *
	 * 🔴 This delegates to the UNION predicate, not to `isWriteOrDestructive()`
	 * alone, because the reach axis exposed a real hole here: `hermiq.webFetch`
	 * declares `scope: read` and `readOnlyHint: true`, so a "preview" would
	 * invoke it FOR REAL and send a model-chosen URL out of the instance. A
	 * preview that egresses has already done the unrecallable part of the thing
	 * it was previewing. The union can only ever neutralise MORE calls than
	 * before, never fewer, so no dry-run that previously skipped a call now
	 * performs one.
	 *
	 * @param string $id The `{appId}.{toolName}` registry id
	 *                   (the `mcpId`, when resolvable).
	 * @param array<string,mixed>|null $descriptor The catalog descriptor for `$id`, when
	 *                                             available (carries the optional
	 *                                             `scope`/`destructiveHint`/`readOnlyHint`
	 *                                             keys forwarded from OpenRegister's MCP
	 *                                             annotations). Null when unavailable —
	 *                                             falls straight to the verb-suffix/
	 *                                             fail-closed rules.
	 *
	 * @return bool True when the tool must be neutralised in a dry-run.
	 *
	 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ToolGrantResolver::requiresGrant()
	 *   is a deliberately stateless pure classifier — both classifications must share
	 *   the ONE verb/hint/reach rule set, so it is called statically, not injected.
	 */
	public function isSideEffecting(string $id, ?array $descriptor = null): bool {
		if (trim($id) === '') {
			// Empty/malformed id — never let a nonsense identifier silently
			// fall through as "safe to invoke for real".
			return true;
		}

		return ToolGrantResolver::requiresGrant(id: $id, descriptor: $descriptor);
	}//end isSideEffecting()
}//end class
