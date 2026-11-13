<?php

/**
 * Hermiq TurnSafeguards
 *
 * What screens a turn: the guardrail policy, session participation and the user directory.
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

use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCP\IUserManager;

/**
 * What screens a turn: the guardrail policy, session participation and the user directory.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class TurnSafeguards {

	/**
	 * Constructor.
	 *
	 * @param GuardrailPolicyService|null $guardrailPolicyService Resolves + applies the effective
	 *                                                            GuardrailPolicy's input/output
	 *                                                            filters (agent-guardrails).
	 *                                                            Nullable/optional purely so
	 *                                                            existing test callers that omit
	 *                                                            it see zero behavior change
	 *                                                            (equivalent to "no policy
	 *                                                            configured" — fail-open,
	 *                                                            design.md Decision 1); real DI
	 *                                                            always provides it.
	 * @param ConversationParticipation $participation The owner-or-listed-participant
	 *                                                 guard (talk-shared-sessions).
	 *                                                 Defaulted so every existing
	 *                                                 caller constructs unchanged; the
	 *                                                 class is dependency-free.
	 * @param IUserManager|null $userManager Resolves a speaker's display name for a
	 *                                       shared session's turn.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly ?GuardrailPolicyService $guardrailPolicyService = null,
		public readonly ConversationParticipation $participation = new ConversationParticipation(),
		public readonly ?IUserManager $userManager = null,
	) {
	}//end __construct()
}//end class
