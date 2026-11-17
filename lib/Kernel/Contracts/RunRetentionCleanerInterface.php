<?php

/**
 * Contract of RunRetentionCleaner
 *
 * The public surface of RunRetentionCleaner that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of RunRetentionCleaner.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RunRetentionCleanerInterface {

	/**
	 * Remove the payload of every run entry past its own recorded retention, and
	 * record that the job ran.
	 *
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return int How many entries were tombstoned.
	 *
	 * @spec openspec/specs/run-audit-log/spec.md#scenario-expired-runs-lose-their-payload
	 */
	public function clean(?DateTimeImmutable $now = null): int;
}//end interface
