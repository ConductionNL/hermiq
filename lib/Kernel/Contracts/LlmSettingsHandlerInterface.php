<?php

/**
 * Contract of LlmSettingsHandler
 *
 * The public surface of LlmSettingsHandler that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

/**
 * Contract of LlmSettingsHandler.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LlmSettingsHandlerInterface {

	/**
	 * The allowed `chatProvider` values. `nextcloud` is additive to OR's original three
	 * (openai, ollama, fireworks) — plan §8 move 1, background/non-interactive only.
	 * `anthropic` is additive too (anthropic-agent-provider) — Claude via API key or a
	 * Claude Max OAuth subscription, broker-injected like openai/fireworks.
	 * `openai-compatible` is an OpenAI-compatible endpoint an admin connects by base
	 * URL (models-provider-connections).
	 *
	 * @var string[]
	 */
	public const ALLOWED_CHAT_PROVIDERS = ['openai', 'ollama', 'fireworks', 'nextcloud', 'anthropic', 'openai-compatible'];

	/**
	 * Get the LLM settings, defaulting every field when the key is unset.
	 *
	 * @return array LLM configuration (enabled, embeddingProvider, chatProvider, per-provider
	 *               config blocks, vectorConfig).
	 *
	 * @throws \RuntimeException If the stored config cannot be decoded.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-1
	 */
	public function getLLMSettingsOnly(): array;

	/**
	 * Update the LLM settings (PATCH semantics — merges with the existing stored config).
	 *
	 * @param array $llmData Partial or full LLM configuration.
	 *
	 * @return array The merged, persisted configuration.
	 *
	 * @throws \RuntimeException If persisting fails.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-1
	 */
	public function updateLLMSettingsOnly(array $llmData): array;
}//end interface
