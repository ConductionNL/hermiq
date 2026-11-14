<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Hermiq\Service\Handoff
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-flow-started-by-an-agent-closes-its-handoff-when-the-flow-run-ends-req-handoff-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Handoff;

use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Flow handoffs: a flow an agent queued with `openregister.runFlow`, waited for
 * until OpenRegister reports that the run ended.
 *
 * Opening happens right after the tool queued the run, inside the turn, so the
 * handoff knows its agent, conversation and owner. Closing happens on
 * OpenRegister's `FlowRunTerminalEvent`, which names the run and its status;
 * the run's items are read through OpenRegister's own published tool
 * `openregister.flowRunStatus`, never from its run store. When the deployed
 * OpenRegister has no such event, no handoff is opened and the model is told
 * the result will not come back; the flow still runs.
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-flow-started-by-an-agent-closes-its-handoff-when-the-flow-run-ends-req-handoff-003
 */
class FlowHandoffs {

	/**
	 * OpenRegister's flow-run terminal event, named by string so hermiq boots without it.
	 */
	public const EVENT_CLASS = 'OCA\OpenRegister\Event\FlowRunTerminalEvent';

	/**
	 * What the model is told when a handoff was opened.
	 */
	public const RUNNING_NOTE = 'The flow is running. Its result will arrive in this conversation.';

	/**
	 * What the model is told when this OpenRegister cannot report the end of a run.
	 */
	public const NO_EVENT_NOTE = 'This OpenRegister cannot report when the flow ends, so its result will not come back here.';

	/**
	 * The run statuses whose work answered; every other terminal status is a failure.
	 */
	private const COMPLETED = 'completed';

	/**
	 * Whether OpenRegister's terminal event exists; injected so a test can answer both ways.
	 *
	 * @var bool
	 */
	private readonly bool $eventAvailable;

	/**
	 * Constructor.
	 *
	 * @param HandoffService     $handoffs       The handoff record.
	 * @param ToolRegistryFacade $tools          OpenRegister's published tool surface.
	 * @param LoggerInterface    $logger         The logger.
	 * @param bool|null          $eventAvailable Whether the terminal event exists; null asks the autoloader.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-flow-started-by-an-agent-closes-its-handoff-when-the-flow-run-ends-req-handoff-003
	 */
	public function __construct(
		private readonly HandoffService $handoffs,
		private readonly ToolRegistryFacade $tools,
		private readonly LoggerInterface $logger,
		?bool $eventAvailable = null,
	) {
		$this->eventAvailable = ($eventAvailable ?? class_exists(self::EVENT_CLASS));
	}//end __construct()

	/**
	 * After runFlow queued a run in a turn: open its handoff and say so in the tool result.
	 *
	 * A result that is an error, names no run, or comes from a turn without an
	 * agent, conversation or owner is returned as it is: nothing could deliver
	 * a late result there.
	 *
	 * @param array<string, mixed> $arguments The runFlow arguments.
	 * @param mixed                $result    The tool's result.
	 * @param FlowTurn             $turn      The agent, conversation and owner of the turn.
	 *
	 * @return mixed The result, with `handoff` and `note` when it was a queued run.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-flow-started-by-an-agent-closes-its-handoff-when-the-flow-run-ends-req-handoff-003
	 */
	public function afterRunFlow(array $arguments, mixed $result, FlowTurn $turn): mixed {
		$runUuid = '';
		if (is_array($result) === true) {
			$runUuid = (string) ($result['runUuid'] ?? '');
		}

		if ($runUuid === '' || $turn->isComplete() === false) {
			return $result;
		}

		if ($this->eventAvailable === false) {
			$result['note'] = self::NO_EVENT_NOTE;
			return $result;
		}

		$flowId = (string) ($arguments['flowId'] ?? '');
		try {
			$handoff = $this->handoffs->open(
				handoff: [
					'kind'           => 'flow',
					'agentId'        => $turn->agentId,
					'conversationId' => $turn->conversationId,
					'requestedBy'    => $turn->owner,
					'label'          => $this->labelOf(arguments: $arguments, flowId: $flowId),
					'target'         => $flowId,
					'externalRef'    => $runUuid,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('[FlowHandoffs] The flow handoff could not be opened: ' . $e->getMessage());
			return $result;
		}

		$result['handoff'] = (string) ($handoff['id'] ?? '');
		$result['note']    = self::RUNNING_NOTE;

		return $result;
	}//end afterRunFlow()

	/**
	 * Close the waiting handoff of a run that reached a terminal status.
	 *
	 * Idempotent: the event may fire more than once for one run, and a second
	 * close of the same handoff is ignored by HandoffService.
	 *
	 * @param string $runUuid The run's uuid.
	 * @param string $status  Its terminal status.
	 *
	 * @return bool True when this call closed a handoff.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-flow-started-by-an-agent-closes-its-handoff-when-the-flow-run-ends-req-handoff-003
	 */
	public function closeForRun(string $runUuid, string $status): bool {
		$handoffId = $this->handoffs->waitingFor(kind: 'flow', externalRef: $runUuid);
		if ($handoffId === null) {
			return false;
		}

		$run = $this->runOf(runUuid: $runUuid);
		if ($status === self::COMPLETED) {
			return $this->handoffs->close(handoffId: $handoffId, status: 'done', result: ['items' => ($run['items'] ?? [])]);
		}

		return $this->handoffs->close(
			handoffId: $handoffId,
			status: 'failed',
			result: ['status' => $status, 'outcome' => ($run['outcome'] ?? null)]
		);
	}//end closeForRun()

	/**
	 * The run as OpenRegister's flowRunStatus tool answers it, empty when it cannot be read.
	 *
	 * @param string $runUuid The run's uuid.
	 *
	 * @return array<string, mixed>
	 */
	private function runOf(string $runUuid): array {
		$envelope = $this->tools->invokeTool(toolId: 'openregister.flowRunStatus', arguments: ['runUuid' => $runUuid]);
		if (($envelope['isError'] ?? true) === true || is_array($envelope['result'] ?? null) === false) {
			$this->logger->warning('[FlowHandoffs] The run could not be read', ['runUuid' => $runUuid]);
			return [];
		}

		return $envelope['result'];
	}//end runOf()

	/**
	 * What the user sees the work called: the model's label when it gave one, else the flow id.
	 *
	 * @param array<string, mixed> $arguments The runFlow arguments.
	 * @param string               $flowId    The flow.
	 *
	 * @return string
	 */
	private function labelOf(array $arguments, string $flowId): string {
		$label = trim((string) ($arguments['label'] ?? ''));
		if ($label !== '') {
			return $label;
		}

		if ($flowId !== '') {
			return $flowId;
		}

		return 'flow';
	}//end labelOf()
}//end class
