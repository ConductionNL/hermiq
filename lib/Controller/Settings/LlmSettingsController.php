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
     * Replace stored secrets with boolean `*Set` flags so the raw values never
     * reach the browser.
     *
     * @param array $config The full `hermiq.llm` config.
     *
     * @return array The same config with `openaiConfig.apiKey` / `fireworksConfig.apiKey`
     *               removed and `openaiApiKeySet` / `fireworksApiKeySet` booleans added.
     */
    private function maskCredentials(array $config): array
    {
        $oaiKey = $config['openaiConfig']['apiKey'] ?? '';
        $fwKey  = $config['fireworksConfig']['apiKey'] ?? '';

        $config['openaiApiKeySet']    = ($oaiKey !== '');
        $config['fireworksApiKeySet'] = ($fwKey !== '');

        if (isset($config['openaiConfig']['apiKey']) === true) {
            unset($config['openaiConfig']['apiKey']);
        }

        if (isset($config['fireworksConfig']['apiKey']) === true) {
            unset($config['fireworksConfig']['apiKey']);
        }

        return $config;

    }//end maskCredentials()

    /**
     * Remove empty-string credential fields from the patch so submitting an
     * unedited masked field never clears a previously-stored key.
     *
     * @param array $data The incoming patch.
     *
     * @return array The patch with blank credential fields removed.
     */
    private function dropBlankCredentials(array $data): array
    {
        foreach (['openaiConfig', 'fireworksConfig'] as $block) {
            if (isset($data[$block]['apiKey']) === true && $data[$block]['apiKey'] === '') {
                unset($data[$block]['apiKey']);
            }
        }

        return $data;

    }//end dropBlankCredentials()
}//end class
