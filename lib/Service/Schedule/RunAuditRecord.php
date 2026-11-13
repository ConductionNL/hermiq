<?php

/**
 * Hermiq RunAuditRecord
 *
 * What one per-run audit entry records.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Schedule;

use DateTimeImmutable;

/**
 * What one per-run audit entry records.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class RunAuditRecord {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data The finalised schedule payload (status/agentId).
	 * @param string $summary The raw run output / error (redacted here).
	 * @param DateTimeImmutable $startedAt When the agent turn began (UTC).
	 * @param int $attempt The attempt number for this occurrence (1 for a
	 *                     fresh/non-retry run; 2+ for a retry — run-reliability).
	 * @param bool $dryRun Whether this entry records a dry-run/replay preview
	 *                     (run-replay-and-dry-run) rather than a real run. False
	 *                     (every pre-existing caller) is byte-for-byte unchanged.
	 * @param string|null $replayOf The original run's AuditTrail UUID, when this entry
	 *                              records a replay (run-replay-and-dry-run). Null for a
	 *                              real run or a direct (non-replay) dry-run.
	 * @param string|null $promptOverride The EXACT prompt text actually used for this run, when
	 *                                    it differs from `$data['prompt']` (run-replay-and-dry-run
	 *                                    replay: the original run's recorded prompt, not the
	 *                                    schedule's current, possibly-since-edited one). Null (every
	 *                                    pre-existing caller) reads `$data['prompt']` instead — the
	 *                                    prompt that WAS actually used for a real run/direct dry-run.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly array $data,
		public readonly string $summary,
		public readonly DateTimeImmutable $startedAt,
		public readonly int $attempt = 1,
		public readonly bool $dryRun = false,
		public readonly ?string $replayOf = null,
		public readonly ?string $promptOverride = null,
	) {
	}//end __construct()
}//end class
