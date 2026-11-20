<?php

/**
 * Hermiq: the daily removal of expired cached answers.
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
 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Service\AnswerCache\AnswerCacheService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removes the cached answers whose lifetime has passed, once a day.
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004
 */
class AnswerCachePurgeJob extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory       $time   The clock.
	 * @param AnswerCacheService $cache  The answer cache.
	 * @param LoggerInterface    $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly AnswerCacheService $cache,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Remove the expired entries.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is part of the background job signature; this
	 * job takes no argument.
	 */
	protected function run($argument): void {
		try {
			$removed = $this->cache->purgeExpired();
			$this->logger->info(sprintf('Hermiq answer cache removed %d expired answers', $removed), ['app' => 'hermiq']);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq answer cache purge failed: ' . $e->getMessage(), ['exception' => $e, 'app' => 'hermiq']);
		}
	}//end run()
}//end class
