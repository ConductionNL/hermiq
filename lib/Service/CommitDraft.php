<?php

/**
 * Hermiq CommitDraft.
 *
 * A commit about to be made on top of a publish run's base commit: its tree
 * entries and its message.
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
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * One commit's entries and message.
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
 */
final class CommitDraft {

	/**
	 * Build the draft.
	 *
	 * @param array<int, array<string, string>> $entries The tree entries.
	 * @param string $message The commit message.
	 */
	public function __construct(
		public readonly array $entries,
		public readonly string $message,
	) {
	}//end __construct()
}//end class
