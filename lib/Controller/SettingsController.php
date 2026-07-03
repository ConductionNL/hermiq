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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for managing Hermiq application settings.
 */
class SettingsController extends Controller
{
    /**
     * Constructor for the SettingsController.
     *
     * @param IRequest        $request         The request object
     * @param SettingsService $settingsService The settings service
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private SettingsService $settingsService,
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
        $settings = $this->settingsService->getSettings();
        $isAdmin  = ($settings['isAdmin'] ?? false);

        if ($isAdmin === false) {
            unset($settings['register']);
        }

        return new JSONResponse($settings);
    }//end index()

    /**
     * Update settings with provided data.
     *
     * @return JSONResponse
     *
     * @spec openspec/specs/settings-management/spec.md#REQ-CFG-002
     */
    public function create(): JSONResponse
    {
        $data   = $this->request->getParams();
        $config = $this->settingsService->updateSettings($data);

        return new JSONResponse(
            [
                'success' => true,
                'config'  => $config,
            ]
        );
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
        $result = $this->settingsService->loadConfiguration(force: true);

        return new JSONResponse($result);
    }//end load()
}//end class
