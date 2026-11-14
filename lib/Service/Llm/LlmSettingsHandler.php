<?php

/**
 * Hermiq LLM Settings Handler.
 *
 * Reads and writes the `hermiq.llm` IAppConfig key: the LLM provider configuration
 * (chat/embedding provider selection, per-provider credentials, vector backend) the
 * ported agent engine runs on. Ported from OpenRegister's
 * `OCA\OpenRegister\Service\Settings\LlmSettingsHandler` (`openregister.llm`), same
 * JSON shape, renamed appName. Adds `nextcloud` as a 4th allowed `chatProvider` value
 * (TaskProcessing-backed, no own config sub-block — see ProviderFactory).
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

use Exception;
use OCA\Hermiq\Kernel\Contracts\LlmSettingsHandlerInterface;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Handler for the `hermiq.llm` IAppConfig key.
 *
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-1
 */
class LlmSettingsHandler implements LlmSettingsHandlerInterface {

	/**
	 * The shape of the `hermiq.llm` configuration, with every field's default.
	 *
	 * `apiKey` is GONE. It used to sit in CLEARTEXT inside this JSON blob in
	 * oc_appconfig — readable by anything that could read the database, and printed
	 * verbatim by `occ config:app:get hermiq llm`. What lives here now is
	 * `credentialId`: a broker credential UUID whose secret is in the vault and is
	 * injected server-side. It is a reference, not a secret, so it is stored and
	 * returned in the clear. `RemoveLegacyLlmKeys` deletes the keys stored before
	 * this release.
	 *
	 * `executionMode` selects the transport: `http` (default — the direct
	 * BrokerHttpClient path) or `cli` (dispatch the turn to the hermiq-llm-runner
	 * ExApp, running the OpenAI Codex CLI or the `claude` CLI). Defaulting to `http`
	 * keeps every existing config unchanged (llm-cli-runner-exapp).
	 *
	 * Anthropic (anthropic-agent-provider): like openai/fireworks the secret is NOT
	 * here — `credentialId` is a broker reference. `authMode` selects the header set
	 * (api_key → x-api-key; oauth → Authorization: Bearer + the anthropic-beta oauth
	 * flag). `scope` is organisation | personal; a Claude Max OAuth token MUST be
	 * personal-only (validated on save).
	 *
	 * `openaiConfig.baseUrl` is optional: empty keeps api.openai.com. A base URL with
	 * no credential is called directly; with a credential the call goes through the
	 * broker. `openaiCompatibleConfig` is an OpenAI-compatible endpoint named by its
	 * admin-chosen `label`, under the same transport rule. `ownNetwork` is the
	 * admin's statement that a keyless base URL is a server on the organisation's
	 * own network: only then may it resolve to a private address or use plain HTTP.
	 * `apiMode` on both is `chat_completions` or `responses` (models-provider-connections).
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULT_CONFIG = [
		'enabled' => false,
		'embeddingProvider' => null,
		'chatProvider' => null,
		'openaiConfig' => [
			'credentialId' => '',
			'model' => null,
			'chatModel' => null,
			'organizationId' => '',
			'executionMode' => 'http',
			'baseUrl' => '',
			'ownNetwork' => false,
			'apiMode' => 'chat_completions',
		],
		'ollamaConfig' => [
			'url' => 'http://localhost:11434',
			'model' => null,
			'chatModel' => null,
		],
		'fireworksConfig' => [
			'credentialId' => '',
			'embeddingModel' => null,
			'chatModel' => null,
			'baseUrl' => 'https://api.fireworks.ai/inference/v1',
		],
		'anthropicConfig' => [
			'credentialId' => '',
			'chatModel' => null,
			'authMode' => 'api_key',
			'scope' => 'organisation',
			'baseUrl' => 'https://api.anthropic.com/v1',
			'executionMode' => 'http',
		],
		'openaiCompatibleConfig' => [
			'label' => '',
			'baseUrl' => '',
			'chatModel' => null,
			'credentialId' => '',
			'ownNetwork' => false,
			'apiMode' => 'chat_completions',
		],
		'vectorConfig' => [
			'backend' => 'php',
			'solrField' => '_embedding_',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Nextcloud app configuration service.
	 * @param string $appName Application name the config is stored under.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-1
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly string $appName = 'hermiq',
	) {
	}//end __construct()

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
	public function getLLMSettingsOnly(): array {
		try {
			$llmConfig = $this->appConfig->getValueString($this->appName, 'llm', '');

			if (empty($llmConfig) === true) {
				return $this->defaultConfig();
			}

			$decoded = json_decode($llmConfig, true);
			if (is_array($decoded) === false) {
				throw new Exception('Stored hermiq.llm config is not a JSON object');
			}

			return $this->backfillStored(decoded: $decoded);
		} catch (Exception $e) {
			throw new RuntimeException('Failed to retrieve LLM settings: ' . $e->getMessage());
		}//end try
	}//end getLLMSettingsOnly()

	/**
	 * Backward/forward compatible field defaults for a stored configuration.
	 *
	 * @param array<string, mixed> $decoded The stored configuration.
	 *
	 * @return array<string, mixed> The configuration with `enabled` and the vector fields set.
	 */
	private function backfillStored(array $decoded): array {
		if (isset($decoded['enabled']) === false) {
			$decoded['enabled'] = false;
		}

		if (isset($decoded['vectorConfig']) === false) {
			$decoded['vectorConfig'] = [];
		}

		if (isset($decoded['vectorConfig']['backend']) === false) {
			$decoded['vectorConfig']['backend'] = 'php';
		}

		if (isset($decoded['vectorConfig']['solrField']) === false) {
			$decoded['vectorConfig']['solrField'] = '_embedding_';
		}

		return $decoded;
	}//end backfillStored()

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
	public function updateLLMSettingsOnly(array $llmData): array {
		try {
			$llmConfig = $this->mergeSettings(patch: $llmData, existing: $this->getLLMSettingsOnly());

			$this->appConfig->setValueString($this->appName, 'llm', json_encode($llmConfig));
			return $llmConfig;
		} catch (Exception $e) {
			throw new RuntimeException('Failed to update LLM settings: ' . $e->getMessage());
		}//end try
	}//end updateLLMSettingsOnly()

	/**
	 * Merge a patch over the stored configuration: every field of the default
	 * shape takes the patch's value, else the stored one, else its default (a
	 * null falls through like an absent value). Keys outside the shape are
	 * dropped, so a submitted `apiKey` is never carried over.
	 *
	 * @param array<string, mixed> $patch    Partial or full LLM configuration.
	 * @param array<string, mixed> $existing The stored configuration.
	 *
	 * @return array<string, mixed> The merged configuration, in the default shape's order.
	 */
	private function mergeSettings(array $patch, array $existing): array {
		$merged = [];
		foreach (self::DEFAULT_CONFIG as $key => $default) {
			if (is_array($default) === false) {
				$merged[$key] = ($patch[$key] ?? $existing[$key] ?? $default);
				continue;
			}

			$patchSection = ($patch[$key] ?? []);
			$existingSection = ($existing[$key] ?? []);
			foreach ($default as $field => $fieldDefault) {
				$merged[$key][$field] = ($patchSection[$field] ?? $existingSection[$field] ?? $fieldDefault);
			}
		}

		return $merged;
	}//end mergeSettings()

	/**
	 * The default configuration returned when `hermiq.llm` has never been set.
	 *
	 * @return array Default LLM configuration.
	 */
	private function defaultConfig(): array {
		return self::DEFAULT_CONFIG;
	}//end defaultConfig()
}//end class
