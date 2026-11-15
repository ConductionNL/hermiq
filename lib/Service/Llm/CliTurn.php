<?php

/**
 * Hermiq CliTurn.
 *
 * One Anthropic turn served through the `cli` runner, or one warm-up of its
 * process: what was asked, who asks, the pool key, and the run token and MCP
 * config minted for it.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-a-turn-that-cannot-be-governed-fails-loudly-and-is-never-silently-tool-less
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

/**
 * One `cli` turn or warm-up in progress; it never leaves ProviderFactory.
 *
 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-a-turn-that-cannot-be-governed-fails-loudly-and-is-never-silently-tool-less
 */
final class CliTurn {

	/**
	 * The broker credential UUID of the subscription.
	 *
	 * @var string
	 */
	public string $credentialId = '';

	/**
	 * The conversation the run belongs to, or ''.
	 *
	 * @var string
	 */
	public string $conversationId = '';

	/**
	 * The turn's message history (empty for a warm-up).
	 *
	 * @var array<int, mixed>
	 */
	public array $messageHistory = [];

	/**
	 * The functions offered to the model; empty for a tool-less turn.
	 *
	 * @var array<int, mixed>
	 */
	public array $functions = [];

	/**
	 * Whether a warmed process is governed (carries tools).
	 *
	 * @var bool
	 */
	public bool $governed = true;

	/**
	 * The acting user's UID, once resolved.
	 *
	 * @var string|null
	 */
	public ?string $uid = null;

	/**
	 * The key a pooled process is reused under, or '' for the cold path.
	 *
	 * @var string
	 */
	public string $poolKey = '';

	/**
	 * The run token minted for the turn, or null before one is.
	 *
	 * @var string|null
	 */
	public ?string $runToken = null;

	/**
	 * The governed MCP config handed to the CLI, or null for a tool-less turn.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $mcpConfig = null;

	/**
	 * Start the turn.
	 *
	 * @param string      $model   The model id (a startup argument of the CLI).
	 * @param string|null $agentId The acting agent's UUID, or null.
	 */
	public function __construct(
		public readonly string $model,
		public readonly ?string $agentId,
	) {
	}//end __construct()
}//end class
