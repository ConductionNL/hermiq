<?php

/**
 * Hermiq InstructionVariablesService.
 *
 * Two things around an agent's placeholders (agents-instruction-variables):
 * the owner's preview of the instructions as they will reach the model, and
 * the answers a person gives to the agent's start fields before the first
 * message of a session.
 *
 * The preview is the owner's alone; anyone else gets a 404, whether or not they
 * may use the agent. Answers are set once per session by the person whose
 * session it is, checked against the agent's fields and passed through the
 * organisation's input filter, because they reach the model inside the
 * instructions.
 *
 * Errors are RuntimeExceptions whose code is the HTTP status the controller
 * answers with (404, 409, 422), as AgentGitService does.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Agent
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
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Agent;

use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\Engine\PromptVariableResolver;
use OCA\Hermiq\Service\Engine\SanitizesForSaveTrait;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use RuntimeException;

/**
 * The owner's preview and a session's start field answers.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
 */
class InstructionVariablesService {
	use SanitizesForSaveTrait;

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * The session schema slug.
	 */
	private const SESSION_SCHEMA = 'agentsession';

	/**
	 * The agent schema slug.
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * Constructor.
	 *
	 * @param ObjectService          $objectService OpenRegister object store.
	 * @param AgentAccessService     $agentAccess   Who may see and who owns an agent.
	 * @param PromptVariableResolver $resolver      The placeholder values.
	 * @param GuardrailPolicyService $guardrails    The organisation's input filter.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly AgentAccessService $agentAccess,
		private readonly PromptVariableResolver $resolver,
		private readonly GuardrailPolicyService $guardrails,
	) {
	}//end __construct()

	/**
	 * The instructions filled in for the owner, with sample answers.
	 *
	 * @param string                                $agentId      The agent.
	 * @param string                                $uid          The caller.
	 * @param string|null                           $prompt       The form's unsaved instructions, or null for the stored ones.
	 * @param array<int, array<string, mixed>>|null $startFields  The form's unsaved fields, or null for the stored ones.
	 * @param array<string, mixed>                  $sampleValues Sample answers per field key.
	 *
	 * @return array{text: string, unknown: array<int, string>}
	 *
	 * @throws RuntimeException 404 when the caller does not own the agent.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
	 */
	public function preview(string $agentId, string $uid, ?string $prompt, ?array $startFields, array $sampleValues): array {
		$agent = $this->agentAccess->loadAccessibleAgent(agentId: $agentId, userId: $uid);
		if ($agent === null || $this->agentAccess->canUserModifyAgent(agent: $agent, userId: $uid) === false) {
			throw new RuntimeException('Agent not found', 404);
		}

		$data = $agent->getObject();
		if ($prompt !== null) {
			$data['prompt'] = $prompt;
		}

		if ($startFields !== null) {
			$data['startFields'] = $startFields;
		}

		$variables = $this->resolver->variablesFor(
			userId: $uid,
			agentData: $data,
			organisation: (string)($agent->getOrganisation() ?? ''),
			startValues: $sampleValues
		);
		$text = (string)($data['prompt'] ?? '');

		return [
			'text' => PromptVariableResolver::fill(prompt: $text, variables: $variables),
			'unknown' => PromptVariableResolver::unknown(prompt: $text, variables: $variables),
		];
	}//end preview()

	/**
	 * Store the person's answers on their session, once.
	 *
	 * @param string               $sessionId The session.
	 * @param string               $uid       The caller.
	 * @param array<string, mixed> $values    The answers per field key.
	 *
	 * @return ObjectEntity The saved session.
	 *
	 * @throws RuntimeException 404 when it is not the caller's session, 409 when it already has answers.
	 * @throws StartValuesRejectedException 422 when an answer is missing, of the wrong kind or blocked.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
	 */
	public function answer(string $sessionId, string $uid, array $values): ObjectEntity {
		$session = $this->objectService->find(id: $sessionId, register: self::REGISTER_SLUG, schema: self::SESSION_SCHEMA);
		if ($session === null || (string)($session->getObject()['userId'] ?? '') !== $uid || $uid === '') {
			throw new RuntimeException('Session not found', 404);
		}

		$data = $session->getObject();
		if (empty($data['startValues']) === false) {
			throw new RuntimeException('This session already has its answers.', 409);
		}

		$fields = StartFields::of(agentData: $this->agentData(agentId: (string)($data['agentId'] ?? '')));
		$problems = StartFields::problems(fields: $fields, values: $values);
		if ($problems !== []) {
			throw new StartValuesRejectedException(problems: $problems);
		}

		$answers = $this->filtered(
			answers: StartFields::clean(fields: $fields, values: $values),
			organisation: (string)($session->getOrganisation() ?? '')
		);
		if ($answers === []) {
			return $session;
		}

		$data['startValues'] = $answers;

		return $this->objectService->saveObject(
			object: $this->sanitizeForSave(data: $data),
			register: self::REGISTER_SLUG,
			schema: self::SESSION_SCHEMA,
			uuid: $sessionId
		);
	}//end answer()

	/**
	 * The session's agent's data, read as the system (the session already proves access).
	 *
	 * @param string $agentId The agent.
	 *
	 * @return array<string, mixed>
	 */
	private function agentData(string $agentId): array {
		if ($agentId === '') {
			return [];
		}

		$agent = $this->objectService->find(id: $agentId, register: self::REGISTER_SLUG, schema: self::AGENT_SCHEMA, _rbac: false);
		if ($agent === null) {
			return [];
		}

		return $agent->getObject();
	}//end agentData()

	/**
	 * The answers through the organisation's input filter.
	 *
	 * @param array<string, string> $answers      The answers.
	 * @param string                $organisation The session's organisation.
	 *
	 * @return array<string, string>
	 *
	 * @throws StartValuesRejectedException When the filter blocks an answer.
	 */
	private function filtered(array $answers, string $organisation): array {
		$policy = $this->guardrails->effectivePolicyFor(organisation: $organisation);

		$blocked = [];
		foreach ($answers as $key => $answer) {
			$filter = $this->guardrails->filterInput(policy: $policy, text: $answer);
			if ($filter['blocked'] === true) {
				$blocked[$key] = (string)$filter['reason'];
				continue;
			}

			$answers[$key] = (string)$filter['text'];
		}

		if ($blocked !== []) {
			throw new StartValuesRejectedException(problems: $blocked);
		}

		return $answers;
	}//end filtered()
}//end class
