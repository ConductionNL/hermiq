<?php

/**
 * Hermiq draft rejection.
 *
 * One curator's rejection of one skill draft: the draft, who rejects it, their note
 * and the learnings they marked as bad.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
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
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

/**
 * One draft rejection.
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
 */
final class DraftRejection {

	/**
	 * The rejection.
	 *
	 * @param string $draftId The SkillDraft UUID.
	 * @param string $deciderUid The rejecting curator.
	 * @param string $note The curator's note.
	 * @param array $refs The learnings marked as bad.
	 */
	public function __construct(
		public readonly string $draftId,
		public readonly string $deciderUid,
		public readonly string $note,
		public readonly array $refs,
	) {
	}//end __construct()
}//end class
