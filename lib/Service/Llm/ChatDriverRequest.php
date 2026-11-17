<?php

/**
 * Hermiq ChatDriverRequest.
 *
 * What one `ProviderFactory::createChatDriver()` call asks for: the instance's LLM
 * configuration, the agent's overrides (model, temperature, ceiling, pinned
 * credentials), the organisation the model policy is checked for, and the AI
 * feature, document and attachments the feature's gates check. The provider and
 * whether a feature binding applied are filled in while the driver is resolved.
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
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

/**
 * One chat-driver resolution. Built and read only inside ProviderFactory.
 *
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-1
 */
final class ChatDriverRequest {

	/**
	 * The provider name, from the configuration or the feature binding.
	 *
	 * @var mixed
	 */
	public mixed $chatProvider = null;

	/**
	 * Agent-level (or feature-bound) model override.
	 *
	 * @var string|null
	 */
	public ?string $agentModel = null;

	/**
	 * Agent-level temperature override.
	 *
	 * @var float|null
	 */
	public ?float $agentTemperature = null;

	/**
	 * Agent-level max-tokens override.
	 *
	 * @var int|null
	 */
	public ?int $agentMaxTokens = null;

	/**
	 * The AI feature slug the run belongs to, or null.
	 *
	 * @var string|null
	 */
	public ?string $aiFeature = null;

	/**
	 * Whether a feature binding applies (the feature gates run instead of the policy alone).
	 *
	 * @var bool
	 */
	public bool $featureBound = false;

	/**
	 * The document the run was handed, or null.
	 *
	 * @var string|null
	 */
	public ?string $documentReference = null;

	/**
	 * The agent's own credential per provider, or null.
	 *
	 * @var array<string, string>|null
	 */
	public ?array $agentCredentialIds = null;

	/**
	 * The file ids attached to this turn.
	 *
	 * @var array<int, string>
	 */
	public array $attachmentReferences = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $llmConfig    The `hermiq.llm` configuration.
	 * @param string|null          $organisation The calling agent's organisation, or null (no policy check).
	 */
	public function __construct(
		public readonly array $llmConfig,
		public readonly ?string $organisation,
	) {
		$this->chatProvider = ($llmConfig['chatProvider'] ?? null);
	}//end __construct()
}//end class
