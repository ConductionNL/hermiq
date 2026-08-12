<?php

/**
 * Hermiq LLM Chat Driver value object.
 *
 * The result of `ProviderFactory::createChatDriver()`: an immutable bundle
 * describing which provider was selected and how to talk to it. For
 * `openai`/`ollama` the ready-to-use LLPhant chat instance is attached directly;
 * for `fireworks` (which stays on the direct-HTTP path — see ProviderFactory's
 * class docblock) the credentials needed for `ProviderFactory::callFireworksChat()`
 * travel alongside instead; `nextcloud` carries neither (non-streaming,
 * TaskProcessing-backed, resolved separately via `ProviderFactory::generateViaNextcloud()`).
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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

use LLPhant\Chat\OllamaChat;
use LLPhant\Chat\OpenAIChat;

/**
 * Immutable provider-selection result.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
 */
final class ChatDriver {
	/**
	 * Constructor.
	 *
	 * @param string $provider One of LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS.
	 * @param OpenAIChat|OllamaChat|null $chat Ready-to-use LLPhant chat instance for
	 *                                         openai/ollama; null for
	 *                                         fireworks/nextcloud.
	 * @param string $model The resolved model identifier.
	 * @param string|null $credentialId Broker credential UUID (fireworks only).
	 *                                  NOT a key: this used to carry the raw
	 *                                  Fireworks API key, which meant every
	 *                                  handler that touched a ChatDriver was
	 *                                  holding a live secret. The secret now
	 *                                  lives in the vault and the broker
	 *                                  injects it; this is only a reference.
	 * @param string|null $baseUrl Fireworks/Anthropic base URL
	 *                             (fireworks/anthropic only).
	 * @param string|null $authMode Anthropic auth mode (anthropic only):
	 *                              `api_key` or `oauth`. Selects the
	 *                              header set `callAnthropicChat()`
	 *                              builds. Null for every other
	 *                              provider.
	 * @param string $executionMode Transport mode for a CLI-capable provider
	 *                              (`anthropic`/`openai`): `http` (default —
	 *                              the direct `BrokerHttpClient` path) or `cli`
	 *                              (dispatch the assembled turn to the
	 *                              `hermiq-llm-runner` ExApp). `http` for every
	 *                              provider that has no CLI backend, so nothing
	 *                              changes for existing configs.
	 * @param int|null $maxTokens The agent's own output ceiling, when it
	 *                            set one. Carried here because the
	 *                            Anthropic path builds its request at the
	 *                            call site rather than on a chat object,
	 *                            so it has nowhere else to read it from.
	 *                            Null means "use the provider default".
	 */
	public function __construct(
		public readonly string $provider,
		public readonly OpenAIChat|OllamaChat|null $chat,
		public readonly string $model,
		public readonly ?string $credentialId = null,
		public readonly ?string $baseUrl = null,
		public readonly ?string $authMode = null,
		public readonly string $executionMode = 'http',
		public readonly ?int $maxTokens = null,
	) {
	}//end __construct()
}//end class
