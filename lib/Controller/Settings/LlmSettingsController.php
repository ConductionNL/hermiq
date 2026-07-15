<?php

/**
 * Hermiq LLM Settings Controller.
 *
 * Minimal admin surface over the `hermiq.llm` IAppConfig key so an admin can
 * SELECT which chat provider Hermiq's background/non-interactive LLM work runs on
 * (SPECTR-NEXTCLOUD-PLAN.md §8 move 1). The backend of that move already shipped in
 * `agent-engine-port` (ProviderFactory's four drivers incl. `nextcloud`); this
 * controller is the read/patch endpoint that makes the choice reachable from the
 * Nextcloud admin panel instead of only `occ config:app:set`.
 *
 * Scope is deliberately narrow (plan move 1 "minimal"): read the config with every
 * credential masked to a boolean, and patch it with provider validation. OpenRegister's
 * heavier `test-chat` / `ollama-models` helper endpoints are intentionally NOT ported.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller\Settings;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read + patch the Hermiq chat-provider configuration.
 *
 * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-1-1
 */
class LlmSettingsController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request         The request.
     * @param LlmSettingsHandler $settingsHandler Reads/writes `hermiq.llm`.
     * @param LoggerInterface    $logger          Logger.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly LlmSettingsHandler $settingsHandler,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Read the current LLM provider configuration, with credentials masked.
     *
     * Admin-only via the settings-panel guard (mirrors decidesk's SettingsController).
     *
     * @return JSONResponse The config, with every stored secret replaced by a boolean
     *                      `*Set` flag so the raw key is never returned to the browser.
     *
     * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-1-2
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function get(): JSONResponse
    {
        try {
            $config = $this->settingsHandler->getLLMSettingsOnly();
        } catch (Throwable $e) {
            $this->logger->error(
                message: '[LlmSettingsController] Failed to read hermiq.llm config',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            return new JSONResponse(['error' => 'Failed to read LLM configuration'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse($this->maskCredentials(config: $config));

    }//end get()

    /**
     * Update the LLM provider configuration (PATCH/merge semantics).
     *
     * Validates the selected provider against the allowed set and drops blank
     * credential strings so an unedited masked field never wipes a stored key.
     *
     * @return JSONResponse The merged, persisted (masked) configuration, or 422 on
     *                      an unsupported provider.
     *
     * @spec openspec/changes/taskprocessing-consume-ui/tasks.md#task-1-3
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function update(): JSONResponse
    {
        $data = $this->request->getParam('llm');
        if (is_array($data) === false) {
            // Accept a flat body too (the whole request as the llm patch).
            $data = $this->request->getParams();
            unset($data['_route']);
        }

        $provider = $data['chatProvider'] ?? null;
        if ($provider !== null
            && in_array($provider, LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS, true) === false
        ) {
            return new JSONResponse(
                [
                    'error'   => 'Unsupported chat provider',
                    'allowed' => LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS,
                ],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        // Anthropic credential scope: a Claude Max / Pro subscription (authMode: oauth) is
        // personal-only per the Anthropic Terms of Service — it may only be a personal token
        // in personal settings, never an organisation-wide credential serving other users.
        // Refuse oauth at organisation scope. An API key (authMode: api_key) may be either
        // scope. See anthropic-agent-provider spec.
        $anthropic = $data['anthropicConfig'] ?? [];
        if (is_array($anthropic) === true
            && ($anthropic['authMode'] ?? '') === 'oauth'
            && ($anthropic['scope'] ?? 'organisation') === 'organisation'
        ) {
            return new JSONResponse(
                [
                    'error' => 'A Claude Max/Pro subscription (OAuth) may only be set as a personal token in '
                        .'personal settings, never as an organisation credential (per the Anthropic Terms of Service).',
                ],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $data = $this->dropBlankCredentials(data: $data);

        try {
            $merged = $this->settingsHandler->updateLLMSettingsOnly($data);
        } catch (Throwable $e) {
            $this->logger->error(
                message: '[LlmSettingsController] Failed to persist hermiq.llm config',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            return new JSONResponse(['error' => 'Failed to save LLM configuration'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(
            [
                'success' => true,
                'config'  => $this->maskCredentials(config: $merged),
            ]
        );

    }//end update()

    /**
     * Strip any secret from the config before it reaches the browser.
     *
     * There is no longer a stored key to mask: `credentialId` is a broker credential UUID,
     * and the secret behind it lives in the vault. It is therefore returned in the clear —
     * the settings UI needs it back to show which credential is selected.
     *
     * The `*Set` booleans are kept (now derived from the credential) so an older frontend
     * that still reads them keeps working, and a stray `apiKey` from a legacy config blob
     * is stripped defensively so it can never be echoed back.
     *
     * @param array $config The full `hermiq.llm` config.
     *
     * @return array The config with any legacy `apiKey` removed.
     */
    private function maskCredentials(array $config): array
    {
        $oaiCredential = $config['openaiConfig']['credentialId'] ?? '';
        $fwCredential  = $config['fireworksConfig']['credentialId'] ?? '';
        $antCredential = $config['anthropicConfig']['credentialId'] ?? '';

        $config['openaiApiKeySet']    = ($oaiCredential !== '');
        $config['fireworksApiKeySet'] = ($fwCredential !== '');
        $config['anthropicApiKeySet'] = ($antCredential !== '');

        // Defensive: a config blob written before this release still carries a cleartext
        // apiKey until the repair step runs. It must never be echoed to the browser.
        unset($config['openaiConfig']['apiKey'], $config['fireworksConfig']['apiKey'], $config['anthropicConfig']['apiKey']);

        return $config;

    }//end maskCredentials()

    /**
     * Drop fields from the patch that must not be written.
     *
     * A blank `credentialId` is dropped so submitting an unedited form never clears the
     * selected credential. A submitted `apiKey` is dropped outright — Hermiq does not hold
     * LLM keys any more, and a client that still sends one must not have it persisted.
     *
     * @param array $data The incoming patch.
     *
     * @return array The sanitised patch.
     */
    private function dropBlankCredentials(array $data): array
    {
        foreach (['openaiConfig', 'fireworksConfig', 'anthropicConfig'] as $block) {
            if (isset($data[$block]['credentialId']) === true && $data[$block]['credentialId'] === '') {
                unset($data[$block]['credentialId']);
            }

            if (isset($data[$block]['apiKey']) === true) {
                $this->logger->warning(
                    '[Hermiq] A retired LLM apiKey was submitted and ignored. '
                    .'LLM keys live in the credential broker now; set `credentialId` instead.',
                    ['block' => $block]
                );
                unset($data[$block]['apiKey']);
            }
        }

        return $data;

    }//end dropBlankCredentials()
}//end class
