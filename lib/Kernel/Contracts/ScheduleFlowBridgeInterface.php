<?php

/**
 * Contract of ScheduleFlowBridge
 *
 * The public surface of ScheduleFlowBridge that other components call, so they depend
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

/**
 * Contract of ScheduleFlowBridge.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ScheduleFlowBridgeInterface {

	/**
	 * Delete a mirror flow row; fall back to disabling it.
	 *
	 * @param string $flowId The flow uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
	 */
	public function deleteFlow(string $flowId): void;

	/**
	 * Bring every schedule's delegation in step with the engine, once.
	 *
	 * Called from the dispatch tick (arming: a schedule created since the
	 * last tick is mirrored within one poll interval) and from the
	 * migration repair step (in-flight schedules on upgrade). Idempotent by
	 * construction: a mirrored, undrifted schedule is a no-op.
	 *
	 * Per-schedule isolation mirrors the tick loop: one bad schedule must
	 * not block the sync for the rest.
	 *
	 * @return array{mirrored:int,refreshed:int,retired:int,skipped:int,failed:int} Counts of what the pass did.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
	 */
	public function syncAll(): array;
}//end interface
