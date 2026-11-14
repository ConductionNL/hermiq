<?php

/**
 * Hermiq Trace Export Job.
 *
 * Sends the waiting traces off the request path, one batch of at most 50 per run of the
 * job. A failed batch is retried three times with growing backoff, then dropped with one
 * warning. After each batch the `trace-export` connection is reported, throttled, so the
 * Integrations page shows the last send or the error. A failure never touches a run.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\Hermiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/observability-export-traces/specs/trace-export/spec.md#requirement-export-never-slows-or-changes-a-run-req-trex-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Kernel\Contracts\TraceExportDeliveryInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Send one batch of traces, retrying and finally dropping it.
 *
 * @spec openspec/changes/observability-export-traces/specs/trace-export/spec.md#requirement-export-never-slows-or-changes-a-run-req-trex-003
 */
class TraceExportJob extends QueuedJob {

	/**
	 * How many times a failed batch is retried before it is dropped.
	 */
	public const MAX_RETRIES = TraceExportDeliveryInterface::MAX_RETRIES;

	/**
	 * The first backoff in seconds; it doubles per retry.
	 */
	public const BACKOFF_SECONDS = TraceExportDeliveryInterface::BACKOFF_SECONDS;

	/**
	 * The connection the outcome is reported under.
	 */
	public const CONNECTION_KEY = TraceExportDeliveryInterface::CONNECTION_KEY;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                 $time     The clock.
	 * @param TraceExportDeliveryInterface $delivery Sends, retries and drops the batch.
	 * @param LoggerInterface              $logger   The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly TraceExportDeliveryInterface $delivery,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

	}//end __construct()

	/**
	 * Send one batch. A failure is logged and never thrown.
	 *
	 * @param mixed $argument The job argument: empty, or a batch with its attempt and notBefore.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-export-traces/specs/trace-export/spec.md#requirement-export-never-slows-or-changes-a-run-req-trex-003
	 */
	protected function run($argument): void {
		try {
			$this->delivery->deliver(argument: (array)$argument, now: $this->time->getTime());
		} catch (Throwable $e) {
			$this->logger->warning('[TraceExportJob] Trace export failed: ' . $e->getMessage());
		}

	}//end run()
}//end class
