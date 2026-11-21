<?php

/**
 * Hermiq flow-mirror rollback run.
 *
 * The progress of one `hermiq:schedules:rollback-flow-mirror` run: where it reports,
 * whether it is a dry run, the schedule in hand and its mirror flow, and the counts
 * so far. Built by RollbackScheduleFlowMirror and never handed out of it.
 *
 * @category Command
 * @package  OCA\Hermiq\Command
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
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
 */

declare(strict_types=1);

namespace OCA\Hermiq\Command;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * One flow-mirror rollback run.
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
 */
final class MirrorRollbackRun {

	/**
	 * The schedule in hand.
	 *
	 * @var string
	 */
	public string $scheduleUuid = '';

	/**
	 * The mirror flow of the schedule in hand.
	 *
	 * @var string
	 */
	public string $flowId = '';

	/**
	 * Schedules rolled back (or, in a dry run, that would be).
	 *
	 * @var int
	 */
	public int $rolledBack = 0;

	/**
	 * The schedules that could not be rolled back.
	 *
	 * @var array<int, string>
	 */
	public array $failed = [];

	/**
	 * Start the run.
	 *
	 * @param OutputInterface $output Command output.
	 * @param bool $dryRun Whether to report without changing anything.
	 */
	public function __construct(
		public readonly OutputInterface $output,
		public readonly bool $dryRun,
	) {
	}//end __construct()
}//end class
