<?php

/**
 * Contract of IntakeSettings
 *
 * The public surface of IntakeSettings that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use InvalidArgumentException;

/**
 * Contract of IntakeSettings.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntakeSettingsInterface {

	/**
	 * Everything an administrator reads.
	 *
	 * @return array{abstentionThreshold: float, default: float} The settings.
	 *
	 * @spec openspec/specs/conversational-intake/spec.md#scenario-the-threshold-is-readable
	 */
	public function all(): array;

	/**
	 * Set the abstention threshold.
	 *
	 * @param float $threshold The new threshold, between 0 and 1.
	 *
	 * @return float The stored threshold.
	 *
	 * @throws InvalidArgumentException When the value is outside 0..1.
	 *
	 * @spec openspec/specs/conversational-intake/spec.md#requirement-a-classification-must-carry-a-confidence-and-must-be-able-to-abstain
	 */
	public function setAbstentionThreshold(float $threshold): float;
}//end interface
