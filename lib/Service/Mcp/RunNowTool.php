<?php

/**
 * Hermiq RunNowTool.
 *
 * The `hermiq.runAgentNow` domain tool (hermiq-domain-mcp-tools): runs one of
 * the caller's own schedules now, under the owner guard and the course
 * requirement `RunNowController::run()` applies.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Mcp
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
 * @spec openspec/changes/archive/2026-11-24-hermiq-domain-mcp-tools/specs/hermiq-domain-mcp-tools/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Mcp;

use OCA\Hermiq\Kernel\Contracts\LiteracyRequirementInterface;
use OCA\Hermiq\Kernel\Contracts\ScheduleServiceInterface;
use OCA\Hermiq\Service\Schedule\OwnedSchedule;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Run one of the caller's own schedules now, as the run-now route does.
 *
 * @spec openspec/changes/archive/2026-11-24-hermiq-domain-mcp-tools/specs/hermiq-domain-mcp-tools/spec.md
 */
class RunNowTool {

	/**
	 * The owner guard of a run by hand, the one RunNowController runs.
	 *
	 * @var OwnedSchedule
	 */
	private readonly OwnedSchedule $owned;

	/**
	 * Constructor.
	 *
	 * @param ScheduleServiceInterface          $schedules Runs a schedule now.
	 * @param ObjectService                     $objects   Loads the caller's schedule.
	 * @param LoggerInterface                   $logger    Logs a schedule that cannot be loaded.
	 * @param LiteracyRequirementInterface|null $literacy  The course a run by hand may require.
	 */
	public function __construct(
		private readonly ScheduleServiceInterface $schedules,
		ObjectService $objects,
		LoggerInterface $logger,
		private readonly ?LiteracyRequirementInterface $literacy = null,
	) {
		$this->owned = new OwnedSchedule(objectService: $objects, logger: $logger);
	}//end __construct()

	/**
	 * Run one of the caller's own schedules now.
	 *
	 * @param string $uid        The caller.
	 * @param string $scheduleId The schedule uuid.
	 *
	 * @return array<string, mixed> The schedule's status after the run, or an error envelope.
	 *
	 * @spec openspec/changes/archive/2026-11-24-hermiq-domain-mcp-tools/specs/hermiq-domain-mcp-tools/spec.md
	 */
	public function run(string $uid, string $scheduleId): array {
		if ($scheduleId === '') {
			return DomainTools::error(code: 'invalid_arguments', message: 'scheduleId is required.');
		}

		$schedule = $this->owned->load(scheduleId: $scheduleId, uid: $uid);
		if ($schedule === null) {
			// As the route: a schedule of somebody else reads as one that does not exist.
			return DomainTools::error(code: 'not_found', message: 'Schedule not found.');
		}

		$refusal = $this->literacy?->refusal(uid: $uid);
		if ($refusal !== null) {
			return DomainTools::error(code: 'literacy_required', message: $refusal['message']);
		}

		$this->schedules->runNow(schedule: $schedule);
		$data = ($this->owned->load(scheduleId: $scheduleId, uid: $uid)?->getObject() ?? []);

		return [
			'scheduleId' => $scheduleId,
			'status' => ($data['lastStatus'] ?? null),
			'error' => ($data['lastError'] ?? null),
			'nextRun' => ($data['nextRun'] ?? null),
		];
	}//end run()
}//end class
