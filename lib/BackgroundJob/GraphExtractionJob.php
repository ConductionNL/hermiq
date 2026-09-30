<?php

/**
 * Hermiq GraphExtractionJob.
 *
 * One queued batch of knowledge-graph extraction. A pure wrapper, like TalkTurnJob:
 * the argument carries the user who enqueued the batch and its sources, and
 * GraphExtractionService runs the whole batch as that user and restores the prior
 * identity afterwards. A malformed argument does nothing.
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
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Service\Graph\GraphExtractionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Runs one extraction batch as its user.
 *
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
 */
class GraphExtractionJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param GraphExtractionService $extraction The extraction service.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly GraphExtractionService $extraction,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Run the batch.
	 *
	 * @param mixed $argument {userId, sources}.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
	 */
	protected function run($argument): void {
		if (is_array($argument) === false) {
			return;
		}

		$uid = (string)($argument['userId'] ?? '');
		$sources = ($argument['sources'] ?? null);
		if ($uid === '' || is_array($sources) === false || $sources === []) {
			return;
		}

		$this->extraction->extract(uid: $uid, sources: array_values($sources));

	}//end run()
}//end class
