<?php

/**
 * Hermiq ResearchStarter.
 *
 * Starts, stops and shows deep research runs (design D1, D6). A start checks
 * that the person may read the agent and the conversation, opens a handoff of
 * kind `research` with the run's caps, and queues the job that runs it; the
 * request never waits on the research. A stop sets the handoff's stop flag,
 * which the runner reads before every step. Only the person who started a run
 * may stop or read it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Research
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Research;

use OCA\Hermiq\BackgroundJob\ResearchJob;
use OCA\Hermiq\Service\Handoff\HandoffService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;

/**
 * Starts, stops and shows research runs for the person who asked.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */
class ResearchStarter {

	/**
	 * The handoff target of every research run.
	 */
	public const TARGET = 'deep-research';

	/**
	 * The app config key holding the instance's caps as a JSON object.
	 */
	public const CAPS_KEY = 'research_caps';

	/**
	 * Hours after which a run that never finished expires.
	 */
	private const DEADLINE_HOURS = 1;

	/**
	 * The longest question kept, in characters.
	 */
	private const QUESTION_LENGTH = 1000;

	/**
	 * Constructor.
	 *
	 * @param HandoffService  $handoffs  Opens and updates the research handoff.
	 * @param ResearchObjects $objects   Reads the agent and the conversation as the person.
	 * @param IJobList        $jobList   Queues the run.
	 * @param IAppConfig      $appConfig The instance's caps.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly HandoffService $handoffs,
		private readonly ResearchObjects $objects,
		private readonly IJobList $jobList,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Start a research run in the background.
	 *
	 * @param ResearchRequest $request What the person asks.
	 * @param string          $userId  The person who asks.
	 *
	 * @return array<string, mixed>|null The run as show() answers it, or null when the person
	 *                                   may not read the agent or the conversation.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	public function start(ResearchRequest $request, string $userId): ?array {
		$conversation = $this->objects->readable(uuid: $request->conversationId, schema: ResearchObjects::CONVERSATION);
		$agent        = $this->objects->readable(uuid: $request->agentId, schema: ResearchObjects::AGENT);
		if ($conversation === null || $agent === null || $userId === '') {
			return null;
		}

		$handoff = $this->handoffs->open(
			handoff: [
				'kind'           => HandoffService::RESEARCH,
				'agentId'        => $request->agentId,
				'conversationId' => $request->conversationId,
				'requestedBy'    => $userId,
				'label'          => mb_substr($request->question, 0, self::QUESTION_LENGTH),
				'target'         => self::TARGET,
				'deadlineHours'  => self::DEADLINE_HOURS,
				'depth'          => $request->depth,
				'organisation'   => (string)($conversation->getOrganisation() ?? ''),
				'plan'           => [],
				'ledger'         => [],
				'caps'           => ResearchCaps::fromSettings(settings: $this->instanceCaps())->forDepth(depth: $request->depth)->toArray(),
				'progress'       => ['phase' => 'plan', 'toolCalls' => 0, 'sources' => 0, 'tokens' => 0],
				'stopRequested'  => false,
			]
		);

		$this->jobList->add(ResearchJob::class, ['handoffId' => (string)$handoff['id']]);

		return $this->view(handoffId: (string)$handoff['id'], handoff: $handoff);
	}//end start()

	/**
	 * Ask a running research run to stop at its next step.
	 *
	 * @param string $handoffId The run.
	 * @param string $userId    The person asking.
	 *
	 * @return bool True when the run was the person's own and still running.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
	 */
	public function stop(string $handoffId, string $userId): bool {
		if ($this->ownRun(handoffId: $handoffId, userId: $userId) === null) {
			return false;
		}

		return $this->handoffs->update(handoffId: $handoffId, fields: ['stopRequested' => true]);
	}//end stop()

	/**
	 * One of the person's research runs: its status, plan and progress.
	 *
	 * @param string $handoffId The run.
	 * @param string $userId    The person asking.
	 *
	 * @return array<string, mixed>|null The run, or null when it is not the person's.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	public function show(string $handoffId, string $userId): ?array {
		$handoff = $this->ownRun(handoffId: $handoffId, userId: $userId);
		if ($handoff === null) {
			return null;
		}

		return $this->view(handoffId: $handoffId, handoff: $handoff);
	}//end show()

	/**
	 * The research handoff, when it is one and the person started it.
	 *
	 * @param string $handoffId The run.
	 * @param string $userId    The person.
	 *
	 * @return array<string, mixed>|null
	 */
	private function ownRun(string $handoffId, string $userId): ?array {
		$handoff = $this->handoffs->find(handoffId: $handoffId);
		$own     = ($handoff['kind'] ?? '') === HandoffService::RESEARCH && $userId !== '' && ($handoff['requestedBy'] ?? '') === $userId;
		if ($own === false) {
			return null;
		}

		return $handoff;
	}//end ownRun()

	/**
	 * What the chat shows of a run. The ledger's page texts stay on the handoff.
	 *
	 * @param string               $handoffId The run.
	 * @param array<string, mixed> $handoff   The stored handoff.
	 *
	 * @return array<string, mixed>
	 */
	private function view(string $handoffId, array $handoff): array {
		return [
			'id'            => $handoffId,
			'status'        => (string)($handoff['status'] ?? ''),
			'question'      => (string)($handoff['label'] ?? ''),
			'depth'         => (string)($handoff['depth'] ?? ''),
			'plan'          => (array)($handoff['plan'] ?? []),
			'progress'      => (array)($handoff['progress'] ?? []),
			'caps'          => (array)($handoff['caps'] ?? []),
			'stopRequested' => (($handoff['stopRequested'] ?? false) === true),
			'result'        => $handoff['result'] ?? null,
		];
	}//end view()

	/**
	 * The instance's caps from app config, empty when unset or not a JSON object.
	 *
	 * @return array<string, mixed>
	 */
	private function instanceCaps(): array {
		$decoded = json_decode($this->appConfig->getValueString('hermiq', self::CAPS_KEY, ''), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end instanceCaps()
}//end class
