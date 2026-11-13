<?php

/**
 * Hermiq AgentMemoryTools (agent-memory-tools).
 *
 * The three self-editing memory tools (`hermiq.rememberMemory`,
 * `hermiq.recallMemory`, `hermiq.forgetMemory`) that HermiqToolProvider
 * dispatches here: an agent decides, mid-run, to write, search or retract its
 * own durable memory. Every call is scoped to the acting user and requires the
 * run-injected `agentId`; nothing here throws for a bad input, it returns a
 * structured error envelope.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
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
 * @spec openspec/specs/agent-memory/spec.md#requirement-agent-self-service-memory-write-tool
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\MemoryService;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The agent self-service memory tools of the hermiq MCP provider.
 *
 * @spec openspec/specs/agent-memory/spec.md#requirement-agent-self-service-memory-write-tool
 */
final class AgentMemoryTools {

	/**
	 * Builds the error envelopes these tools return.
	 *
	 * @var ToolErrorEnvelope
	 */
	private readonly ToolErrorEnvelope $errors;

	/**
	 * Build the memory tools over the agent memory store.
	 *
	 * @param MemoryService $memoryService The agent memory store.
	 */
	public function __construct(
		private readonly MemoryService $memoryService,
	) {
		$this->errors = new ToolErrorEnvelope();
	}//end __construct()

	/**
	 * Run the memory tool $toolId names.
	 *
	 * @param string $toolId The namespaced tool id.
	 * @param NativeToolCall $call The acting user and arguments.
	 *
	 * @return array<string, mixed>|null The result, or null when $toolId is not a memory tool.
	 *
	 * @spec openspec/specs/agent-memory/spec.md#requirement-agent-self-service-memory-write-tool
	 */
	public function invoke(string $toolId, NativeToolCall $call): ?array {
		return match ($toolId) {
			Application::APP_ID . '.rememberMemory' => $this->rememberMemory(uid: $call->uid, arguments: $call->arguments),
			Application::APP_ID . '.recallMemory' => $this->recallMemory(uid: $call->uid, arguments: $call->arguments),
			Application::APP_ID . '.forgetMemory' => $this->forgetMemory(uid: $call->uid, arguments: $call->arguments),
			default => null,
		};
	}//end invoke()

	/**
	 * Append a durable fact to the agent's own Memory (`scope: agent`) or to the
	 * acting user's UserProfile for this agent (`scope: user`) — agent-memory-tools'
	 * self-service write. Delegates verbatim to `MemoryService::appendMemoryEntry()`/
	 * `appendUserProfileEntry()`, which redacts `content` before persist
	 * (`MemoryService::appendEntry()`).
	 *
	 * @param string $uid The acting user id (IDOR: `scope: user`
	 *                    only ever writes to THIS user's own
	 *                    UserProfile, never a caller-supplied
	 *                    `subjectUid`).
	 * @param array<string, mixed> $arguments The tool arguments (`content`, `scope`,
	 *                                        plus the run-injected `agentId` — see
	 *                                        `FacadeToolInvoker::withAgentId()`).
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/specs/agent-memory/spec.md#requirement-agent-self-service-memory-write-tool
	 */
	private function rememberMemory(string $uid, array $arguments): array {
		$agentId = trim((string)($arguments['agentId'] ?? ''));
		if ($agentId === '') {
			return $this->errors->noAgentContext();
		}

		$content = trim((string)($arguments['content'] ?? ''));
		if ($content === '') {
			return $this->errors->build(code: 'invalid_argument', message: 'A non-empty content is required.');
		}

		$scope = (string)($arguments['scope'] ?? '');
		if (in_array($scope, ['agent', 'user'], true) === false) {
			return $this->errors->build(code: 'invalid_argument', message: 'scope must be "agent" or "user".');
		}

		if ($scope === 'user') {
			$memory = $this->memoryService->appendUserProfileEntry(agentId: $agentId, subjectUid: $uid, text: $content);
			return $this->rememberedResult(scope: $scope, memory: $memory);
		}

		$memory = $this->memoryService->appendMemoryEntry(agentId: $agentId, text: $content);
		return $this->rememberedResult(scope: $scope, memory: $memory);
	}//end rememberMemory()

