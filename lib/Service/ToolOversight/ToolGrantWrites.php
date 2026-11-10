<?php

/**
 * Hermiq ToolGrantWrites.
 *
 * The writes of the tool-oversight endpoints: an agent's `tools` grant list,
 * and the decision on a tool-access request the agent raised. The callers
 * check the acting user first; these methods only write.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\ToolOversight
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
 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/design.md#put-api-agents-agentid-tool-grants
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\ToolOversight;

use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\Capability\ToolGrantSet;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Persists grant changes and access-request decisions.
 *
 * @psalm-api
 *
 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/design.md#put-api-agents-agentid-tool-grants
 */
class ToolGrantWrites {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug for agent objects.
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object read/write (single write-path).
	 * @param ToolAccessRequestService $accessRequests Raises and resolves an agent's requests
	 *                                                 for tools it has not been granted.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ToolAccessRequestService $accessRequests,
	) {
	}//end __construct()

	/**
	 * Write an agent's grant payload.
	 *
	 * An added or removed approval waiver is audited by AgentWaiverChangeListener
	 * from the object event this save raises, as for every other write to an agent.
	 *
	 * @param array<string, mixed> $payload The payload from {@see self::grantWritePayload()}.
	 * @param string $agentId The agent id the request named, written as the uuid.
	 *
	 * @return array<int|string, mixed> The tools as saved.
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/design.md#put-api-agents-agentid-tool-grants
	 */
	public function saveAgent(array $payload, string $agentId): array {
		$updated = $this->objectService->saveObject(
			object: $payload,
			register: self::REGISTER_SLUG,
			schema: self::AGENT_SCHEMA,
			uuid: $agentId
		);

		return ($updated->getObject()['tools'] ?? $payload['tools']);
	}//end saveAgent()

	/**
	 * Store the decision on a tool-access request, and tell the owner about a grant.
	 *
	 * @param array<string, mixed> $data The request data with its decision set.
	 * @param string $requestId The request uuid.
	 *
	 * @return void
	 *
	 * @throws Throwable When the decision cannot be stored.
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
	 */
	public function saveDecision(array $data, string $requestId): void {
		// System path: the schema admits only the request's owner, and the
		// caller checked that the acting user owns the agent the request is for.
		$this->objectService->saveObject(
			object: $data,
			register: self::REGISTER_SLUG,
			schema: 'toolAccessRequest',
			uuid: $requestId,
			_rbac: false
		);

		if (($data['status'] ?? '') === 'granted') {
			$this->accessRequests->notifyOwner(agentId: (string)($data['agentId'] ?? ''), toolId: (string)($data['toolId'] ?? ''), subject: 'granted');
		}
	}//end saveDecision()

	/**
	 * Load an access request, but only if it belongs to this agent.
	 *
	 * ⚠️ Returns null for "no such request" AND for "a request that belongs to
	 * someone else", deliberately — the caller turns both into the same 404 it
	 * uses for "not your agent". Distinguishing them would let a non-owner probe
	 * which requests exist by reading the difference between the two answers.
	 *
	 * @param string $requestId The request uuid.
	 * @param string $agentId The agent it must belong to.
	 *
	 * @return array<string, mixed>|null The request data, or null when it is not this agent's.
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
	 */
	public function findAccessRequest(string $requestId, string $agentId): ?array {
		try {
			$requestObject = $this->objectService->find(
				id: $requestId,
				options: new ObjectFindOptions(register: self::REGISTER_SLUG, schema: 'toolAccessRequest')
			);
		} catch (Throwable $e) {
			return null;
		}

		if ($requestObject === null) {
			return null;
		}

		$data = $requestObject->jsonSerialize();
		if (($data['agentId'] ?? '') !== $agentId) {
			return null;
		}

		return $data;
	}//end findAccessRequest()

	/**
	 * Add the tool of a granted request to its agent's grant list, idempotently; a refusal adds nothing.
	 *
	 * @param ObjectEntity $agent The agent object.
	 * @param array<string, mixed> $data The request data with its decision set; its
	 *                                   `agentId` was matched by {@see self::findAccessRequest()}.
	 *
	 * @return void
	 *
	 * @throws Throwable When the grant cannot be persisted. Deliberately NOT
	 *                   translated here: this helper widens an authorization
	 *                   boundary, so a failed write must not be reported as a
	 *                   success. The single caller
	 *                   (`resolveToolAccessRequest()`) turns it into a 500.
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
	 */
	public function grantRequestedTool(ObjectEntity $agent, array $data): void {
		if (($data['status'] ?? '') !== 'granted') {
			return;
		}

		$grants = ($agent->getObject()['tools'] ?? []);
		if (is_array($grants) === false) {
			$grants = [];
		}

		// Idempotent: approving the same request twice must not list the tool
		// twice, because the grant list is compared by value elsewhere.
		$toolId = (string)($data['toolId'] ?? '');
		if (in_array($toolId, $grants, true) === false) {
			$grants[] = $toolId;
		}

		$this->saveAgent(payload: $this->grantWritePayload(agent: $agent, grants: $grants), agentId: (string)($data['agentId'] ?? ''));
	}//end grantRequestedTool()

	/**
	 * Build the object payload for a grant write.
	 *
	 * The whole stored object is carried forward on purpose — `saveObject()` is
	 * PUT-semantic, so any field this endpoint omits is NULLED, and it must not
	 * clear the fields it does not manage. Three kinds of value are dropped
	 * anyway, because OpenRegister refuses them:
	 *
	 * 🔴 `@self` — OR's own metadata envelope. Feeding it back fails the schema
	 * resolver with `$ref must be a non-empty string`, a 500 on every grant
	 * write, for the OWNER, on a clean instance. It survived this long because
	 * the only client exercising the path is `AgentFormModal.vue`, which does
	 * the same strip in JavaScript (`delete base['@self']`) before calling —
	 * the endpoint was relying on its caller to sanitise its input, so the
	 * first caller that did not (an e2e test) broke it immediately.
	 *
	 * 🔴 `null` and `[]` — OR refuses BOTH `{}` and `null` for an object-typed
	 * property and wants the key OMITTED instead. Only reachable on an agent
	 * whose optional object fields were never populated, i.e. a freshly created
	 * one — exactly what a clean instance has and a long-lived dev instance
	 * does not. Omitting is safe under PUT semantics: an absent optional object
	 * and a stored empty one are the same state.
	 *
	 * `tools` is re-asserted AFTER the sweep because it is the one field this
	 * endpoint exists to write, and an intentional EMPTY grant list must
	 * survive — the sweep would otherwise silently drop it.
	 *
	 * @param ObjectEntity $agent The stored agent.
	 * @param array<int, string> $grants The grants to persist — the list of
	 *                                   grant strings `Agent.tools` declares,
	 *                                   produced by {@see self::storableGrants()}.
	 *
	 * @return array<string, mixed> The payload to hand to `saveObject()`.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-only-an-agents-owner-may-change-it
	 */
	public function grantWritePayload(ObjectEntity $agent, array $grants): array {
		$payload = $agent->getObject();
		unset($payload['@self']);

		foreach ($payload as $key => $value) {
			if ($value === null || $value === []) {
				unset($payload[$key]);
			}
		}

		$payload['tools'] = $grants;

		return $payload;
	}//end grantWritePayload()

	/**
	 * Normalise submitted grants into the shape `Agent.tools` actually declares.
	 *
	 * 🔴 A LIST OF GRANT STRINGS, AND THE SCHEMA IS THE REASON.
	 *
	 * The structure is real — `ToolGrantSet` is app => subject => action => tool
	 * id, and consumers read those coordinates instead of splitting an id — but
	 * it lives in the DOMAIN, not in storage. `Agent.tools` is declared
	 * `type: array`, and OpenRegister allows exactly ONE type per property: a
	 * union (`["array","object"]`) is rejected by its importer, and omitting
	 * `type` defaults the property to `string`, which is worse. Both were tried
	 * against a live instance.
	 *
	 * Writing the structure therefore failed validation on EVERY save —
	 * `Property 'tools' should be type 'array or null' but is 'object'` — so the
	 * owner got a 500 on a save that changed nothing. Reads still accept either
	 * shape ({@see ToolGrantSet::fromStored}), so an agent written structured by
	 * an earlier build keeps working; only the write converges on the declared
	 * shape.
	 *
	 * @param array<mixed> $rawGrants The submitted grants, in either shape.
	 *
	 * @return array<int, string> The grants as the schema declares them.
	 *
	 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#scenario-the-written-shape-is-the-one-the-schema-declares
	 */
	public function storableGrants(array $rawGrants): array {
		return ToolGrantSet::fromStored(stored: $rawGrants)->toGrantStrings();

	}//end storableGrants()
}//end class
