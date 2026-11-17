<?php

/**
 * Hermiq ListenerRegistrar
 *
 * The event listeners Hermiq registers, and the bundles it loads on every page.
 *
 * @category Listener
 * @package  OCA\Hermiq\Listener
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
 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use Closure;
use OCA\Hermiq\Event\AiOversightRecordedEvent;
use OCA\OpenRegister\Event\AgentRunRequestedEvent;
use OCA\OpenRegister\Event\DraftCreatedEvent;
use OCA\OpenRegister\Event\DraftUpdatedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserDeletedEvent;

/**
 * The Listener component registers its own listeners, so the bootstrap does not name them.
 *
 * Listeners on one event run in registration order, so the order here is part of the contract.
 *
 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Names every listener and the event it listens to.
 */
final class ListenerRegistrar {

	/**
	 * Register every listener, in the order Hermiq has always registered them.
	 *
	 * @param IRegistrationContext $context The registration context.
	 * @param Closure $addInitScript Loads one bundle on every page, given the suffix after the app id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
	 */
	public function register(IRegistrationContext $context, Closure $addInitScript): void {
		$this->registerAgentRunListeners(context: $context);
		$this->registerAgentObjectListeners(context: $context);
		$this->registerOpenRegisterContributions(context: $context, addInitScript: $addInitScript);
		$this->registerUserAndTalkListeners(context: $context);
	}//end register()

	/**
	 * The listeners that start an agent run or store what a person decided about one.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
	 */
	private function registerAgentRunListeners(IRegistrationContext $context): void {
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

		// Hermiq owns human oversight; the apps that run AI against their own
		// domain objects own the moment the human decides. This is how the
		// second tells the first, so Art. 14 evidence lands in one register
		// rather than one per app. Unlike the run-request listener above this
		// one is synchronous: it stores a fact the user already produced, and
		// the consumer reads isHandled() right after dispatch.
		$context->registerEventListener(
			event: AiOversightRecordedEvent::class,
			listener: AiOversightRecordedListener::class
		);
	}//end registerAgentRunListeners()

	/**
	 * Listeners on the object lifecycle of Hermiq's agents.
	 *
	 * Agents are written straight through OpenRegister's API (ADR-022), so the
	 * object events are the only place that sees every write, including ones
	 * made from OR's own UI or its generic objects API.
	 *
	 * - Each Talk-enabled agent carries its own Talk bot, because the bot record
	 *   is the ONLY carrier of the name Talk displays (talk-agent-sessions).
	 * - Adding or removing an approval waiver is audited on every write, drafts
	 *   included: OpenRegister raises only the draft events for a draft.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
	 */
	private function registerAgentObjectListeners(IRegistrationContext $context): void {
		$context->registerEventListener(event: ObjectCreatedEvent::class, listener: AgentBotLifecycleListener::class);
		$context->registerEventListener(event: ObjectUpdatedEvent::class, listener: AgentBotLifecycleListener::class);
		$context->registerEventListener(event: ObjectDeletedEvent::class, listener: AgentBotLifecycleListener::class);

		$context->registerEventListener(event: ObjectCreatedEvent::class, listener: AgentWaiverChangeListener::class);
		$context->registerEventListener(event: ObjectUpdatedEvent::class, listener: AgentWaiverChangeListener::class);
		$context->registerEventListener(event: DraftCreatedEvent::class, listener: AgentWaiverChangeListener::class);
		$context->registerEventListener(event: DraftUpdatedEvent::class, listener: AgentWaiverChangeListener::class);

		// Naming the account an agent works with is checked before the write,
		// on every write path (agent-acting-identity-picker, REQ-AGID-002).
		$context->registerEventListener(event: ObjectCreatingEvent::class, listener: AgentActingUserGuardListener::class);
		$context->registerEventListener(event: ObjectUpdatingEvent::class, listener: AgentActingUserGuardListener::class);
	}//end registerAgentObjectListeners()

	/**
	 * What Hermiq contributes to OpenRegister's engines, and the bundles it loads on every page.
	 *
	 * @param IRegistrationContext $context The registration context.
	 * @param Closure $addInitScript Loads one bundle on every page.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
	 */
	private function registerOpenRegisterContributions(IRegistrationContext $context, Closure $addInitScript): void {
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

		// Oversight (schedules-onto-engine-triggers, EU AI Act Art. 14): contribute
		// hermiq's per-organisation kill switch to the engine's oversight registry,
		// so a `hermiq.*` hop of an engaged organisation's run is vetoed BEFORE it
		// executes — the operator's stop control reaches engine-timed and
		// canvas-authored runs, not only the app-local tick. Guarded on the event
		// class existing so an instance whose OpenRegister predates the oversight
		// registry still boots.
		if (class_exists(\OCA\OpenRegister\Service\Flow\RegisterFlowOversightEvent::class) === true) {
			$context->registerEventListener(
				\OCA\OpenRegister\Service\Flow\RegisterFlowOversightEvent::class,
				\OCA\Hermiq\Listener\FlowOversightRegistrationListener::class
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
			$addInitScript('-agent-leaf');
		}

		// AI companion on EVERY page (companion-everywhere). Until now the chat
		// existed only inside Hermiq's own Vue app, where CnAppRoot renders it from
		// `:aiCompanion="true"` -- which put the assistant one navigation away from
		// the document the user was actually looking at.
		//
		// The office editors are THIRD-PARTY apps (onlyoffice, eurooffice,
		// richdocuments), so there is no CnAppRoot of ours to switch on and no
		// template of ours to edit. An init script is the only seam that reaches
		// them, and it is the same one the agent leaf above already uses.
		//
		// The bundle itself declines to mount on Hermiq's own pages, where
		// CnAppRoot already renders a companion.
		$addInitScript('-companion');

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
	}//end registerOpenRegisterContributions()

	/**
	 * The listeners on Nextcloud's user events, Talk's bot events and OpenRegister's tasks.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
	 */
	private function registerUserAndTalkListeners(IRegistrationContext $context): void {
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

		// Approval-task convergence (phase 1): every pending Approval is
		// mirrored as an OpenRegister task, and a task completed in
		// OpenRegister's shared inbox decides the Approval here. Registered by
		// event NAME (the filinq#988 pattern), deliberately: at register()
		// time the OCA\OpenRegister\ prefix may not be autoloadable yet, so a
		// class_exists() guard could silently disable the whole surface, while
		// registering for a name that is never dispatched is harmless.
		$context->registerEventListener(
			event: \OCA\Hermiq\Listener\TaskTerminalListener::EVENT_TASK_TERMINAL,
			listener: \OCA\Hermiq\Listener\TaskTerminalListener::class
		);

		// Flows-hand-off-and-wait: a flow an agent started closes its handoff
		// when OpenRegister persists the run in a terminal status. By event
		// name for the same reason as the task listener above.
		$context->registerEventListener(
			event: \OCA\Hermiq\Service\Handoff\FlowHandoffs::EVENT_CLASS,
			listener: \OCA\Hermiq\Listener\FlowRunTerminalListener::class
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
	}//end registerUserAndTalkListeners()
}//end class
