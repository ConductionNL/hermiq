<?php

/**
 * Hermiq IntakeRefusedException.
 *
 * The intake surface was asked to call something its grant does not cover. The grant
 * is create-only by design: a citizen with no record needs one filed, and nothing on
 * this surface has any business reading or changing a record that already exists.
 *
 * @category Exception
 * @package  OCA\Hermiq\Service\Intake
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
 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-intake-cannot-touch-an-existing-record
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Intake;

use RuntimeException;

/**
 * A call the intake surface's narrow grant does not cover.
 *
 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-intake-cannot-touch-an-existing-record
 */
class IntakeRefusedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $toolId The tool that was asked for.
	 * @param string $reason Why the grant does not cover it.
	 */
	public function __construct(
		public readonly string $toolId,
		string $reason,
	) {
		parent::__construct(
			sprintf("The intake surface may not call '%s': %s.", $toolId, $reason),
			403
		);

	}//end __construct()
}//end class
