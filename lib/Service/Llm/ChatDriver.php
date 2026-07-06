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
final class ChatDriver
{
    /**
     * Constructor.
     *
     * @param string                     $provider One of LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS.
     * @param OpenAIChat|OllamaChat|null $chat     Ready-to-use LLPhant chat instance for
     *                                             openai/ollama; null for fireworks/nextcloud.
     * @param string                     $model    The resolved model identifier.
     * @param string|null                $apiKey   Fireworks API key (fireworks only).
     * @param string|null                $baseUrl  Fireworks base URL (fireworks only).
     */
    public function __construct(
        public readonly string $provider,
        public readonly OpenAIChat|OllamaChat|null $chat,
        public readonly string $model,
        public readonly ?string $apiKey=null,
        public readonly ?string $baseUrl=null,
    ) {
    }//end __construct()
}//end class
