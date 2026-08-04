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
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

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
