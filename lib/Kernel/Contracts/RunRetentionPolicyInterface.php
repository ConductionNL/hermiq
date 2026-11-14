<?php

/**
 * Contract of RunRetentionPolicy
 *
 * The public surface of RunRetentionPolicy that other components call, so they depend
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
 * Contract of RunRetentionPolicy.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RunRetentionPolicyInterface {

	/**
	 * The shortest retention an administrator may set. A retention of zero would
	 * delete a run before anybody could read it, which is not storage limitation
	 * but an absent audit trail.
	 *
	 * @var int
	 */
	public const MINIMUM_DAYS = 1;

	/**
	 * The longest retention an administrator may set, ten years, matching the
	 * ceiling the audit trail itself supports.
	 *
	 * @var int
	 */
	public const MAXIMUM_DAYS = 3650;

	/**
	 * The instance default, in days. Never absent: an unset or unusable value reads
	 * as the shipped default rather than as no retention at all.
	 *
	 * @return int The default retention in days.
	 *
	 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-ai-run-must-carry-the-retention-that-applied-when-it-was-written
	 */
	public function defaultDays(): int;

	/**
	 * When retention last ran and how much it removed. A job that has never run
	 * reads as never run, rather than as a successful run of zero: those are
	 * different answers to an administrator asking whether retention is enforced.
	 *
	 * @return array{ran: bool, at: string, removed: int|null} The report.
	 *
	 * @spec openspec/specs/run-audit-log/spec.md#scenario-a-job-that-has-never-run-is-visible-as-such
	 */
	public function lastCleanup(): array;

	/**
	 * Set the instance default.
	 *
	 * @param int $days The new default, in days.
	 *
	 * @return int The stored default.
	 *
	 * @throws InvalidArgumentException When the value is outside the permitted range.
	 *
	 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-ai-run-must-carry-the-retention-that-applied-when-it-was-written
	 */
	public function setDefaultDays(int $days): int;
}//end interface
