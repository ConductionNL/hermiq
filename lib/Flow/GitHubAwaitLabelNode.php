<?php

/**
 * Holds the run until a label appears on a GitHub issue.
 *
 * WHY THIS EXISTS RATHER THAN `openregister.await-signal`. That node is the right
 * shape for an answer somebody DELIVERS: it parks the run and a POST to the resume
 * endpoint wakes it. GitHub cannot make that POST. A development instance is not on
 * the public internet, so a webhook has nowhere to land, and even where one would
 * land the flow would then depend on a delivery that can be lost. The state we care
 * about — does this issue carry `accepted` — is readable at any moment, so this node
 * ASKS instead of waiting to be told.
 *
 * It is therefore a suspend-and-poll, and it uses the engine's own heartbeat for it:
 * every wake re-reads the issue and either continues or suspends again. A wake that
 * finds nothing costs one API call, which is why the default interval is generous.
 * Being slow to notice is the correct failure here; a run that resumed on a label
 * that was never applied would branch a repository nobody approved.
 *
 * THE DEADLINE IS NOT OPTIONAL IN PRACTICE. A suspended run counts as active, so a
 * flow left waiting on an issue that is never triaged holds its own schedule shut
 * behind it. `timeoutMinutes` ends the wait and lets the author route on the
 * outcome, rather than leaving the flow indefinitely and invisibly engaged.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Flow
 * @package  OCA\Hermiq\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Flow;

use DateTime;
use OCA\Hermiq\Service\GitHubBroker;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use Throwable;
use UnexpectedValueException;

/**
 * Suspends the run until a named label is on a named issue.
 */
class GitHubAwaitLabelNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm, IFlowNodeTaxonomy {

	/**
	 * How long to sleep between checks when the author sets nothing.
	 *
	 * @var int
	 */
	private const DEFAULT_HEARTBEAT_MINUTES = 5;

	/**
	 * How long to keep asking before giving up, when the author sets nothing.
	 *
	 * @var int
	 */
	private const DEFAULT_TIMEOUT_MINUTES = 1440;

