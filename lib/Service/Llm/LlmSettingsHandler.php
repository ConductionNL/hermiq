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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

use Exception;
use RuntimeException;
use OCP\IAppConfig;

/**
 * Handler for the `hermiq.llm` IAppConfig key.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
 */
class LlmSettingsHandler
{

    /**
     * The allowed `chatProvider` values. `nextcloud` is additive to OR's original three
     * (openai, ollama, fireworks) — plan §8 move 1, background/non-interactive only.
     * `anthropic` is additive too (anthropic-agent-provider) — Claude via API key or a
     * Claude Max OAuth subscription, broker-injected like openai/fireworks.
     *
     * @var string[]
     */
    public const ALLOWED_CHAT_PROVIDERS = ['openai', 'ollama', 'fireworks', 'nextcloud', 'anthropic'];

    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig Nextcloud app configuration service.
     * @param string     $appName   Application name the config is stored under.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly string $appName='hermiq'
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
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
     */
    public function getLLMSettingsOnly(): array
    {
        try {
            $llmConfig = $this->appConfig->getValueString($this->appName, 'llm', '');

            if (empty($llmConfig) === true) {
                return $this->defaultConfig();
            }

            $decoded = json_decode($llmConfig, true);
            if (is_array($decoded) === false) {
                throw new Exception('Stored hermiq.llm config is not a JSON object');
            }

            // Backward/forward compatible field defaults.
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
        } catch (Exception $e) {
            throw new RuntimeException('Failed to retrieve LLM settings: '.$e->getMessage());
        }//end try
    }//end getLLMSettingsOnly()

