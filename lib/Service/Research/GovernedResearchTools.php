<?php

/**
 * Hermiq GovernedResearchTools.
 *
 * Opens a research run's toolbox on the SAME governed chain a chat turn uses
 * (design D2): the agent's granted functions from `ToolLoop::listAgentFunctions()`,
 * bound to one `FacadeToolInvoker` by `ToolLoop::buildFunctionInfos()`. Every
 * search and fetch therefore passes the grants, the guardrail policy, the
 * egress guard, tracing and redaction exactly as in chat. A tool the agent was
 * not granted is simply not listed, so the toolbox is not opened.
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
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Research;

use LLPhant\Chat\FunctionInfo\FunctionInfo;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Opens a research toolbox over the agent's governed tool chain.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
 */
class GovernedResearchTools implements ResearchTools {

	/**
	 * The search tool's registry id.
	 */
	public const SEARCH = 'hermiq.webSearch';

	/**
	 * The fetch tool's registry id.
	 */
	public const FETCH = 'hermiq.webFetch';

	/**
	 * Constructor.
	 *
	 * @param ToolLoop      $toolLoop      Lists the agent's granted functions and binds them to the governed invoker.
	 * @param ObjectService $objectService Reads the agent.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ToolLoop $toolLoop,
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The toolbox for this agent, or null when it may not both search and read the web.
	 *
	 * @param string $agentId        The agent running the research.
	 * @param string $organisation   The run's organisation, whose tool policy applies.
	 * @param string $conversationId The conversation the run belongs to.
	 *
	 * @return ResearchToolbox|null The toolbox.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
	 */
	public function open(string $agentId, string $organisation, string $conversationId): ?ResearchToolbox {
		$agent = $this->agent(agentId: $agentId);
		if ($agent === null) {
			return null;
		}

		$functions = $this->researchFunctions(
			functions: $this->toolLoop->listAgentFunctions(agent: $agent, selectedTools: [self::SEARCH, self::FETCH])
		);
		if (count($functions) !== 2) {
			return null;
		}

		$infos = $this->toolLoop->buildFunctionInfos(
			functions: array_values($functions),
			agent: $agent,
			organisation: $organisation,
			conversationId: $conversationId
		);

		$search = $this->infoNamed(infos: $infos, name: (string)$functions[self::SEARCH]['name']);
		$fetch  = $this->infoNamed(infos: $infos, name: (string)$functions[self::FETCH]['name']);
		if ($search === null || $fetch === null) {
			return null;
		}

		return new InvokerToolbox(search: $search, fetch: $fetch);
	}//end open()

	/**
	 * The search and fetch descriptors among the agent's functions, by registry id.
	 *
	 * @param array<int, mixed> $functions The agent's flattened function descriptors.
	 *
	 * @return array<string, array<string, mixed>> The two descriptors that were listed.
	 */
	private function researchFunctions(array $functions): array {
		$found = [];
		foreach ($functions as $function) {
			if (is_array($function) === false || isset($function['name']) === false) {
				continue;
			}

			// The same id resolution ToolLoop applies: the registry id, else the name.
			$toolId = (string)($function['mcpId'] ?? $function['name']);
			if ($toolId === self::SEARCH || $toolId === self::FETCH) {
				$found[$toolId] = $function;
			}
		}

		return $found;
	}//end researchFunctions()

	/**
	 * The bound function of this name, or null.
	 *
	 * @param array<int, mixed> $infos The bound functions.
	 * @param string            $name  The function name.
	 *
	 * @return FunctionInfo|null
	 */
	private function infoNamed(array $infos, string $name): ?FunctionInfo {
		foreach ($infos as $info) {
			if ($info instanceof FunctionInfo && $info->name === $name) {
				return $info;
			}
		}

		return null;
	}//end infoNamed()

	/**
	 * The agent, or null when it cannot be read.
	 *
	 * @param string $agentId The agent's uuid.
	 *
	 * @return ObjectEntity|null
	 */
	private function agent(string $agentId): ?ObjectEntity {
		if ($agentId === '') {
			return null;
		}

		try {
			$agent = $this->objectService->find(
				id: $agentId,
				options: new ObjectFindOptions(register: 'hermiq', schema: 'agent')
			);
		} catch (Throwable $e) {
			return null;
		}

		if (($agent instanceof ObjectEntity) === false) {
			return null;
		}

		return $agent;
	}//end agent()
}//end class
