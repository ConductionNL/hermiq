<?php

/**
 * ExampleWidget
 *
 * A minimal Nextcloud Dashboard widget. Copy this file as a starting point
 * for your own dashboard widgets. Each widget needs a matching webpack
 * entry-point in `webpack.config.js` and a Vue renderer registered with
 * `OCA.Dashboard.register(...)` — see `src/exampleWidget.js`.
 *
 * @category Dashboard
 * @package  OCA\Hermiq\Dashboard
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

namespace OCA\Hermiq\Dashboard;

use OCA\Hermiq\AppInfo\Application;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\Util;

/**
 * Example dashboard widget showing the bundling pattern from ADR-004.
 */
class ExampleWidget implements IWidget
{
    /**
     * Constructor.
     *
     * @param IL10N $l10n Localisation service.
     */
    public function __construct(
        private IL10N $l10n,
    ) {

    }//end __construct()

    /**
     * Stable widget identifier — must match the string passed to
     * `OCA.Dashboard.register(...)` in `src/exampleWidget.js`.
     *
     * @return string
     */
    public function getId(): string
    {
        return Application::APP_ID.'_example_widget';

    }//end getId()

    /**
     * Title shown in the dashboard widget picker.
     *
     * @return string
     */
    public function getTitle(): string
    {
        return $this->l10n->t('Example widget');

    }//end getTitle()

    /**
     * Display order. Lower numbers appear first in the picker.
     *
     * @return int
     */
    public function getOrder(): int
    {
        return 10;

    }//end getOrder()

    /**
     * Icon CSS class. Provide a matching `icon-<name>` rule in your CSS.
     *
     * @return string
     */
    public function getIconClass(): string
    {
        return 'icon-'.Application::APP_ID;

    }//end getIconClass()

    /**
     * Optional URL. Returning `null` removes the title-bar link.
     *
     * @return string|null
     */
    public function getUrl(): ?string
    {
        return null;

    }//end getUrl()

    /**
     * Attach the widget's scripts when the dashboard loads.
     *
     * Order matters — the two shared chunks (emitted by webpack
     * `optimization.splitChunks`) MUST load BEFORE the per-widget bundle:
     *
     *   1. shared-vendor  (Vue + pinia + icons)
     *   2. shared-nc-vue  (@nextcloud/vue + @conduction/nextcloud-vue)
     *   3. exampleWidget  (the widget's own renderer)
     *
     * `Util::addScript` dedupes by (app, file), so even when LaunchPad loads
     * every registered widget at once the shared chunks are emitted to the
     * HTML exactly once. See ADR-004 (Build / bundling).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess) — Nextcloud Util API is static by design
     */
    public function load(): void
    {
        Util::addScript(application: Application::APP_ID, file: Application::APP_ID.'-shared-vendor');
        Util::addScript(application: Application::APP_ID, file: Application::APP_ID.'-shared-nc-vue');
        Util::addScript(application: Application::APP_ID, file: Application::APP_ID.'-exampleWidget');

    }//end load()
}//end class
