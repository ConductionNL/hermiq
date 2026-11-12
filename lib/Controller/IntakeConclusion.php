<?php

/**
 * Hermiq intake conclusion.
 *
 * One request to conclude an intake conversation: the conversation, the
 * classification reached, the catalogue it came from, and the intake tool to file
 * through.
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
 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

/**
 * One intake conclusion.
 *
 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md
 */
final class IntakeConclusion {

	/**
	 * The conclusion.
	 *
	 * @param string $conversationId The conversation to conclude.
	 * @param array $classification The classification reached.
	 * @param array $catalogue The catalogue the classification came from.
	 * @param string $intakeTool The intake tool to file through.
	 */
	public function __construct(
		public readonly string $conversationId,
		public readonly array $classification,
		public readonly array $catalogue,
		public readonly string $intakeTool,
	) {
	}//end __construct()
}//end class
