<?php

/**
 * Hermiq WorkspaceReaperJob.
 *
 * Removes every governed run workspace that has been idle for longer than the
 * retention window (`workspace_retention_seconds`, default one hour), so a
 * failed run stays debuggable for a while and no checkout outlives it for long.
 *
 * @category BackgroundJob
 * @package  OCA\Hermiq\BackgroundJob
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reaps idle run workspaces every quarter of an hour.
 *
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
 */
class WorkspaceReaperJob extends TimedJob {

	/**
	 * Build the job.
	 *
	 * @param ITimeFactory                $time     Clock.
	 * @param ServerSideWorkspaceProvider $provider The workspace store.
	 * @param LoggerInterface             $logger   Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ServerSideWorkspaceProvider $provider,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 900);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Reap.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The TimedJob signature requires it.
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
	 */
	protected function run($argument): void {
		try {
			$removed = $this->provider->reap();
			if ($removed > 0) {
				$this->logger->info(sprintf('Hermiq removed %d idle run workspaces', $removed), ['app' => 'hermiq']);
			}
		} catch (Throwable $e) {
			$this->logger->error('Hermiq workspace reaping failed: ' . $e->getMessage(), ['exception' => $e, 'app' => 'hermiq']);
		}
	}//end run()
}//end class
