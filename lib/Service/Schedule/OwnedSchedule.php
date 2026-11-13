<?php

/**
 * Hermiq OwnedSchedule.
 *
 * The owner guard every run-by-hand path shares: a schedule is the caller's
 * only when it loads and its owner is the caller.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Schedule
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
 * @spec openspec/changes/hermiq-domain-mcp-tools/specs/hermiq-domain-mcp-tools/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Schedule;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Loads a schedule only for its owner. Moved out of `RunNowController` so the
 * run-now route and the `hermiq.runAgentNow` tool run the identical guard: a
 * schedule that cannot be loaded, or that another user owns, is not the
 * caller's, and the caller cannot tell the two apart.
 *
 * @spec openspec/changes/hermiq-domain-mcp-tools/specs/hermiq-domain-mcp-tools/spec.md
 */
final class OwnedSchedule {

	/**
	 * The register the schedules live in.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * The schedule schema.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'schedule';

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister's object service.
	 * @param LoggerInterface $logger        Logs a failed lookup.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The schedule when the caller owns it, else null.
	 *
	 * @param string $scheduleId The Schedule object UUID.
	 * @param string $uid        The caller.
	 *
	 * @return ObjectEntity|null The owned schedule, or null.
	 *
	 * @spec openspec/changes/hermiq-domain-mcp-tools/specs/hermiq-domain-mcp-tools/spec.md
	 */
	public function load(string $scheduleId, string $uid): ?ObjectEntity {
		try {
			$schedule = $this->objectService->find(
				id: $scheduleId,
				register: self::REGISTER_SLUG,
				schema: self::SCHEMA_SLUG
			);
		} catch (Throwable $e) {
			// `ObjectService::find()` throws when the object is absent. A schedule
			// that cannot be loaded is, to a caller, not owned; null carries that.
			$this->logger->warning(
				'Hermiq schedule lookup failed for ' . $scheduleId . ': ' . $e->getMessage(),
				['exception' => $e]
			);
			return null;
		}

		if (($schedule instanceof ObjectEntity) === false) {
			return null;
		}

		if ((string)($schedule->getOwner() ?? '') !== $uid) {
			return null;
		}

		return $schedule;
	}//end load()
}//end class
