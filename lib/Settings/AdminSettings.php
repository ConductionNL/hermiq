<?php

/**
 * Hermiq Admin Settings
 *
 * Provides the admin settings form for the Hermiq application.
 *
 * @category Settings
 * @package  OCA\Hermiq\Settings
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

namespace OCA\Hermiq\Settings;

use OCA\Hermiq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Settings\IDelegatedSettings;

/**
 * Provides the admin settings form for the Hermiq application.
 *
 * Implements IDelegatedSettings (the delegated-admin-capable ISettings variant) so
 * the LLM-provider endpoints can guard on `#[AuthorizedAdminSetting(AdminSettings::class)]`
 * — that attribute requires a `class-string<IDelegatedSettings>`. Access still
 * defaults to full admins: getName()/getAuthorizedAppConfig() return the
 * no-delegation defaults (matching decidesk's AdminSettings).
 *
 * @spec exclude Trivial IDelegatedSettings binding (template + section + priority); no behavioural spec.
 */
class AdminSettings implements IDelegatedSettings
{
    /**
     * Constructor.
     *
     * @param IAppManager   $appManager   The app manager.
     * @param IInitialState $initialState Provides is_admin/opencatalogi_available to the
     *                                    relocated AiFeatureRegister section
     *                                    (ai-features-to-admin).
     * @param IGroupManager $groupManager Instance-admin check for is_admin.
     * @param IUserSession  $userSession  Resolves the current user for is_admin.
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly IInitialState $initialState,
        private readonly IGroupManager $groupManager,
        private readonly IUserSession $userSession,
    ) {
    }//end __construct()

    /**
     * Get the settings form template.
     *
     * Provides the `is_admin` / `opencatalogi_available` IInitialState keys the
     * relocated AiFeatureRegister section reads (ai-features-to-admin) — this page is
     * only reachable by a full instance admin already (getAuthorizedAppConfig() returns
     * `[]`), so is_admin is always true here; resolved via IGroupManager for parity with
     * DashboardController::provideKillSwitchCapability() rather than hardcoded, so it
     * would not silently go stale if this class ever gains real delegation.
     *
     * @return TemplateResponse
     *
     * @spec openspec/changes/ai-features-to-admin/tasks.md#task-3-provide-is_admin--opencatalogi_available-from-the-admin-settings-bootstrap
     */
    public function getForm(): TemplateResponse
    {
        $version = $this->appManager->getAppVersion(appId: Application::APP_ID);

        $user       = $this->userSession->getUser();
        $isAdminNow = ($user !== null && $this->groupManager->isAdmin($user->getUID()) === true);
        $this->initialState->provideInitialState('is_admin', $isAdminNow);
        $this->initialState->provideInitialState(
            'opencatalogi_available',
            $this->appManager->isInstalled('opencatalogi')
        );

        return new TemplateResponse(
            Application::APP_ID,
            'settings/admin',
            ['version' => $version]
        );
    }//end getForm()

    /**
     * Get the section ID this settings page belongs to.
     *
     * @return string
     *
     * @spec exclude Trivial settings-section binding; no behavioural spec.
     */
    public function getSection(): string
    {
        return 'hermiq';
    }//end getSection()

    /**
     * Get the priority for ordering within the section.
     *
     * @return int
     *
     * @spec exclude Trivial settings-ordering priority; no behavioural spec.
     */
    public function getPriority(): int
    {
        return 10;
    }//end getPriority()

    /**
     * The name of this settings sub-section within the Hermiq admin section.
     *
     * Returns null (no named sub-section — the single Hermiq panel), matching the
     * decidesk AdminSettings default.
     *
     * @return string|null
     *
     * @spec exclude Trivial IDelegatedSettings sub-section name; no behavioural spec.
     */
    public function getName(): ?string
    {
        return null;
    }//end getName()

    /**
     * App config keys an authorized (delegated) admin may manage.
     *
     * Empty — no delegated app-config keys are exposed; access defaults to full
     * admins (matching decidesk's AdminSettings).
     *
     * @return array
     *
     * @spec exclude Trivial IDelegatedSettings delegation list (empty); no behavioural spec.
     */
    public function getAuthorizedAppConfig(): array
    {
        return [];
    }//end getAuthorizedAppConfig()
}//end class