	/**
	 * Constructor.
	 *
	 * @param GitHubBroker $broker The brokered GitHub client.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urls Icon URLs.
	 */
	public function __construct(
		private readonly GitHubBroker $broker,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {
	}//end __construct()

	/**
	 * The node id.
	 *
	 * @return string
	 */
	public function getId(): string {
		return 'hermiq.github-await-label';
	}//end getId()

	/**
	 * The name on the canvas.
	 *
	 * @return string
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Wait for a GitHub label');
	}//end getDisplayName()

	/**
	 * What it does, in one line.
	 *
	 * @return string
	 */
	public function getDescription(): string {
		return $this->l10n->t('Pause until someone puts a named label on the issue, then carry on.');
	}//end getDescription()

	/**
	 * The icon.
	 *
	 * @return string
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/confirm.svg');
	}//end getIcon()

	/**
	 * Where the node may be used.
	 *
	 * @param int $scope The workflow scope.
	 *
	 * @return bool
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The shape it is a service task, and it belongs with the other human gates:
	 * what it is really waiting for is a person deciding.
	 *
	 * @return string
	 */
	public function getKind(): string {
		return 'serviceTask';
	}//end getKind()

	/**
	 * The palette group.
	 *
	 * @return string
	 */
	public function getCategory(): string {
		return 'human';
	}//end getCategory()

	/**
	 * The accepted config keys.
	 *
	 * @return array<int,string>
	 */
	public function configKeys(): array {
		return ['repo', 'issue', 'label', 'heartbeatMinutes', 'timeoutMinutes', 'output', 'onTimeout'];
	}//end configKeys()

	/**
	 * The editor's fields.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'repo',
				'label' => $this->l10n->t('Repository'),
				'type' => 'text',
				'help' => $this->l10n->t('As owner/name, for example ConductionNL/planninq. Templates are allowed.'),
				'required' => true,
			],
			[
				'key' => 'issue',
				'label' => $this->l10n->t('Issue number'),
				'type' => 'text',
				'help' => $this->l10n->t('Usually a template onto the item, such as {{issueNumber}}.'),
				'required' => true,
			],
			[
				'key' => 'label',
				'label' => $this->l10n->t('Label to wait for'),
				'type' => 'text',
				'help' => $this->l10n->t('Matched without regard to case, so "Accepted" and "accepted" are the same label.'),
				'required' => true,
			],
			[
				'key' => 'heartbeatMinutes',
				'label' => $this->l10n->t('Check every (minutes)'),
				'type' => 'number',
				'help' => $this->l10n->t('Each check is one call to GitHub. Five minutes is plenty for a label a person applies by hand.'),
			],
			[
				'key' => 'timeoutMinutes',
				'label' => $this->l10n->t('Give up after (minutes)'),
				'type' => 'number',
				'help' => $this->l10n->t('A run waiting here counts as active and holds its flow\'s schedule. Defaults to a day.'),
			],
			[
				'key' => 'output',
				'label' => $this->l10n->t('Field to store the result in'),
				'type' => 'text',
				'help' => $this->l10n->t('Receives the label list and the issue title, so later steps can read them. Defaults to "labelWait".'),
			],
			[
				'key' => 'onTimeout',
				'label' => $this->l10n->t('On timeout'),
				'type' => 'select',
				'options' => ['continue', 'fail'],
				'help' => $this->l10n->t('Continue lets the author route on the result. Fail stops the run and says why.'),
			],
		];
	}//end configForm()

	/**
	 * Refuse a wait that cannot describe what it is waiting for.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When repo, issue or label is missing.
	 */
	public function validateConfig(array $config): void {
		foreach (['repo' => 'a repository', 'issue' => 'an issue number', 'label' => 'a label'] as $key => $what) {
			if (trim((string)($config[$key] ?? '')) === '') {
				throw new UnexpectedValueException(
					sprintf('A GitHub label wait needs %s. Set "%s" on the step.', $what, $key)
				);
			}
		}
	}//end validateConfig()

	/**
	 * Read the issue, continue when the label is there, otherwise sleep.
	 *
	 * The deadline lives in the node's RESUME STATE, not on the item. That is not a
	 * preference: throwing FlowSuspension abandons the step, so every item mutation
	 * made on the way to the throw is discarded. A deadline written onto the item
	 * was therefore recomputed from `now` on every single wake, which is a timeout
	 * that can never expire, on a node whose whole reason for having a deadline is
	 * that a suspended run holds its flow's schedule shut behind it. Resume state
	 * is the one thing the engine carries across a suspension.
	 *
	 * @param array $items The items in flight.
	 * @param array $config The step configuration.
	 * @param array $context The run context.
	 *
	 * @return array The items, each carrying the result.
	 *
	 * @throws FlowSuspension While the label is absent and the deadline has not passed.
	 * @throws FlowStop When the deadline passes and the step is set to fail.
	 */
	public function execute(array $items, array $config, array $context): array {
		$label = strtolower(trim((string)($config['label'] ?? '')));
		$outputKey = trim((string)($config['output'] ?? ''));
		if ($outputKey === '') {
			$outputKey = 'labelWait';
		}

		$userId = ($context['triggeredBy'] ?? null);
		$now = new DateTime();
		$stillWaiting = false;
		$deadline = $this->deadline(config: $config, context: $context, now: $now);
		$expired = ($deadline !== null && strtotime($deadline) !== false && strtotime($deadline) <= $now->getTimestamp());

		foreach ($items as $index => $item) {
			if (is_array($item) === false) {
				// Not a skip. `continue` here let a malformed item through the
				// approval gate untouched, which is the same hole as an empty
				// template resolving to nothing: the gate does not gate, and the
				// run carries on as though someone had approved it.
				throw new FlowStop(
					reason: sprintf('Item %s is not a record this step can read, so it cannot be waited on.', (string)$index),
					isError: true
				);
			}

			$json = (array)($item[FlowItems::JSON] ?? []);
			$repo = trim($this->render(template: (string)($config['repo'] ?? ''), json: $json));
			$number = (int)trim($this->render(template: (string)($config['issue'] ?? ''), json: $json));

			if ($repo === '' || $number <= 0) {
				// This used to write "no issue on this item" and carry on, which read
				// as tolerance and behaved as a hole. Measured 2026-09-23: the step
				// was configured with a template that resolved to nothing, so the gate
				// waiting for a human's `accepted` label passed in a single pass, and
				// the run branched a repository and started writing code that nobody
				// had approved. The run looked healthy the whole way.
				//
				// A gate that cannot find what it is gating has not been satisfied, it
				// has failed, and the difference has to be visible. Stopping names the
				// template and the value it produced, which is the one thing that makes
				// the mistake fixable.
				throw new FlowStop(
					reason: sprintf(
						'This step cannot tell which issue to watch: repo "%s" and issue "%s" resolved to "%s" and "%s". '
						. 'Point them at a value the item actually carries.',
						(string)($config['repo'] ?? ''),
						(string)($config['issue'] ?? ''),
						$repo,
						(string)$number
					),
					isError: true
				);
			}

			try {
				$issue = $this->broker->getIssue(repo: $repo, number: $number, userId: $userId);
				$labels = (array)($issue['labels'] ?? []);
				$found = in_array($label, array_map('strtolower', $labels), true);
			} catch (Throwable $e) {
				// A failed read is not an answer. Treating it as "label absent" keeps
				// the wait honest: a rate limit or an outage must not be able to
				// decide that an issue was never approved.
				$found = false;
				$labels = [];
				$issue = ['error' => $e->getMessage()];
			}

			if ($found === false && $expired === false) {
				$json[$outputKey] = ['waited' => true, 'found' => false, 'deadline' => $deadline, 'labels' => $labels];
				$item[FlowItems::JSON] = $json;
				$items[$index] = $item;
				$stillWaiting = true;
				continue;
			}

			$json[$outputKey] = [
				'waited' => true,
				'found' => $found,
				'timedOut' => ($found === false),
				'deadline' => $deadline,
				'labels' => $labels,
				'title' => (string)($issue['title'] ?? ''),
				'state' => (string)($issue['state'] ?? ''),
				// Carried so a timeout can be told apart from an outage. Without
				// it, a day of failed reads and a day of nobody looking at the
				// issue produce the identical record: timedOut true, no labels,
				// no title. One of those is a person deciding not to approve, and
				// the other is us never having asked.
				'lastError' => (string)($issue['error'] ?? ''),
			];
			$item[FlowItems::JSON] = $json;
			$items[$index] = $item;

			if ($found === false && strtolower(trim((string)($config['onTimeout'] ?? 'continue'))) === 'fail') {
				$lastError = (string)($issue['error'] ?? '');

				throw new FlowStop(
					reason: sprintf(
						'The "%s" label was not on %s#%d before the deadline. %s',
						(string)($config['label'] ?? ''),
						$repo,
						$number,
						($lastError === '')
							? 'The issue was readable throughout, so nobody applied it.'
							: ('The last read of the issue failed, so it may never have been asked: ' . $lastError)
					),
					isError: true
				);
			}
		}//end foreach

		if ($stillWaiting === true) {
			throw new FlowSuspension(
				resumeAt: (new DateTime())->modify(
					'+' . $this->minutes(config: $config, key: 'heartbeatMinutes', fallback: self::DEFAULT_HEARTBEAT_MINUTES) . ' minutes'
				),
				reason: sprintf('waiting for the "%s" label on GitHub', (string)($config['label'] ?? ''))
			);
		}

		return $items;
	}//end execute()

	/**
	 * When this wait gives up, fixed on the first pass and carried across wakes.
	 *
	 * The engine hands the node a resume-state object precisely because a
	 * suspending node has nowhere else to keep anything: the items it mutated are
	 * thrown away with the step. The first pass writes the deadline, every later
	 * pass reads the same one back, which is what makes the timeout a real
	 * deadline rather than a fresh one per heartbeat.
	 *
	 * An engine that hands over no resume state answers null, and the wait then
	 * runs without a deadline rather than failing: losing the safety net is bad,
	 * and refusing to wait at all is worse.
	 *
	 * @param array $config The step configuration.
	 * @param array $context The run context.
	 * @param DateTime $now The current time.
	 *
	 * @return string|null The deadline as an ATOM timestamp, or null when none can be kept.
	 */
	private function deadline(array $config, array $context, DateTime $now): ?string {
		$resume = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);
		if (($resume instanceof FlowNodeResumeState) === false) {
			return null;
		}

		if ($resume->has(key: 'deadline') === true) {
			return (string)$resume->get(key: 'deadline');
		}

		$deadline = (clone $now)
			->modify('+' . $this->minutes(config: $config, key: 'timeoutMinutes', fallback: self::DEFAULT_TIMEOUT_MINUTES) . ' minutes')
			->format(DATE_ATOM);

		$resume->merge(values: ['deadline' => $deadline, 'label' => (string)($config['label'] ?? '')]);

		return $deadline;
	}//end deadline()

