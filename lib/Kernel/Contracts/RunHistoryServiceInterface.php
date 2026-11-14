<?php

/**
 * Contract of RunHistoryService
 *
 * The public surface of RunHistoryService that other components call, so they depend
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
 * Contract of RunHistoryService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RunHistoryServiceInterface {

	/**
	 * Return a schedule's run history newest-first as compact run records.
	 *
	 * Reads the Schedule object's `action='run'` audit entries, keeps them, sorts
	 * newest-first, and applies offset/limit paging.
	 *
	 * WHY read by `object_uuid` via AuditTrailMapper::findAll() rather than
	 * ObjectService::getLogs(): OpenRegister's `getLogs()` filters audit rows by the
	 * object's INTEGER id (`GetObject::findLogs()` sets `$filters['object'] = getId()`),
	 * but the app-writable `AuditTrailMapper::recordObjectEvent()` we use for the
	 * per-run entry sets `object_uuid`/`register`/`schema` yet NEVER sets the integer
	 * `object` column — so our run rows have `object = NULL` and `getLogs()` never
	 * matches them (confirmed in the DB: auto create/update rows carry object=<id>, our
	 * `run` row carries object=NULL). Reading by the string `object_uuid` (which
	 * findAll() special-cases as a valid filter column) matches our entries reliably.
	 * This is an upstream OR omission (recordObjectEvent should also setObject) —
	 * see design.md Risks; the audit read is NOT tenant-filtered here, so the caller's
	 * owner check (RunHistoryController) remains the security boundary.
	 *
	 * @param string $scheduleUuid The Schedule object UUID.
	 * @param int $limit Max records to return.
	 * @param int $offset Records to skip (paging).
	 *
	 * @return array<int, array<string, mixed>> The run records, newest-first.
	 *
	 * @spec openspec/changes/archive/2026-09-06-run-audit-log/tasks.md#task-3-1
	 */
	public function getRunHistory(string $scheduleUuid, int $limit = 20, int $offset = 0): array;

	/**
	 * Return one run's full, ordered step timeline (run-trace-observability).
	 *
	 * Reads the SAME `action='run'` entries `getRunHistory()` reads (by
	 * `object_uuid`, never tenant-filtered here — the caller's owner check is the
	 * security boundary, same as `getRunHistory()`), locates the entry whose UUID
	 * matches `$runId`, and — when it is immediately preceded (no gap, no other
	 * status in between) by one or more `awaiting_approval`/`skipped_killswitch`
	 * entries for the SAME schedule — reconstructs a leading `gate_wait` step
	 * spanning from the first such entry's `created` timestamp to this run's
	 * actual `startedAt`. Never guesses a gate-wait across a gap or a different
	 * schedule's entries.
	 *
	 * A `$runId` that does not belong to `$scheduleUuid` simply never appears in
	 * the `object_uuid`-filtered result set, so it naturally returns null rather
	 * than another schedule's run — no separate ownership check is needed here
	 * (`RunHistoryController` still owns the caller-facing IDOR guard).
	 *
	 * @param string $scheduleUuid The Schedule object UUID.
	 * @param string $runId The run's AuditTrail entry UUID.
	 *
	 * @return array<string, mixed>|null The run's trace record, or null when not found.
	 *
	 * @spec openspec/changes/archive/2026-07-12-run-trace-observability/tasks.md#task-4-runhistoryservicegetruntrace-trace-read-gate-wait-reconstruction
	 */
	public function getRunTrace(string $scheduleUuid, string $runId): ?array;
}//end interface
