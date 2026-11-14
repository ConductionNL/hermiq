<?php

/**
 * Hermiq AnthropicChatRun.
 *
 * The inputs of one `ProviderFactory::callAnthropicChat()` turn, carried
 * together to the transport that serves it (the direct Messages API or the
 * hermiq-llm-runner CLI).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

/**
 * One Anthropic chat turn. Built and read only inside ProviderFactory.
 *
 * @spec openspec/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
 */
final class AnthropicChatRun {

	/**
	 * The Anthropic base URL.
	 *
	 * @var string
	 */
	public string $baseUrl = '';

	/**
	 * Array of LLPhant Message objects.
	 *
	 * @var array<int, mixed>
	 */
	public array $messageHistory = [];

	/**
	 * `api_key` or `oauth`.
	 *
	 * @var string
	 */
	public string $authMode = 'api_key';

	/**
	 * The output ceiling, or null for the default.
	 *
	 * @var int|null
	 */
	public ?int $maxTokens = null;

	/**
	 * OpenAI-style tool definitions.
	 *
	 * @var array<int, mixed>
	 */
	public array $functions = [];

	/**
	 * Runs a requested tool by (name, input), or null for a text-only turn.
	 *
	 * @var callable|null
	 */
	public mixed $toolExecutor = null;

	/**
	 * The acting agent's UUID, or null.
	 *
	 * @var string|null
	 */
	public ?string $agentId = null;

	/**
	 * The conversation this turn belongs to.
	 *
	 * @var string
	 */
	public string $conversationId = '';

	/**
	 * The tool calls this turn may make, or null for the default.
	 *
	 * @var int|null
	 */
	public ?int $maxToolCalls = null;

	/**
	 * Constructor.
	 *
	 * @param string $credentialId Broker credential UUID — NOT a secret.
	 * @param string $model        Model identifier.
	 */
	public function __construct(
		public readonly string $credentialId,
		public readonly string $model,
	) {
	}//end __construct()
}//end class
