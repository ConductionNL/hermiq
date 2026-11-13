<?php

/**
 * Hermiq InvocationServices
 *
 * The services a tool invocation consults: search, approvals, classification and redaction.
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

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ToolClassificationService;
use OCA\Hermiq\Service\ToolSearchService;

/**
 * The services a tool invocation consults: search, approvals, classification and redaction.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class InvocationServices {

	/**
	 * Constructor.
	 *
	 * @param ToolSearchService|null $toolSearchService Per-run resolved-set + `searchTools`
	 *                                                  ranking
	 *                                                  (agent-tool-governance-and-disclosure);
	 *                                                  null disables both the meta-tool
	 *                                                  short-circuit and the
	 *                                                  approval-gate's grant-membership
	 *                                                  check (agent-less chat).
	 * @param ApprovalService|null $approvalService Human-approval gate; null disables the
	 *                                              destructive-invocation short-circuit
	 *                                              (existing callers, unchanged
	 *                                              behaviour).
	 * @param ToolClassificationService|null $classifier Resolves whether a tool is
	 *                                                   side-effecting or
	 *                                                   read-only
	 *                                                   (run-replay-and-dry-run);
	 *                                                   only consulted when
	 *                                                   `$dryRun` is true.
	 *                                                   Defaults to a fresh
	 *                                                   instance (stateless, no
	 *                                                   dependencies) so callers
	 *                                                   never need to construct
	 *                                                   one just to leave dry-run
	 *                                                   off.
	 * @param RedactionService|null $redactionService Masks secrets/PII in a
	 *                                                `would-have-called`
	 *                                                step's arguments
	 *                                                before they reach the
	 *                                                trace
	 *                                                (run-replay-and-dry-run,
	 *                                                the ONE exception to
	 *                                                this class's "never
	 *                                                raw arguments" rule).
	 *                                                Null falls back to a
	 *                                                fully-opaque
	 *                                                placeholder rather
	 *                                                than ever risking an
	 *                                                unredacted value
	 *                                                (fail-safe).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly ?ToolSearchService $toolSearchService = null,
		public readonly ?ApprovalService $approvalService = null,
		public readonly ?ToolClassificationService $classifier = new ToolClassificationService(),
		public readonly ?RedactionService $redactionService = null,
	) {
	}//end __construct()
}//end class
