<?php

/**
 * Hermiq RunRetentionCleanupJob.
 *
 * The scheduled half of run retention. A retention that nothing enforces is a
 * promise on a screen, so this runs daily and removes the payload of every run
 * entry past the retention it was written with.
 *
 * A pure wrapper (ADR-002): every decision lives in `RunRetentionCleaner`, which is
 * where the tests are, and nothing here knows what a tombstone looks like.
 *
 * @category BackgroundJob
 * @package  OCA\Hermiq\BackgroundJob
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
 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#requirement-a-scheduled-job-must-enforce-retention-and-must-report-that-it-did
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Service\AiFeature\RunRetentionCleaner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the retention cleanup once a day.
 *
 * @psalm-api
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 *
 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#requirement-a-scheduled-job-must-enforce-retention-and-must-report-that-it-did
 */
class RunRetentionCleanupJob extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for TimedJob scheduling.
	 * @param RunRetentionCleaner $cleaner Removes expired run payloads.
	 * @param LoggerInterface $logger Isolates a failed cleanup from the cron tick.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly RunRetentionCleaner $cleaner,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		// Once a day. Retention is measured in days, so a shorter cadence would
		// read the same entries repeatedly and remove nothing extra.
		$this->setInterval(seconds: 86400);

		// Never two cleanups at once: two passes over the same batch would fight
		// over the same rows and report the work twice.
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Run one cleanup pass.
	 *
	 * @param mixed $argument The (unused) background-job argument.
	 *
	 * @return void
	 *
	 * @phpstan-param mixed $argument
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-expired-runs-lose-their-payload
	 */
	protected function run($argument): void {
		try {
			$removed = $this->cleaner->clean();
			$this->logger->info(
				sprintf('Hermiq run retention removed %d expired run payloads', $removed),
				['app' => 'hermiq']
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Hermiq run retention cleanup failed: ' . $e->getMessage(),
				['exception' => $e, 'app' => 'hermiq']
			);
		}

	}//end run()
}//end class
