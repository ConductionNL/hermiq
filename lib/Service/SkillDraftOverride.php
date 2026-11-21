<?php

/**
 * Hermiq SkillDraftOverride.
 *
 * The draft a draft-vs-active comparison swaps in: which skill it drafts and the
 * `{name, description, body}` content that replaces the stored skill's content,
 * in memory only, for the draft half.
 *
 * @category ValueObject
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
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-paired-draft-vs-active-eval-gates-the-draft-and-a-worse-draft-is-auto-discarded
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * One skill draft under comparison.
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-a-paired-draft-vs-active-eval-gates-the-draft-and-a-worse-draft-is-auto-discarded
 */
final class SkillDraftOverride {

	/**
	 * Name the draft.
	 *
	 * @param string               $skillId The skill under comparison.
	 * @param array<string, mixed> $content The draft's `{name, description, body}` override.
	 */
	public function __construct(
		public readonly string $skillId,
		public readonly array $content,
	) {
	}//end __construct()
}//end class
