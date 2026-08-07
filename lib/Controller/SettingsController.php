<?php

/**
 * Hermiq Settings Controller
 *
 * Controller for managing Hermiq application settings.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\SettingsService;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for managing Hermiq application settings.
 *
 * @spec openspec/specs/settings-management/spec.md
 */
class SettingsController extends Controller
{
    /**
     * Constructor for the SettingsController.
     *
     * @param IRequest        $request         The request object
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger          Records why a translated failure happened
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private SettingsService $settingsService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Retrieve all current settings.
     *
     * Admin-sensitive fields (register binding) are stripped for non-admin users
     * so the register UUID is not exposed to regular authenticated users.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/settings-management/spec.md#REQ-CFG-001
     */
    public function index(): JSONResponse
    {
        try {
            $settings = $this->settingsService->getSettings();
            $isAdmin  = ($settings['isAdmin'] ?? false);

            if ($isAdmin === false) {
                unset($settings['register']);
            }

            return new JSONResponse($settings);
        } catch (\Throwable $e) {
            // Translated rather than allowed to escape: an uncaught throwable
            // leaves the framework to render a 500 with a stack trace, which
            // tells the caller nothing it can act on and leaks internals to a
            // NON-ADMIN — this method is #[NoAdminRequired].
            $this->logger->error('Hermiq: reading settings failed', ['exception' => $e]);

            return new JSONResponse(['error' => 'Could not read the settings.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end index()

    /**
     * Update settings with provided data.
     *
     * Admin-only, declared rather than inherited. The method writes app config
     * (including the OpenRegister `register` binding that the sibling index()
     * deliberately strips for non-admins), so it was already admin-gated by
     * Nextcloud's default for an un-attributed method — but that posture was
     * implicit, and an implicit one is silently lost the moment anybody adds
     * #[NoAdminRequired] to make a read work. AuthorizedAdminSetting also
     * enforces DELEGATED admin authorization for this app's settings section,
     * so a delegated admin gets exactly the same surface as in the UI.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/settings-management/spec.md#REQ-CFG-002
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function create(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $config = $this->settingsService->updateSettings($data);

            return new JSONResponse(
                [
                    'success' => true,
                    'config'  => $config,
                ]
            );
        } catch (\Throwable $e) {
            // A write that half-happened must not answer with a stack trace.
            // `success: false` is the shape the caller already branches on, so
            // the failure is legible to the UI rather than being a 500 it has
            // to guess at.
            $this->logger->error('Hermiq: updating settings failed', ['exception' => $e]);

            return new JSONResponse(
                [
                    'success' => false,
                    'error'   => 'Could not update the settings.',
                ],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end create()

    /**
     * Re-import the configuration from hermiq_register.json.
     *
     * Forces a fresh import regardless of version, auto-configuring
     * all schema and register IDs from the import result.
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/settings-management/spec.md#REQ-CFG-003
     */
    public function load(): JSONResponse
    {
        try {
            $result = $this->settingsService->loadConfiguration(force: true);

            return new JSONResponse($result);
        } catch (\Throwable $e) {
            // The import touches OpenRegister and the register.d fragments, so
            // this is the method most likely to throw for an environmental
            // reason (OR absent, a malformed fragment). Naming that beats a
            // stack trace: the caller is an admin clicking "reload", and the
            // one thing they need to know is that nothing was imported.
            $this->logger->error('Hermiq: configuration import failed', ['exception' => $e]);

            return new JSONResponse(
                ['error' => 'Could not import the configuration. Nothing was changed.'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end load()
}//end class