	/**
	 * Shape a persisted Memory/UserProfile object into `rememberMemory`'s result
	 * payload, surfacing the newly-appended entry's id.
	 *
	 * @param string $scope The scope the entry was appended to (`agent`|`user`).
	 * @param ObjectEntity $memory The persisted Memory/UserProfile object.
	 *
	 * @return array<string, mixed> The result payload.
	 */
	private function rememberedResult(string $scope, ObjectEntity $memory): array {
		$data = $memory->getObject();
		$entries = (array)($data['entries'] ?? []);
		$newest = end($entries);

		$entryId = null;
		if (is_array($newest) === true) {
			$entryId = ($newest['id'] ?? null);
		}

		return [
			'remembered' => true,
			'scope' => $scope,
			'entryId' => $entryId,
			'needsConsolidation' => (bool)($data['needsConsolidation'] ?? false),
		];

	}//end rememberedResult()

	/**
	 * Search the agent's own Memory/UserProfile entries and past SessionTurns for a
	 * query — agent-memory-tools' self-service recall. Reuses the SAME OpenRegister
	 * search substrate `recallSessions()` already uses (`MemoryService::recallEntries()`
	 * + `recallSessions()`, merged into one result) — no second search index.
	 *
	 * @param string $uid The acting user id (whose own UserProfile
	 *                    is also searched).
	 * @param array<string, mixed> $arguments The tool arguments (`query`, plus the
	 *                                        run-injected `agentId`).
	 *
	 * @return array<string, mixed> The combined result, or an error envelope.
	 *
	 * @spec openspec/specs/agent-memory/spec.md#requirement-agent-self-service-memory-recall-tool
	 */
	private function recallMemory(string $uid, array $arguments): array {
		$agentId = trim((string)($arguments['agentId'] ?? ''));
		if ($agentId === '') {
			return $this->errors->noAgentContext();
		}

		$query = trim((string)($arguments['query'] ?? ''));
		if ($query === '') {
			return $this->errors->build(code: 'invalid_argument', message: 'A non-empty query is required.');
		}

		$entries = $this->memoryService->recallEntries(agentId: $agentId, subjectUid: $uid, query: $query);
		$turns = $this->memoryService->recallSessions(agentId: $agentId, query: $query);

		$sessionTurns = [];
		foreach ($turns as $turn) {
			$sessionTurns[] = $this->shapeSessionTurn(turn: $turn);
		}

		return [
			'query' => $query,
			'memoryEntries' => $entries['memoryEntries'],
			'userProfileEntries' => $entries['userProfileEntries'],
			'sessionTurns' => $sessionTurns,
		];

	}//end recallMemory()

	/**
	 * Soft-delete one memory entry by id — agent-memory-tools' self-service forget.
	 * Scoped to the agent's own Memory and the ACTING user's own UserProfile only
	 * (IDOR: never a caller-supplied `subjectUid`). Never a hard delete
	 * (`MemoryService::forgetEntry()`); an unknown id is a structured not-found
	 * result, never a thrown error.
	 *
	 * @param string $uid The acting user id.
	 * @param array<string, mixed> $arguments The tool arguments (`id`, plus the
	 *                                        run-injected `agentId`).
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/specs/agent-memory/spec.md#requirement-agent-self-service-memory-forget-tool-soft-delete-only
	 */
	private function forgetMemory(string $uid, array $arguments): array {
		$agentId = trim((string)($arguments['agentId'] ?? ''));
		if ($agentId === '') {
			return $this->errors->noAgentContext();
		}

		$entryId = trim((string)($arguments['id'] ?? ''));
		if ($entryId === '') {
			return $this->errors->build(code: 'invalid_argument', message: 'A non-empty id is required.');
		}

		$result = $this->memoryService->forgetEntry(agentId: $agentId, subjectUid: $uid, entryId: $entryId);

		if ($result['found'] === false) {
			return ['found' => false, 'message' => 'No memory entry with that id was found.'];
		}

		return ['found' => true, 'scope' => $result['scope']];
	}//end forgetMemory()

	/**
	 * Shape a SessionTurn ObjectEntity into the recall result's turn payload.
	 *
	 * @param ObjectEntity $turn The SessionTurn object.
	 *
	 * @return array<string, string> The shaped turn.
	 */
	private function shapeSessionTurn(ObjectEntity $turn): array {
		$data = $turn->getObject();

		return [
			'role' => (string)($data['role'] ?? ''),
			'content' => (string)($data['content'] ?? ''),
			'createdAt' => (string)($data['createdAt'] ?? ''),
		];

	}//end shapeSessionTurn()
}//end class
