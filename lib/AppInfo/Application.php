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

use OCA\Hermiq\Listener\AgentBotLifecycleListener;
use OCA\Hermiq\Listener\AgentRunRequestedListener;
use OCA\Hermiq\Listener\RegisterAgentLeafListener;
use OCA\Hermiq\Listener\TalkApprovalReactionListener;
use OCA\Hermiq\Listener\TalkBotInvokeListener;
use OCA\Hermiq\Listener\UserLifecycleListener;
use OCA\Hermiq\Mcp\HermiqToolProvider;
use OCA\Hermiq\Notification\Notifier;
use OCA\Hermiq\TaskProcessing\ContextAgentProvider;
use OCA\Hermiq\TaskProcessing\Text2TextHeadlineProvider;
use OCA\Hermiq\TaskProcessing\AudioToTextProvider;
use OCA\Hermiq\TaskProcessing\Text2TextProvider;
use OCA\Hermiq\TaskProcessing\TextToSpeechProvider;
use OCA\Hermiq\TaskProcessing\Text2TextSummaryProvider;
use OCA\OpenRegister\Event\AgentRunRequestedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserDeletedEvent;

/**
 * Main application class for the Hermiq Nextcloud app.
 *
 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-1-2
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Bootstrap class wires every listener,
 * notifier, tool provider and TaskProcessing provider the app ships — one reference per
 * registered integration point, no behavioural coupling.
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'hermiq';

	/**
	 * Constructor for the Application class.
	 *
	 * @return void
	 */
	public function __construct() {
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
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Linear registration list — one block per
	 * integration point, each with the comment explaining WHY it is registered; splitting it
	 * would scatter the bootstrap story.
	 * @SuppressWarnings(PHPMD.StaticAccess)          \OCP\Util::addInitScript is the Nextcloud
	 * asset API — there is no injectable equivalent in a bootstrap register() hook.
	 *
	 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-1-2
	 */
	public function register(IRegistrationContext $context): void {
		// Load the app's own composer autoloader so bundled dependencies (e.g.
		// dragonmantank/cron-expression, used by ScheduleService) resolve at
		// runtime — Nextcloud does not auto-load an app's vendor/autoload.php.
		// Mirrors openregister/openconnector (ADR-002 dispatcher chain).
		include_once __DIR__ . '/../../vendor/autoload.php';

		// Flow-triggered agent runs (SPECTR-NEXTCLOUD-PLAN.md §5.2, ADR-041): a
		// declarative `x-openregister-flows` action of `type: "agent"` dispatches
		// OpenRegister's AgentRunRequestedEvent; this listener enqueues the governed
		// dispatch (AgentRunRequestedJob → FlowAgentRunService). No class_exists()
		// guard is needed on THIS line specifically — `::class` is a compile-time
		// string, it does not require the class to be loaded — but OpenRegister is
		// NOT enforceable as a versioned dependency via info.xml (NC has no
		// inter-app version-pin mechanism); it is documented in <description> and
		// checked at install/upgrade by Repair\CheckOpenRegisterCompatibility,
		// which is what actually catches a stale-OpenRegister fleet instance.
		$context->registerEventListener(
			event: AgentRunRequestedEvent::class,
			listener: AgentRunRequestedListener::class
		);

		// Each Talk-enabled agent carries its own Talk bot, because the bot
		// record is the ONLY carrier of the name Talk displays (talk-agent-sessions).
		// Hooked on the object lifecycle rather than a controller: agents are
		// written straight through OpenRegister's API (ADR-022), so this is the
		// only place that sees every write, including ones made from OR's own UI.
		$context->registerEventListener(event: ObjectCreatedEvent::class, listener: AgentBotLifecycleListener::class);
		$context->registerEventListener(event: ObjectUpdatedEvent::class, listener: AgentBotLifecycleListener::class);
		$context->registerEventListener(event: ObjectDeletedEvent::class, listener: AgentBotLifecycleListener::class);

		// Consume OpenRegister's flow engine (ADR-022/ADR-065, hermiq#35): hermiq
		// contributes the agent step as a flow NODE, and nothing else. Flows now
		// live in OpenRegister's one native store, so there is no hermiq flow
		// resolver, no hermiq flow store and no hermiq executor — node
		// contribution is the whole of the integration surface. Guarded on the
		// class existing so an instance whose OpenRegister predates the flow
		// engine still boots.
		if (class_exists(\OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent::class) === true) {
			$context->registerEventListener(
				\OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent::class,
				\OCA\Hermiq\Flow\HermiqFlowNodeListener::class
			);
		}

		// Agent render leaf (agent-object-leaf, ADR-019 + ADR-066): contribute the
		// `hermiq-agent` leaf to OpenRegister's cross-app leaf catalogue via the
		// sibling-app leaf-registration hook (RegisterLeafProvidersEvent). This makes
		// an Agent tab/widget discoverable on any OpenRegister object in any OpenBuild
		// app. Guarded on the event class existing so an instance whose OpenRegister
		// predates the leaf hook still boots. The matching JS registration
		// (registerIntegration under the SAME id) ships in the always-loaded
		// `hermiq-agent-leaf` bundle added below.
		if (class_exists(RegisterLeafProvidersEvent::class) === true) {
			$context->registerEventListener(
				RegisterLeafProvidersEvent::class,
				RegisterAgentLeafListener::class
			);

			// Load the leaf's render-registration bundle on EVERY Nextcloud page so
			// `registerIntegration('hermiq-agent', …)` runs wherever an OpenBuild app
			// renders the OpenRegister integration registry — not only on Hermiq's own
			// pages. The load-order-safe registry shim queues the call when OR's bundle
			// has not loaded yet and replays it on install (ADR-019 cross-bundle trap).
			\OCP\Util::addInitScript(self::APP_ID, self::APP_ID . '-agent-leaf');
		}

		// Federated configuration sharing: contribute hermiq's skill type to
		// OpenRegister's shareable-config engine (agent templates ride the schema
		// marker instead). Guarded on the event class existing so an instance whose
		// OpenRegister predates the engine still boots.
		if (class_exists(\OCA\OpenRegister\Service\Config\RegisterShareableConfigTypesEvent::class) === true) {
			$context->registerEventListener(
				\OCA\OpenRegister\Service\Config\RegisterShareableConfigTypesEvent::class,
				\OCA\Hermiq\Listener\ShareableConfigTypeListener::class
			);
		}

		// Offboarding (agent-lifecycle-governance): a Nextcloud user being deleted
		// or disabled auto-pauses their schedules (ScheduleService::pauseForUser())
		// and flags the owning Agent(s) for reassignment. This NC version has no
		// dedicated `DisableUserEvent` — disabling a user fires `UserChangedEvent`
		// with feature='enabled'/value=false (see UserLifecycleListener docblock),
		// so both events are registered against the SAME listener.
		$context->registerEventListener(
			event: UserDeletedEvent::class,
			listener: UserLifecycleListener::class
		);
		$context->registerEventListener(
			event: UserChangedEvent::class,
			listener: UserLifecycleListener::class
		);

		// Inbound Talk chat bridge (talk-chat-bridge): spreed dispatches
		// BotInvokeEvent IN-PROCESS for bots registered with the
		// `nextcloudapp://` URL scheme, so this is a plain listener rather than
		// a webhook endpoint — nothing is exposed to the network.
		//
		// Registered UNCONDITIONALLY and by event NAME, deliberately. Guarding
		// it with `class_exists('OCA\Talk\...')` is the obvious-looking move and
		// is wrong: at register() time a sibling app may not be loaded yet, so
		// the check can return false on a perfectly healthy instance and
		// silently disable the whole feature with nothing in the logs.
		// Registration is cheap and listener resolution is lazy, so the guard
		// would buy nothing and cost the feature. Talk availability is checked
		// at INVOKE time instead, inside the listener (TalkBridge::isAvailable()).
		$context->registerEventListener(
			event: 'OCA\\Talk\\Events\\BotInvokeEvent',
			listener: TalkBotInvokeListener::class
		);

		// Approvals decided by reaction (talk-approval-reactions): spreed
		// invokes bots on reactions with the SAME BotInvokeEvent, so this is a
		// second listener on the same event — each ignores the invocation types
		// that are not its own. Registered unconditionally and by event name for
		// the same reason as the message listener above.
		$context->registerEventListener(
			event: 'OCA\\Talk\\Events\\BotInvokeEvent',
			listener: TalkApprovalReactionListener::class
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
			'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::' . self::APP_ID,
			HermiqToolProvider::class
		);

		// TaskProcessing PROVIDERS (SPECTR-NEXTCLOUD-PLAN.md §8 moves 2 + 3). Hermiq
		// backs Nextcloud's own AI API with its configured LLM, so the whole instance
		// (Assistant, Mail, decidesk — which 503s without any provider) gets AI from
		// one Hermiq config. The text2text family shares the identical input→output
		// shape; the contextagent provider is the governed alternative to NC's stock
		// `context_agent` ExApp (admin picks the preferred provider per task type).
		$context->registerTaskProcessingProvider(AudioToTextProvider::class);
		$context->registerTaskProcessingProvider(TextToSpeechProvider::class);
		$context->registerTaskProcessingProvider(Text2TextProvider::class);
		$context->registerTaskProcessingProvider(Text2TextSummaryProvider::class);
		$context->registerTaskProcessingProvider(Text2TextHeadlineProvider::class);
		$context->registerTaskProcessingProvider(ContextAgentProvider::class);

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
	public function boot(IBootContext $context): void {
	}//end boot()
}//end class