	/**
	 * Read one positive whole number off the config.
	 *
	 * Zero and negatives fall back rather than being honoured: a heartbeat of zero
	 * is a busy loop against somebody else's API, and a deadline of zero expires
	 * before the first check has run.
	 *
	 * @param array $config The step configuration.
	 * @param string $key The key to read.
	 * @param int $fallback The value to use when it is absent or not positive.
	 *
	 * @return int
	 */
	private function minutes(array $config, string $key, int $fallback): int {
		$value = (int)($config[$key] ?? 0);

		if ($value > 0) {
			return $value;
		}

		return $fallback;
	}//end minutes()

	/**
	 * Fill `{{ path.to.value }}` from the item's record.
	 *
	 * The same grammar the agent step uses, so a flow author writes one kind of
	 * template across a chain rather than two.
	 *
	 * @param string $template The template text.
	 * @param array $json The item's record.
	 *
	 * @return string
	 */
	private function render(string $template, array $json): string {
		return (string)preg_replace_callback(
			'/\{\{\s*([A-Za-z0-9_@.]+)\s*\}\}/',
			static function (array $matches) use ($json): string {
				$value = $json;
				foreach (explode('.', $matches[1]) as $segment) {
					if (is_array($value) === false || array_key_exists($segment, $value) === false) {
						return '';
					}

					$value = $value[$segment];
				}

				if (is_array($value) === true) {
					return (string)json_encode($value);
				}

				return (string)$value;
			},
			$template
		);
	}//end render()
}//end class
