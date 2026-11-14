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

use OCA\Hermiq\Support\FleetAppId;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Speaks A2A (JSON-RPC 2.0 over HTTP) to a remote agent, through integriq only.
 *
 * Every request goes through integriq's `CallService::call()` on the remote
 * agent's integriq source, which holds the server's address and credential.
 * This class opens no HTTP connection itself (nc-native-tools). It builds
 * the request, reads the call log's response, and maps an A2A answer onto
 * three things hermiq needs: the task state, its text and its id.
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003
 */
class A2aClient {

	/**
	 * Where a remote agent publishes its card, relative to the source's address.
	 */
	public const CARD_PATH = '/.well-known/agent-card.json';

	/**
	 * A2A task states after which a task never changes again.
	 */
	public const TERMINAL_STATES = ['completed', 'failed', 'canceled', 'rejected'];

	/**
	 * The integriq register and schema a source lives in.
	 */
	private const SOURCE_REGISTER = 'integriq';

	/**
	 * The integriq source schema.
	 */
	private const SOURCE_SCHEMA = 'source';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container     Resolves integriq's CallService lazily.
	 * @param ObjectService      $objectService Reads the integriq source the call goes through.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Fetch the remote agent card through the source.
	 *
	 * @param string $sourceId The integriq source.
	 *
	 * @return array<string, mixed> The card.
	 *
	 * @throws RemoteAgentUnavailableException When the card cannot be fetched.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
	 */
	public function fetchCard(string $sourceId): array {
		$card = $this->request(sourceId: $sourceId, endpoint: self::CARD_PATH, method: 'GET', body: null);
		if (is_string($card['name'] ?? null) === false) {
			throw new RemoteAgentUnavailableException('The source did not answer with an agent card.');
		}

		return $card;
	}//end fetchCard()

	/**
	 * Send a task as an A2A `message/send`.
	 *
	 * @param string $sourceId  The integriq source.
	 * @param string $text      The task.
	 * @param string $messageId A unique id for this message.
	 *
	 * @return array{state: string, text: string, taskId: string}
	 *
	 * @throws RemoteAgentUnavailableException When the remote agent cannot be reached or refuses.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003
	 */
	public function sendMessage(string $sourceId, string $text, string $messageId): array {
		$answer = $this->rpc(
			sourceId: $sourceId,
			method: 'message/send',
			params: [
				'message' => [
					'kind'      => 'message',
					'role'      => 'user',
					'messageId' => $messageId,
					'parts'     => [['kind' => 'text', 'text' => $text]],
				],
			]
		);

		return self::readResult(result: $answer);
	}//end sendMessage()

	/**
	 * Ask a remote task's state with an A2A `tasks/get`.
	 *
	 * @param string $sourceId The integriq source.
	 * @param string $taskId   The remote task.
	 *
	 * @return array{state: string, text: string, taskId: string}
	 *
	 * @throws RemoteAgentUnavailableException When the remote agent cannot be reached or refuses.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003
	 */
	public function getTask(string $sourceId, string $taskId): array {
		return self::readResult(result: $this->rpc(sourceId: $sourceId, method: 'tasks/get', params: ['id' => $taskId]));
	}//end getTask()

	/**
	 * Read an A2A result: a direct message is a completed answer; a task carries its state.
	 *
	 * @param array<string, mixed> $result The JSON-RPC result.
	 *
	 * @return array{state: string, text: string, taskId: string}
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003
	 */
	public static function readResult(array $result): array {
		if (($result['kind'] ?? null) === 'message') {
			return ['state' => 'completed', 'text' => self::textOf(parts: ($result['parts'] ?? [])), 'taskId' => ''];
		}

		$state = (string) ($result['status']['state'] ?? 'unknown');
		$texts = [];
		foreach ((array) ($result['artifacts'] ?? []) as $artifact) {
			if (is_array($artifact) === true) {
				$texts[] = self::textOf(parts: ($artifact['parts'] ?? []));
			}
		}

		if (array_filter($texts) === []) {
			$texts = [self::textOf(parts: ($result['status']['message']['parts'] ?? []))];
		}

		return [
			'state'  => $state,
			'text'   => trim(implode("\n", array_filter($texts))),
			'taskId' => (string) ($result['id'] ?? ''),
		];
	}//end readResult()

