<?php

/**
 * Hermiq AnthropicToolLoop.
 *
 * The state of one Messages API tool loop: the endpoint and headers every round
 * posts to, the system prompt and the growing message list, the tools offered,
 * the tool-call cap and count, and the latest answer text.
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
 * One Anthropic tool loop. Built and read only inside ProviderFactory.
 *
 * @spec openspec/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
 */
final class AnthropicToolLoop {

	/**
	 * The `/messages` endpoint URL.
	 *
	 * @var string
	 */
	public readonly string $url;

	/**
	 * At most this many tool calls run.
	 *
	 * @var int
	 */
	public readonly int $cap;

	/**
	 * Auth/version headers.
	 *
	 * @var array<string, string>
	 */
	public array $headers = [];

	/**
	 * The hoisted system prompt, or ''.
	 *
	 * @var string
	 */
	public string $system = '';

	/**
	 * The Anthropic messages sent with the next round.
	 *
	 * @var array<int, mixed>
	 */
	public array $messages = [];

	/**
	 * The tools offered to the model.
	 *
	 * @var array<int, mixed>
	 */
	public array $tools = [];

	/**
	 * The tool calls run so far.
	 *
	 * @var int
	 */
	public int $executed = 0;

	/**
	 * The latest answer text.
	 *
	 * @var string
	 */
	public string $text = '';

	/**
	 * Constructor.
	 *
	 * @param AnthropicChatRun $run        The turn.
	 * @param int              $defaultCap The cap when the turn sets none.
	 */
	public function __construct(
		public readonly AnthropicChatRun $run,
		int $defaultCap,
	) {
		$this->url = rtrim($run->baseUrl, '/') . '/messages';
		$this->cap = ($run->maxToolCalls ?? $defaultCap);
	}//end __construct()
}//end class
