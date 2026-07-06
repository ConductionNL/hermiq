<?php

/**
 * Hermiq Application
 *
 * Main application class for the Hermiq Nextcloud app.
 *
 * @category AppInfo
 * @package  OCA\Hermiq\AppInfo
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
 *
 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-1-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\AppInfo;

use OCA\Hermiq\Listener\AgentRunRequestedListener;
use OCA\Hermiq\Listener\DeepLinkRegistrationListener;
use OCA\Hermiq\Mcp\HermiqToolProvider;
use OCA\Hermiq\Notification\Notifier;
use OCA\OpenRegister\Event\AgentRunRequestedEvent;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Main application class for the Hermiq Nextcloud app.
 *
 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-1-2
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'hermiq';

    /**
     * Constructor for the Application class.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(appName: self::APP_ID);
    }//end __construct()

    /**
     * Register event listeners and services.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-1-2
     */
    public function register(IRegistrationContext $context): void
    {
        // Load the app's own composer autoloader so bundled dependencies (e.g.
        // dragonmantank/cron-expression, used by ScheduleService) resolve at
        // runtime — Nextcloud does not auto-load an app's vendor/autoload.php.
        // Mirrors openregister/openconnector (ADR-002 dispatcher chain).
        include_once __DIR__.'/../../vendor/autoload.php';

        // Register deep link patterns with OpenRegister's unified search provider.
        // Only fires when OpenRegister is installed and dispatches the event.
        $context->registerEventListener(
            event: DeepLinkRegistrationEvent::class,
            listener: DeepLinkRegistrationListener::class
        );

        // Flow-triggered agent runs (SPECTR-NEXTCLOUD-PLAN.md §5.2, ADR-041): a
        // declarative `x-openregister-flows` action of `type: "agent"` dispatches
        // OpenRegister's AgentRunRequestedEvent; this listener enqueues the governed
        // dispatch (AgentRunRequestedJob → FlowAgentRunService). OpenRegister is
        // already a hard dependency of Hermiq, so no class_exists() guard is needed
        // here — mirrors the DeepLinkRegistrationEvent registration above.
        $context->registerEventListener(
            event: AgentRunRequestedEvent::class,
            listener: AgentRunRequestedListener::class
        );

        // Renders Hermiq's Nextcloud notifications (talk-delivery): the notification
        // delivery channel and the Talk fallback both raise notifications that this
        // INotifier turns into localised bell-menu entries. See lib/Notification/Notifier.php.
        $context->registerNotifierService(Notifier::class);

        // NC-native agent tools (nc-native-tools): expose Files/Contacts/Calendar/Deck/email
        // to the agent runtime as an IMcpToolProvider. OpenRegister's McpToolsService
        // discovers per-app providers by the alias OCA\OpenRegister\Mcp\IMcpToolProvider::{appId}
        // AND, as a fallback, by the conventional FQCN OCA\Hermiq\Mcp\HermiqToolProvider —
        // the class is named to match that convention so discovery resolves it even when the
        // alias is not visible from OR's container. See lib/Mcp/HermiqToolProvider.php.
        $context->registerServiceAlias(
            'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::'.self::APP_ID,
            HermiqToolProvider::class
        );

    }//end register()

    /**
     * Boot the application.
     *
     * @param IBootContext $context The boot context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec exclude Empty framework boot hook — all wiring happens in register(); no behavioural contract.
     */
    public function boot(IBootContext $context): void
    {
    }//end boot()
}//end class
