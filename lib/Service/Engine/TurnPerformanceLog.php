<?php

/**
 * Hermiq: the performance log line of one ResponseGenerationHandler turn.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use Psr\Log\LoggerInterface;

/**
 * Logs a finished turn's timings with the keys that join it to the runner's log.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */
final class TurnPerformanceLog {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log the turn's timings with the keys that join it to the runner's log.
	 *
	 * CORRELATION. Without these this line cannot be joined to anything: the
	 * runner logs its own timings and pool hit/miss against a HASHED pool key, so
	 * the two halves of a turn — what Hermiq measured and what the CLI actually
	 * did — could not be matched up. `turnKey` is the first 12 chars of the same
	 * sha256 the runner prints, which is what makes the join possible; it is a
	 * hash, so it names no user or conversation in the log.
	 *
	 * @param ResponseTurnRun $run      The turn.
	 * @param string          $response The response text.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function record(ResponseTurnRun $run, string $response): void {
		$this->logger->info(
			message: '[ResponseGenerationHandler] Response generated - PERFORMANCE',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'conversationId' => $run->conversationId,
				'agentId' => ($run->progress->cliAgentId ?? ''),
				'turnKey' => $this->turnKey(run: $run),
				'provider' => $run->progress->driver->provider,
				'model' => $run->progress->driver->model,
				'responseLength' => strlen($response),
				'timings' => $this->turnTimings(run: $run),
			]
		);
	}//end record()

	/**
	 * The join key the runner also prints, or '' without a conversation or a
	 * bound agent.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return string
	 */
	private function turnKey(ResponseTurnRun $run): string {
		if ($run->conversationId === '' || $run->progress->cliAgentId === null) {
			return '';
		}

		return substr(
			hash('sha256', $run->conversationId . '|' . $run->progress->cliAgentId . '|' . $run->progress->driver->model),
			0,
			12
		);
	}//end turnKey()

	/**
	 * The turn's timings, formatted for the performance log.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return array<string, string>
	 */
	private function turnTimings(ResponseTurnRun $run): array {
		$totalTime = microtime(true) - $run->progress->startTime;

		return [
			'total' => round($totalTime, 2) . 's',
			'toolsLoading' => round($run->progress->toolsTime, 3) . 's',
			'llmGeneration' => round($run->progress->llmTime, 2) . 's',
			'overhead' => round($totalTime - $run->progress->llmTime - $run->progress->toolsTime, 3) . 's',
		];
	}//end turnTimings()
}//end class
