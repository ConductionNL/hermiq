<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Hermiq\Service\RemoteAgent
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\RemoteAgent;

use OCA\Hermiq\Service\Handoff\FlowTurn;
use OCA\Hermiq\Service\Handoff\HandoffService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tasks handed to remote agents: asking one, and following a task that is still working.
 *
 * `ask()` backs the `hermiq.askRemoteAgent` tool: a completed answer comes
 * back as text at once; a task still `working` is answered with its id, and
 * `afterAsk()` (run inside the turn, which knows its agent, conversation and
 * owner) opens an `a2a` handoff for it. `poll()` asks `tasks/get` through the
 * same source until the task ends or the handoff's deadline passes.
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003
 */
class RemoteTasks {

	/**
	 * What the model is told when a remote task keeps working.
	 */
	public const WORKING_NOTE = 'The remote agent is still working. Its answer will arrive in this conversation.';

	/**
	 * Constructor.
	 *
	 * @param RemoteAgentService $remoteAgents The registered remote agents.
	 * @param A2aClient          $a2a          Speaks A2A through integriq.
	 * @param HandoffService     $handoffs     The handoff record.
	 * @param LoggerInterface    $logger       The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003
	 */
	public function __construct(
		private readonly RemoteAgentService $remoteAgents,
		private readonly A2aClient $a2a,
		private readonly HandoffService $handoffs,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send a task to a registered, switched-on remote agent.
	 *
	 * @param string $remoteAgent The remote agent's handle.
	 * @param string $task        The task text.
	 *
	 * @return array<string, mixed> `{status: completed, answer}`, `{status: working, taskId, remoteAgent, label}`,
	 *                              or `{error, message}`.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003
	 */
	public function ask(string $remoteAgent, string $task): array {
		$agent = $this->remoteAgents->findBySlug(slug: trim($remoteAgent));
		if ($agent === null || ($agent['enabled'] ?? false) !== true) {
			return ['error' => 'remote_agent_unavailable', 'message' => sprintf('No switched-on remote agent "%s".', $remoteAgent)];
		}

		if (trim($task) === '') {
			return ['error' => 'task_missing', 'message' => 'Say what the remote agent should do.'];
		}

		try {
			$answer = $this->a2a->sendMessage(
				sourceId: (string) $agent['source'],
				text: $task,
				messageId: bin2hex(random_bytes(16))
			);
		} catch (RemoteAgentUnavailableException $e) {
			return ['error' => 'remote_agent_unreachable', 'message' => $e->getMessage()];
		}

		if ($answer['state'] === 'completed') {
			return ['status' => 'completed', 'remoteAgent' => $agent['slug'], 'answer' => $answer['text']];
		}

		if (in_array($answer['state'], A2aClient::TERMINAL_STATES, true) === true || $answer['taskId'] === '') {
			$message = $answer['text'];
			if ($message === '') {
				$message = 'The remote agent did not carry out the task.';
			}

			return ['error' => 'remote_task_' . $answer['state'], 'message' => $message];
		}

		return ['status' => 'working', 'remoteAgent' => $agent['slug'], 'label' => (string) $agent['name'], 'taskId' => $answer['taskId']];
	}//end ask()

	/**
	 * After the tool answered a working task in a turn: open its a2a handoff and say so.
	 *
	 * @param mixed    $result The tool's result.
	 * @param FlowTurn $turn   The agent, conversation and owner of the turn.
	 *
	 * @return mixed The result, with `handoff` and `note` for a working task.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003
	 */
	public function afterAsk(mixed $result, FlowTurn $turn): mixed {
		if (is_array($result) === false || ($result['status'] ?? null) !== 'working' || $turn->isComplete() === false) {
			return $result;
		}

		try {
			$handoff = $this->handoffs->open(
				handoff: [
					'kind'           => 'a2a',
					'agentId'        => $turn->agentId,
					'conversationId' => $turn->conversationId,
					'requestedBy'    => $turn->owner,
					'label'          => (string) ($result['label'] ?? $result['remoteAgent']),
					'target'         => (string) $result['remoteAgent'],
					'externalRef'    => (string) $result['taskId'],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('[RemoteTasks] The a2a handoff could not be opened: ' . $e->getMessage());
			return $result;
		}

		$result['handoff'] = (string) ($handoff['id'] ?? '');
		$result['note']    = self::WORKING_NOTE;

		return $result;
	}//end afterAsk()

	/**
	 * Ask a waiting a2a handoff's task once: close it when the task ended, else leave it waiting.
	 *
	 * @param string               $handoffId The handoff.
	 * @param array<string, mixed> $handoff   Its stored data.
	 *
	 * @return string `done`, `failed`, `waiting`, or `unreachable` when the remote agent could not be asked.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003
	 */
	public function poll(string $handoffId, array $handoff): string {
		$agent = $this->remoteAgents->findBySlug(slug: (string) ($handoff['target'] ?? ''));
		if ($agent === null) {
			$this->handoffs->close(handoffId: $handoffId, status: 'failed', result: ['message' => 'The remote agent was removed.']);
			return 'failed';
		}

		try {
			$task = $this->a2a->getTask(sourceId: (string) $agent['source'], taskId: (string) ($handoff['externalRef'] ?? ''));
		} catch (RemoteAgentUnavailableException $e) {
			$this->logger->info('[RemoteTasks] The remote task could not be asked; trying again later', ['handoffId' => $handoffId]);
			return 'unreachable';
		}

		if ($task['state'] === 'completed') {
			$this->handoffs->close(handoffId: $handoffId, status: 'done', result: ['answer' => $task['text']]);
			return 'done';
		}

		if (in_array($task['state'], A2aClient::TERMINAL_STATES, true) === true) {
			$this->handoffs->close(handoffId: $handoffId, status: 'failed', result: ['state' => $task['state'], 'message' => $task['text']]);
			return 'failed';
		}

		return 'waiting';
	}//end poll()
}//end class
