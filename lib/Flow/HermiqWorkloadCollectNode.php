<?php

/**
 * Collects an async workload, contributed to OpenRegister's flow engine.
 *
 * The other half of `hermiq.workload-step` with `async: true`. That one STARTS
 * a stage and puts a handle on the item; this one asks what became of it.
 *
 * WHY THE PAIR EXISTS AT ALL
 * --------------------------
 * A stage is minutes long — six to twenty-five for a build — and
 * `FlowRunWorker` advances queued runs SERIALLY in one PHP process:
 *
 *     foreach ($this->mapper->findQueued(limit: 25) as $run) { $this->advance($run); }
 *
 * so a synchronous stage does not merely block its own run. It blocks every
 * other flow in that pass, including the lock reaper whose entire job is to
 * clean up after stuck work. It also makes a slot pool decorative: N slots
 * cannot produce N agents while the thing holding a slot occupies the only
 * worker.
 *
 * Split in two, the flow dispatches, suspends on an `openregister.wait` — which
 * releases the worker, stores the run and wakes it when `resumeAt` is due — and
 * collects here on a later pass.
 *
 * THE THREE TERMINAL ANSWERS, AND WHY THEY ARE NOT ONE
 * ---------------------------------------------------
 *   done     the stage RAN. `stage` carries the exit code, which may be
 *            non-zero — that is a stage that ran and failed, and it is a
 *            RESULT, not an error.
 *   failed   the stage could NOT be carried out. A refused push lands here
 *            with its stable `code`. It must never be read as a completed
 *            stage carrying an unlucky field — the synchronous route answers
 *            502 for exactly this reason.
 *   unknown  this runner has no such job: it restarted, or the result aged
 *            out. TERMINAL. Reporting it as `running` is how a flow waits
 *            forever for a result that no longer exists.
 *
 * `running` is the only non-terminal answer, and the only one a caller should
 * loop on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Flow
 * @package  OCA\Hermiq\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Flow;

use OCA\Hermiq\Service\AsyncStageDispatchService;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use UnexpectedValueException;

/**
 * Reads the outcome of a stage started asynchronously.
 */
class HermiqWorkloadCollectNode implements IFlowNode {

	/**
	 * Constructor.
	 *
	 * @param AsyncStageDispatchService $stages The transport.
	 * @param IL10N $l10n For palette strings.
	 * @param IURLGenerator $urls For the palette icon.
	 */
	public function __construct(
		private readonly AsyncStageDispatchService $stages,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {

	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return 'hermiq.workload-collect';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Collect workload');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string {
		return $this->l10n->t('Ask what became of a workload that was started asynchronously.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('hermiq', 'app-dark.svg');
	}//end getIcon()

	/**
	 * Available in both scopes, matching the step it collects.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * Reject a collect step that names no job.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When `job` is missing.
	 */
	public function validateConfig(array $config): void {
		if (trim((string)($config['job'] ?? '')) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('A collect step must say which job to collect, e.g. {{build.job.id}}.')
			);
		}

	}//end validateConfig()

	/**
	 * Collect once per item.
	 *
	 * @param array $items The items.
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The items, each carrying the job state.
	 */
	public function execute(array $items, array $config, array $context): array {
		$outKey = trim((string)($config['output'] ?? 'collected'));
		if ($outKey === '') {
			$outKey = 'collected';
		}

		$out = [];
		foreach ($items as $item) {
			$json = (array)($item['json'] ?? []);
			$jobId = trim($this->render(template: (string)($config['job'] ?? ''), json: $json));

			// An EMPTY handle is not a job that is still running — it is a
			// dispatch whose acknowledgement never reached the item, and
			// answering `running` would park the flow on it forever.
			$state = ['status' => 'unknown', 'error' => 'no job id on the item to collect with'];
			if ($jobId !== '') {
				$state = $this->stages->collect(jobId: $jobId, uid: ($context['triggeredBy'] ?? null));
			}

			// The stage result is published under the SAME shape a synchronous
			// step produces, so a downstream gate reading `stage.exitCode` does
			// not care which transport delivered it.
			if ($state['status'] === 'done' && isset($state['result']) === true) {
				$stageKey = trim((string)($config['stageOutput'] ?? 'stage'));
				if ($stageKey === '') {
					$stageKey = 'stage';
				}

				$json[$stageKey] = $state['result'];
				unset($state['result']);
			}

			$json[$outKey] = $state;

			$item['json'] = $json;
			$out[] = $item;
		}//end foreach

		return $out;
	}//end execute()

	/**
	 * Render `{{dotted.path}}` against the item.
	 *
	 * Deliberately the same minimal substitution the workload step uses: a
	 * collect step's only templated field is the handle, and it comes from the
	 * dispatch step's own output on the same item.
	 *
	 * @param string $template The template.
	 * @param array $json The item's record.
	 *
	 * @return string The rendered value.
	 */
	private function render(string $template, array $json): string {
		if (str_contains($template, '{{') === false) {
			return $template;
		}

		return (string)preg_replace_callback(
			'/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/',
			static function (array $match) use ($json): string {
				$cursor = $json;
				foreach (explode('.', $match[1]) as $segment) {
					if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
						return '';
					}

					$cursor = $cursor[$segment];
				}

				if (is_scalar($cursor) === false) {
					return '';
				}

				return (string)$cursor;
			},
			$template
		);
	}//end render()
}//end class
