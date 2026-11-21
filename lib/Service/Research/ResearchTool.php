<?php

/**
 * Hermiq ResearchTool.
 *
 * The `hermiq.startResearch` tool (design D6): an agent or a schedule starts a
 * deep research run in the conversation it runs in. It writes into the acting
 * person's Files, so it reaches `user` and is a create: default-denied like any
 * write tool. The research itself reaches outside only through `webSearch` and
 * `webFetch`, which carry their own reach. The agent and the conversation are
 * injected by FacadeToolInvoker, never chosen by the model.
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

use InvalidArgumentException;
use OCA\Hermiq\Mcp\NativeToolCall;
use OCA\OpenRegister\Service\Capability\ToolReachResolver;

/**
 * Declares and runs `hermiq.startResearch`.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */
final class ResearchTool {

	/**
	 * The tool's registry id.
	 */
	public const ID = 'hermiq.startResearch';

	/**
	 * The tool's descriptor.
	 *
	 * @var list<array<string, mixed>>
	 */
	public const DESCRIPTORS = [
		[
			'id'              => self::ID,
			'subject'         => 'research',
			'action'          => 'start',
			'reach'           => ToolReachResolver::REACH_USER,
			'name'            => 'Start deep research',
			'description'     => 'Start a deep research run on a question in this conversation. It runs in the background, '
				. 'posts its plan here first, reads the web through this agent\'s own search and fetch tools, and '
				. 'saves a cited report in the person\'s Files. Use it for questions that need many sources.',
			'inputSchema'     => [
				'type'       => 'object',
				'properties' => [
					'question' => ['type' => 'string', 'description' => 'The research question.'],
					'depth'    => [
						'type'        => 'string',
						'enum'        => ['quick', 'thorough'],
						'description' => 'quick halves every cap; thorough (the default) keeps them.',
					],
				],
				'required'   => ['question'],
			],
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => false,
			'scope'           => 'create',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param ResearchStarter|null $starter Starts the run; null when research is not wired.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?ResearchStarter $starter,
	) {
	}//end __construct()

	/**
	 * Run the tool, or null when the id is not this tool's.
	 *
	 * @param string         $toolId The namespaced tool id.
	 * @param NativeToolCall $call   The acting person and the arguments, with the injected agent and conversation.
	 *
	 * @return array<string, mixed>|null The started run or an error envelope; null for another tool.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	public function invoke(string $toolId, NativeToolCall $call): ?array {
		if ($toolId !== self::ID) {
			return null;
		}

		if ($this->starter === null) {
			return self::error(code: 'unavailable', message: 'Deep research is not available on this instance.');
		}

		try {
			$run = $this->starter->start(request: $this->request(call: $call), userId: $call->uid);
		} catch (InvalidArgumentException $e) {
			return self::error(code: 'invalid_argument', message: $e->getMessage());
		}

		if ($run === null) {
			return self::error(code: 'not_found', message: 'The agent or the conversation cannot be read.');
		}

		return [
			'researchId' => $run['id'],
			'status'     => $run['status'],
			'message'    => 'Research started. The plan appears in this conversation, then the report.',
		];
	}//end invoke()

	/**
	 * The request of this call: the injected agent and conversation, the question, the depth (thorough by default).
	 *
	 * @param NativeToolCall $call The call.
	 *
	 * @return ResearchRequest
	 *
	 * @throws InvalidArgumentException When a part is missing or the depth is unknown.
	 */
	private function request(NativeToolCall $call): ResearchRequest {
		$depth = $call->text(name: 'depth');
		if ($depth === '') {
			$depth = 'thorough';
		}

		return new ResearchRequest(
			agentId: $call->agentId,
			conversationId: $call->text(name: 'conversationId'),
			question: $call->text(name: 'question'),
			depth: $depth
		);
	}//end request()

	/**
	 * The error envelope.
	 *
	 * @param string $code    The error code.
	 * @param string $message The message.
	 *
	 * @return array{error: array{code: string, message: string}}
	 */
	private static function error(string $code, string $message): array {
		return ['error' => ['code' => $code, 'message' => $message]];
	}//end error()
}//end class