	/**
	 * The text parts of an A2A message or artifact, joined.
	 *
	 * @param mixed $parts The parts.
	 *
	 * @return string
	 */
	private static function textOf(mixed $parts): string {
		$texts = [];
		foreach ((array) $parts as $part) {
			if (is_array($part) === true && is_string($part['text'] ?? null) === true) {
				$texts[] = $part['text'];
			}
		}

		return trim(implode("\n", $texts));
	}//end textOf()

	/**
	 * One JSON-RPC call, answering its result.
	 *
	 * @param string               $sourceId The integriq source.
	 * @param string               $method   The A2A method.
	 * @param array<string, mixed> $params   Its params.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RemoteAgentUnavailableException When the call fails or answers an error.
	 */
	private function rpc(string $sourceId, string $method, array $params): array {
		$answer = $this->request(
			sourceId: $sourceId,
			endpoint: '',
			method: 'POST',
			body: ['jsonrpc' => '2.0', 'id' => bin2hex(random_bytes(8)), 'method' => $method, 'params' => $params]
		);
		if (is_array($answer['error'] ?? null) === true) {
			throw new RemoteAgentUnavailableException('The remote agent refused: ' . (string) ($answer['error']['message'] ?? 'unknown error'));
		}

		if (is_array($answer['result'] ?? null) === false) {
			throw new RemoteAgentUnavailableException('The remote agent answered without a result.');
		}

		return $answer['result'];
	}//end rpc()

	/**
	 * One request through integriq, answering the decoded JSON body.
	 *
	 * @param string                    $sourceId The integriq source.
	 * @param string                    $endpoint The path on the source.
	 * @param string                    $method   The HTTP method.
	 * @param array<string, mixed>|null $body     The JSON body, or null.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RemoteAgentUnavailableException When integriq, the source or the answer is missing.
	 */
	private function request(string $sourceId, string $endpoint, string $method, ?array $body): array {
		$source = $this->source(sourceId: $sourceId);
		$caller = FleetAppId::getService(container: $this->container, canonical: 'integriq', relative: 'Service\CallService');
		if ($source === null || $caller === null) {
			throw new RemoteAgentUnavailableException('The remote agent cannot be reached: its integriq source is not available.');
		}

		$config = ['headers' => ['Accept' => 'application/json']];
		if ($body !== null) {
			$config['body'] = (string) json_encode($body);
			$config['headers']['Content-Type'] = 'application/json';
		}

		try {
			$log = $caller->call(source: $source, endpoint: $endpoint, method: $method, config: $config);
		} catch (Throwable $e) {
			throw new RemoteAgentUnavailableException('The remote agent could not be reached: ' . $e->getMessage());
		}

		$response = [];
		if ($log instanceof ObjectEntity) {
			$response = (array) ($log->getObject()['response'] ?? []);
		}

		$status = (int) ($response['statusCode'] ?? 0);
		if ($status < 200 || $status >= 300) {
			throw new RemoteAgentUnavailableException(sprintf('The remote agent answered HTTP %d.', $status));
		}

		$raw = (string) ($response['body'] ?? '');
		if (($response['encoding'] ?? '') === 'base64') {
			$raw = (string) base64_decode($raw, true);
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			throw new RemoteAgentUnavailableException('The remote agent did not answer JSON.');
		}

		return $decoded;
	}//end request()

	/**
	 * The integriq source, read as the system: hermiq hands it to integriq and keeps nothing of it.
	 *
	 * @param string $sourceId The source's uuid.
	 *
	 * @return ObjectEntity|null
	 */
	private function source(string $sourceId): ?ObjectEntity {
		if ($sourceId === '') {
			return null;
		}

		$source = $this->objectService->find(
			id: $sourceId,
			register: self::SOURCE_REGISTER,
			schema: self::SOURCE_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);
		if (($source instanceof ObjectEntity) === false) {
			return null;
		}

		return $source;
	}//end source()
}//end class
