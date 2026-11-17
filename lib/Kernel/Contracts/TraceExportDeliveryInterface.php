<?php

/**
 * Contract of TraceExportDelivery
 *
 * The public surface of TraceExportDelivery that the trace export job calls, so the job
 * depends on this contract instead of the implementation.
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
 * Contract of TraceExportDelivery.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TraceExportDeliveryInterface {

	/**
	 * How many times a failed batch is retried before it is dropped.
	 */
	public const MAX_RETRIES = 3;

	/**
	 * The first backoff in seconds; it doubles per retry.
	 */
	public const BACKOFF_SECONDS = 60;

	/**
	 * The connection the outcome is reported under.
	 */
	public const CONNECTION_KEY = 'trace-export';

	/**
	 * Send one batch of traces, retrying and finally dropping it.
	 *
	 * @param array<string, mixed> $argument The job argument: empty, or a batch with its attempt and notBefore.
	 * @param int                  $now      The current time, in seconds.
	 *
	 * @return void
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function deliver(array $argument, int $now): void;
}//end interface
