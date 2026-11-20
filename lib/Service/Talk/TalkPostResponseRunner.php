<?php

/**
 * Hermiq TalkPostResponseRunner.
 *
 * Runs work in the SAME PHP process, after the sender's response has gone out.
 * This is instant-talk's path B: where no triggerable runner is registered, a
 * Talk turn still starts within seconds instead of waiting for a cron tick,
 * and the sender's message-send request is never held for it.
 *
 * Mechanics: the work is registered as a shutdown function. When it runs,
 * `fastcgi_finish_request()` (PHP-FPM) first flushes and closes the response,
 * so the client is released before the turn starts. On a SAPI without it the
 * work runs in the shutdown phase under the turn timeout budget, with
 * `ignore_user_abort()` so a client that disconnects does not cut the turn off.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Talk
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
 * @spec openspec/changes/instant-talk/tasks.md#2-immediate-execution-paths
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Defers work past the response boundary.
 *
 * @psalm-api
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-turns-execute-immediately-with-claim-based-exactly-once-semantics
 */
class TalkPostResponseRunner {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the work once the response is out.
	 *
	 * @param callable $work          The work; its result is ignored.
	 * @param int      $budgetSeconds The time budget for the work.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-turns-execute-immediately-with-claim-based-exactly-once-semantics
	 */
	public function defer(callable $work, int $budgetSeconds): void {
		register_shutdown_function(
			function () use ($work, $budgetSeconds): void {
				$this->runAfterResponse(work: $work, budgetSeconds: $budgetSeconds);
			}
		);
	}//end defer()

	/**
	 * Release the client, then run the work. Never throws: an exception in a
	 * shutdown function would surface as a fatal in somebody's request log.
	 *
	 * @param callable $work          The work.
	 * @param int      $budgetSeconds The time budget for the work.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-turns-execute-immediately-with-claim-based-exactly-once-semantics
	 */
	public function runAfterResponse(callable $work, int $budgetSeconds): void {
		if (function_exists('fastcgi_finish_request') === true) {
			fastcgi_finish_request();
		}

		ignore_user_abort(true);
		if ($budgetSeconds > 0) {
			set_time_limit($budgetSeconds);
		}

		try {
			$work();
		} catch (Throwable $e) {
			// The queued job still carries the turn; its claim expires and the
			// job re-runs it, so this is logged, not lost.
			$this->logger->error(
				message: '[TalkPostResponseRunner] Instant turn failed after the response; the queued job recovers it',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
		}
	}//end runAfterResponse()
}//end class
