<?php

/**
 * Hermiq Web Research Settings Controller.
 *
 * Admin surface over the `hermiq.webResearch` IAppConfig key: the search backend
 * (endpoint, provider shape, field mapping), the optional broker credential, and the
 * `web.fetch` egress-governance knobs (allowlist, denylist, insecure-HTTP opt-in, size
 * cap, timeout). Mirrors `LlmSettingsController` exactly (same
 * `#[AuthorizedAdminSetting]` guard, same masked-read / blank-credential-preserving
 * PATCH shape) — with ONE deliberate difference: the raw `searchCredentialId` is never
 * echoed back at all (only the derived `searchCredentialConfigured` boolean), per this
 * change's stricter "never the raw credential id or key" acceptance criterion.
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
 * @spec openspec/changes/web-research-tool/tasks.md#task-2-websearchsettingscontroller--routes
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller\Settings;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\WebResearch\WebResearchSettingsHandler;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read + patch the Hermiq web-research configuration.
 *
 * @spec openspec/changes/web-research-tool/tasks.md#task-2-websearchsettingscontroller--routes
 */
class WebResearchSettingsController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                   $request         The request.
     * @param WebResearchSettingsHandler $settingsHandler Reads/writes `hermiq.webResearch`.
     * @param LoggerInterface            $logger          Logger.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly WebResearchSettingsHandler $settingsHandler,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Read the current web-research configuration, with the credential masked.
     *
     * @return JSONResponse The config, with `searchCredentialId` replaced by a
     *                      `searchCredentialConfigured` boolean.
     *
     * @spec openspec/changes/web-research-tool/tasks.md#task-2-websearchsettingscontroller--routes
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function get(): JSONResponse
    {
        try {
            $config = $this->settingsHandler->getWebResearchSettingsOnly();
        } catch (Throwable $e) {
            $this->logger->error(
                message: '[WebResearchSettingsController] Failed to read hermiq.webResearch config',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            return new JSONResponse(['error' => 'Failed to read web-research configuration'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse($this->maskCredential(config: $config));

    }//end get()

    /**
     * Update the web-research configuration (PATCH/merge semantics).
     *
     * @return JSONResponse The merged, persisted (masked) configuration, or 422 on an
     *                      unsupported search provider.
     *
     * @spec openspec/changes/web-research-tool/tasks.md#task-2-websearchsettingscontroller--routes
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function update(): JSONResponse
    {
        $data = $this->request->getParam('webResearch');
        if (is_array($data) === false) {
            // Accept a flat body too (the whole request as the patch).
            $data = $this->request->getParams();
            unset($data['_route']);
        }

        $provider = $data['searchProvider'] ?? null;
        if ($provider !== null
            && in_array($provider, WebResearchSettingsHandler::ALLOWED_SEARCH_PROVIDERS, true) === false
        ) {
            return new JSONResponse(
                [
                    'error'   => 'Unsupported search provider',
                    'allowed' => WebResearchSettingsHandler::ALLOWED_SEARCH_PROVIDERS,
                ],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $data = $this->dropBlankSearchCredential(data: $data);

        try {
            $merged = $this->settingsHandler->updateWebResearchSettingsOnly($data);
        } catch (Throwable $e) {
            $this->logger->error(
                message: '[WebResearchSettingsController] Failed to persist hermiq.webResearch config',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            return new JSONResponse(['error' => 'Failed to save web-research configuration'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(
            [
                'success' => true,
                'config'  => $this->maskCredential(config: $merged),
            ]
        );

    }//end update()

    /**
     * Replace the raw credential reference with a derived boolean before the config
     * ever reaches the browser — stricter than `LlmSettingsController::maskCredentials()`
     * (which returns the credential id in the clear): this endpoint's acceptance
     * criterion is "never the raw credential id or key".
     *
     * @param array<string, mixed> $config The full `hermiq.webResearch` config.
     *
     * @return array<string, mixed> The config with `searchCredentialId` replaced.
     */
    private function maskCredential(array $config): array
    {
        $credentialId = trim((string) ($config['searchCredentialId'] ?? ''));
        $config['searchCredentialConfigured'] = ($credentialId !== '');
        unset($config['searchCredentialId']);

        return $config;

    }//end maskCredential()

    /**
     * Drop an explicitly-blank `searchCredentialId` so submitting an unedited form
     * never clears the selected credential (mirrors
     * `LlmSettingsController::dropBlankCredentials()`).
     *
     * @param array<string, mixed> $data The incoming patch.
     *
     * @return array<string, mixed> The sanitised patch.
     */
    private function dropBlankSearchCredential(array $data): array
    {
        if (isset($data['searchCredentialId']) === true && $data['searchCredentialId'] === '') {
            unset($data['searchCredentialId']);
        }

        return $data;

    }//end dropBlankSearchCredential()
}//end class