    /**
     * Update the LLM settings (PATCH semantics — merges with the existing stored config).
     *
     * @param array $llmData Partial or full LLM configuration.
     *
     * @return array The merged, persisted configuration.
     *
     * @throws \RuntimeException If persisting fails.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
     */
    public function updateLLMSettingsOnly(array $llmData): array
    {
        try {
            $existingConfig = $this->getLLMSettingsOnly();

            $newOai = $llmData['openaiConfig'] ?? [];
            $exOai  = $existingConfig['openaiConfig'] ?? [];
            $newOll = $llmData['ollamaConfig'] ?? [];
            $exOll  = $existingConfig['ollamaConfig'] ?? [];
            $newFw  = $llmData['fireworksConfig'] ?? [];
            $exFw   = $existingConfig['fireworksConfig'] ?? [];
            $newAnt = $llmData['anthropicConfig'] ?? [];
            $exAnt  = $existingConfig['anthropicConfig'] ?? [];
            $newVec = $llmData['vectorConfig'] ?? [];
            $exVec  = $existingConfig['vectorConfig'] ?? [];

            $llmConfig = [
                'enabled'           => $llmData['enabled'] ?? $existingConfig['enabled'] ?? false,
                'embeddingProvider' => $llmData['embeddingProvider'] ?? $existingConfig['embeddingProvider'] ?? null,
                'chatProvider'      => $llmData['chatProvider'] ?? $existingConfig['chatProvider'] ?? null,
                // `apiKey` is GONE. It used to sit in CLEARTEXT inside this JSON blob in
                // oc_appconfig — readable by anything that could read the database, and
                // printed verbatim by `occ config:app:get hermiq llm`. What lives here now
                // is `credentialId`: a broker credential UUID whose secret is in the vault
                // and is injected server-side. It is a reference, not a secret, so it is
                // stored and returned in the clear. `RemoveLegacyLlmKeys` deletes the keys
                // stored before this release.
                'openaiConfig'      => [
                    'credentialId'   => $newOai['credentialId'] ?? $exOai['credentialId'] ?? '',
                    'model'          => $newOai['model'] ?? $exOai['model'] ?? null,
                    'chatModel'      => $newOai['chatModel'] ?? $exOai['chatModel'] ?? null,
                    'organizationId' => $newOai['organizationId'] ?? $exOai['organizationId'] ?? '',
                    // `executionMode` selects the transport: `http` (default — the direct
                    // BrokerHttpClient path) or `cli` (dispatch the turn to the
                    // hermiq-llm-runner ExApp, running the OpenAI Codex CLI). Defaulting to
                    // `http` keeps every existing config unchanged (llm-cli-runner-exapp).
                    'executionMode'  => $newOai['executionMode'] ?? $exOai['executionMode'] ?? 'http',
                ],
                'ollamaConfig'      => [
                    'url'       => $newOll['url'] ?? $exOll['url'] ?? 'http://localhost:11434',
                    'model'     => $newOll['model'] ?? $exOll['model'] ?? null,
                    'chatModel' => $newOll['chatModel'] ?? $exOll['chatModel'] ?? null,
                ],
                'fireworksConfig'   => [
                    'credentialId'   => $newFw['credentialId'] ?? $exFw['credentialId'] ?? '',
                    'embeddingModel' => $newFw['embeddingModel'] ?? $exFw['embeddingModel'] ?? null,
                    'chatModel'      => $newFw['chatModel'] ?? $exFw['chatModel'] ?? null,
                    'baseUrl'        => $newFw['baseUrl'] ?? $exFw['baseUrl'] ?? 'https://api.fireworks.ai/inference/v1',
                ],
                // Anthropic (anthropic-agent-provider). Like openai/fireworks the secret is
                // NOT here — `credentialId` is a broker reference; the key or OAuth token
                // lives in the vault and is injected server-side. `authMode` selects the
                // header set (api_key → x-api-key; oauth → Authorization: Bearer + the
                // anthropic-beta oauth flag). `scope` is organisation | personal; a
                // Claude Max OAuth token MUST be personal-only (validated on save).
                'anthropicConfig'   => [
                    'credentialId'  => $newAnt['credentialId'] ?? $exAnt['credentialId'] ?? '',
                    'chatModel'     => $newAnt['chatModel'] ?? $exAnt['chatModel'] ?? null,
                    'authMode'      => $newAnt['authMode'] ?? $exAnt['authMode'] ?? 'api_key',
                    'scope'         => $newAnt['scope'] ?? $exAnt['scope'] ?? 'organisation',
                    'baseUrl'       => $newAnt['baseUrl'] ?? $exAnt['baseUrl'] ?? 'https://api.anthropic.com/v1',
                    // `executionMode`: `http` (default — direct BrokerHttpClient Messages API)
                    // or `cli` (dispatch the turn to the hermiq-llm-runner ExApp, running the
                    // `claude` CLI). Default `http` keeps existing configs unchanged; the
                    // credential scope rules (personal Claude Max OAuth vs org API key) carry
                    // over unchanged (llm-cli-runner-exapp).
                    'executionMode' => $newAnt['executionMode'] ?? $exAnt['executionMode'] ?? 'http',
                ],
                'vectorConfig'      => [
                    'backend'   => $newVec['backend'] ?? $exVec['backend'] ?? 'php',
                    'solrField' => $newVec['solrField'] ?? $exVec['solrField'] ?? '_embedding_',
                ],
            ];

            $this->appConfig->setValueString($this->appName, 'llm', json_encode($llmConfig));
            return $llmConfig;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to update LLM settings: '.$e->getMessage());
        }//end try
    }//end updateLLMSettingsOnly()

    /**
     * The default configuration returned when `hermiq.llm` has never been set.
     *
     * @return array Default LLM configuration.
     */
    private function defaultConfig(): array
    {
        return [
            'enabled'           => false,
            'embeddingProvider' => null,
            'chatProvider'      => null,
            'openaiConfig'      => [
                'credentialId'   => '',
                'model'          => null,
                'chatModel'      => null,
                'organizationId' => '',
                'executionMode'  => 'http',
            ],
            'ollamaConfig'      => [
                'url'       => 'http://localhost:11434',
                'model'     => null,
                'chatModel' => null,
            ],
            'fireworksConfig'   => [
                'credentialId'   => '',
                'embeddingModel' => null,
                'chatModel'      => null,
                'baseUrl'        => 'https://api.fireworks.ai/inference/v1',
            ],
            'anthropicConfig'   => [
                'credentialId'  => '',
                'chatModel'     => null,
                'authMode'      => 'api_key',
                'scope'         => 'organisation',
                'baseUrl'       => 'https://api.anthropic.com/v1',
                'executionMode' => 'http',
            ],
            'vectorConfig'      => [
                'backend'   => 'php',
                'solrField' => '_embedding_',
            ],
        ];

    }//end defaultConfig()
}//end class
