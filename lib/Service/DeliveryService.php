<?php

/**
 * Hermiq DeliveryService.
 *
 * The real delivery layer behind ScheduleService's dispatch loop. It replaces the
 * former logging-only deliver() seam: after an agent run it posts the output to the
 * owner where they work — a specific Nextcloud Talk room, the owner's Note-to-self
 * conversation, a Nextcloud notification, an email, or a signed outbound webhook —
 * following the ADR-005 fallback chain (Talk/notification) or the delivery-channels
 * channel's own contract (email/webhook).
 *
 * Architecture law (delivery-channels): Slack/Matrix/Telegram/WhatsApp/Teams are
 * OpenConnector's job, reached THROUGH the outbound webhook this class posts to —
 * Hermiq does not, and will not, grow a per-vendor chat adapter here.
 *
 * This is a legitimate ADR-031 imperative external-integration service: it makes
 * side-effecting calls into Nextcloud subsystems (Talk / Notifications / Mail /
 * HTTP). It owns no schema, no derived value, no lifecycle — those stay declarative
 * in OpenRegister.
 *
 * Design invariants:
 *   - Delivery NEVER throws for a delivery problem; every failure is caught and
 *     reported through a DeliveryResult so the run is never failed by delivery.
 *   - Talk (spreed) is an OPTIONAL runtime dependency: its server-side classes are
 *     resolved LAZILY through the injected server container, only after the core
 *     OCP\Talk\IBroker::hasBackend() probe and a class_exists() guard pass, so
 *     Hermiq boots and still delivers (via notification) when Talk is absent.
 *   - Email and webhook cross the instance boundary, so both redact the output via
 *     RedactionService BEFORE it leaves the process (Talk/notification stay
 *     unredacted — they never leave the instance).
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
 * @spec openspec/changes/archive/2026-07-13-delivery-channels/design.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use Closure;
use DateTime;
use OCA\Hermiq\Service\Connection\ConnectionReporter;
use OCA\Hermiq\Service\Delivery\DeliveryChannels;
use OCA\Hermiq\Service\Delivery\DeliverySafety;
use OCA\Hermiq\Service\Delivery\TalkDelivery;
use OCA\Hermiq\Service\Talk\TalkApprovalNotifier;
use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\Hermiq\Service\Talk\TalkSafeText;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Talk\IBroker;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivers agent run output to Talk / Notifications / Email / Webhook per the
 * schedule's deliver setting.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Coordinates several Nextcloud subsystems
 *   (Talk, Notifications, Mail, HTTP client) plus the webhook secret lookup.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class is a coordinator of many
 *   small, single-shaped alert/delivery methods (run-output delivery via talk/
 *   notification/email/webhook, approval-request, flow-approval-request,
 *   webhook-approval-request, budget-warning, dead-letter alert, circuit-breaker
 *   alert) — each individually simple; the aggregate crosses the class-wide
 *   threshold because the class owns every delivery/alert Hermiq raises rather
 *   than splitting by channel, which would duplicate the shared fallback-chain/
 *   redaction/never-throws plumbing across multiple classes for no behavioural
 *   benefit.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Same single-owner trade-off: every
 *   delivery/alert channel lives here to share the fallback-chain plumbing.
 * @SuppressWarnings(PHPMD.TooManyMethods)           One small deliver/alert method per
 *   channel-and-event pair by design (see ExcessiveClassComplexity rationale).
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     Each caller (schedule runner, approval
 *   gate, budget guard, dead-letter, circuit breaker) gets its own public entry point.
 * @SuppressWarnings(PHPMD.LongVariable)             `$scheduleWebhookSecretService` is a
 *   promoted constructor collaborator named after its class
 *   (ScheduleWebhookSecretService) — shortening it would obscure which service is injected.
 *
 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
 * @spec openspec/changes/archive/2026-07-13-delivery-channels/design.md
 */
class DeliveryService {

	/**
	 * Talk actor type for a Nextcloud user (spreed Attendee::ACTOR_USERS).
	 *
	 * @var string
	 */
	private const ACTOR_USERS = 'users';

	/**
	 * Fully-qualified spreed room manager (resolved lazily; never a hard dependency).
	 *
	 * @var string
	 */
	private const TALK_MANAGER = 'OCA\\Talk\\Manager';

	/**
	 * Fully-qualified spreed participant service (resolved lazily).
	 *
	 * @var string
	 */
	private const TALK_PARTICIPANT_SERVICE = 'OCA\\Talk\\Service\\ParticipantService';

	/**
	 * Fully-qualified spreed Note-to-self service (resolved lazily).
	 *
	 * @var string
	 */
	private const TALK_NOTE_TO_SELF_SERVICE = 'OCA\\Talk\\Service\\NoteToSelfService';

	/**
	 * Fully-qualified spreed chat manager (resolved lazily).
	 *
	 * @var string
	 */
	private const TALK_CHAT_MANAGER = 'OCA\\Talk\\Chat\\ChatManager';

	/**
	 * Fully-qualified spreed room service (resolved lazily) for creating the
	 * per-user default "Hermiq" delivery room.
	 *
	 * @var string
	 */
	private const TALK_ROOM_SERVICE = 'OCA\\Talk\\Service\\RoomService';

	/**
	 * Talk group-conversation type (spreed Room::TYPE_GROUP).
	 *
	 * @var int
	 */
	private const ROOM_TYPE_GROUP = 2;

	/**
	 * Name of the per-user default delivery room created on first delivery.
	 *
	 * @var string
	 */
	private const DEFAULT_ROOM_NAME = 'Hermiq';

	/**
	 * User-config key (under app 'hermiq') storing the owner's default delivery
	 * room token. Matches PreferencesController's `pref_` prefix so the Personal
	 * settings picker and this fallback read/write the same value.
	 *
	 * @var string
	 */
	private const DELIVER_TARGET_PREF = 'pref_delivertarget';

	/**
	 * Outbound webhook JSON envelope size cap in bytes (delivery-channels
	 * design.md Decision 4) — matches agent-webhook-trigger's INBOUND
	 * `WebhookTriggerController::MAX_PAYLOAD_BYTES` for a single precedented
	 * number rather than inventing a new one for the outbound direction.
	 *
	 * @var int
	 */
	private const WEBHOOK_MAX_PAYLOAD_BYTES = 65536;

	/**
	 * Trailing marker appended to a truncated webhook `output` field.
	 *
	 * @var string
	 */
	private const WEBHOOK_TRUNCATION_MARKER = '… [truncated]';

	/**
	 * The outbound webhook signature header name (delivery-channels).
	 *
	 * @var string
	 */
	private const WEBHOOK_SIGNATURE_HEADER = 'X-Hermiq-Signature';

	/**
	 * Hard, non-user-configurable per-attempt HTTP timeout in seconds
	 * (delivery-channels design.md Decision 2) — a hung endpoint cannot itself
	 * defeat the attempt budget.
	 *
	 * @var int
	 */
	private const WEBHOOK_HTTP_TIMEOUT_SECONDS = 10;

	private readonly INotificationManager $notificationManager;

	private readonly IBroker $talkBroker;

	private readonly IMailer $mailer;

	private readonly IClientService $clientService;

	private readonly IURLGenerator $urlGenerator;

	private readonly IUserManager $userManager;

	private readonly TalkRoomBinding $talkRoomBinding;

	private readonly TalkApprovalNotifier $talkApprovalNotifier;

	private readonly RedactionService $redactionService;

	private readonly ScheduleWebhookSecretService $scheduleWebhookSecretService;

	private readonly ?ConnectionReporter $connectionReporter;

	/**
	 * Constructor.
	 *
	 * All constructor dependencies are ALWAYS-present Nextcloud services. The optional
	 * spreed classes are resolved lazily through $container inside the Talk guard, so
	 * this service (and Hermiq) construct cleanly on an instance without Talk.
	 *
	 * @param DeliveryChannels $channels The channels a result is delivered through: notifications, Talk, mail and HTTP.
	 * @param TalkDelivery $talk What a Talk delivery needs: room bindings, approval notices, links and users.
	 * @param ContainerInterface $container Server container for lazy spreed resolution.
	 * @param IConfig $config Reads/writes the owner's default-room preference.
	 * @param DeliverySafety $safety What keeps a delivery safe and observable: redaction, webhook secrets and connection reports.
	 * @param LoggerInterface $logger PSR-3 logger for delivery warnings.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
	 *   distinct always-present Nextcloud/Hermiq collaborator, not a logic-bearing list.
	 */
	public function __construct(
		DeliveryChannels $channels,
		TalkDelivery $talk,
		private readonly ContainerInterface $container,
		private readonly IConfig $config,
		DeliverySafety $safety,
		private readonly LoggerInterface $logger,
	) {
		$this->redactionService = $safety->redactionService;
		$this->scheduleWebhookSecretService = $safety->scheduleWebhookSecretService;
		$this->connectionReporter = $safety->connectionReporter;
		$this->urlGenerator = $talk->urlGenerator;
		$this->userManager = $talk->userManager;
		$this->talkRoomBinding = $talk->talkRoomBinding;
		$this->talkApprovalNotifier = $talk->talkApprovalNotifier;
		$this->notificationManager = $channels->notificationManager;
		$this->talkBroker = $channels->talkBroker;
		$this->mailer = $channels->mailer;
		$this->clientService = $channels->clientService;
	}//end __construct()

	/**
	 * Deliver a run's output for one schedule.
	 *
	 * Never throws for a delivery problem: the outcome (including any warning to
	 * persist as lastDeliveryError) is returned as a DeliveryResult.
	 *
	 * @param string $channel Delivery channel: talk|notification|email|webhook|none.
	 * @param string $output The agent output to deliver.
	 * @param ObjectEntity $schedule The schedule the output belongs to.
	 * @param string|null $conversationUuid The conversation this run produced. When supplied AND
	 *                                      the output reaches a Talk room, that conversation is
	 *                                      bound to the room so a reply there continues this
	 *                                      session (talk-chat-bridge). Null leaves the binding a
	 *                                      no-op — every pre-existing caller is unchanged.
	 *
	 * @return DeliveryResult The delivery outcome.
	 *
	 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
	 * @spec openspec/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-email-mvp
	 * @spec openspec/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-a-signed-outbound-webhook-mvp
	 */
	public function deliver(string $channel, string $output, ObjectEntity $schedule, ?string $conversationUuid = null): DeliveryResult {
		// Per-delivery state: which conversation this run produced, so a Talk
		// room delivery can bind it and become repliable. Null for every caller
		// that does not know (and for every non-Talk channel), which leaves the
		// binding step a no-op.
		$delivery = new ScheduleDelivery(schedule: $schedule, output: $output);
		$delivery->conversationUuid = $conversationUuid;

		return $this->deliverOn(delivery: $delivery, channel: $channel);

	}//end deliver()

	/**
	 * Deliver over the named channel; an empty or `none` channel, or silent/empty
	 * output, is a deliberate no-op and an unknown channel a warning, never a failure.
	 *
	 * @param ScheduleDelivery $delivery The delivery.
	 * @param string           $channel  The delivery channel.
	 *
	 * @return DeliveryResult The delivery outcome.
	 *
	 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
	 */
	private function deliverOn(ScheduleDelivery $delivery, string $channel): DeliveryResult {
		if ($channel === '' || $channel === 'none' || trim($delivery->output) === '') {
			return new DeliveryResult(delivered: false, channel: 'none', fellBack: false, warning: null);
		}

		return match ($channel) {
			'notification' => $this->deliverNotification(delivery: $delivery),
			'talk' => $this->deliverTalk(delivery: $delivery),
			'email' => $this->deliverEmail(schedule: $delivery->schedule, output: $delivery->output),
			'webhook' => $this->deliverWebhook(schedule: $delivery->schedule, output: $delivery->output),
			default => $this->unknownChannel(channel: $channel),
		};

	}//end deliverOn()

	/**
	 * Unknown channel: record a warning but never fail the run.
	 *
	 * @param string $channel The unknown channel name.
	 *
	 * @return DeliveryResult The undelivered outcome with its warning.
	 */
	private function unknownChannel(string $channel): DeliveryResult {
		return new DeliveryResult(
			delivered: false,
			channel: $channel,
			fellBack: false,
			warning: sprintf("Unknown delivery channel '%s'", $channel)
		);

	}//end unknownChannel()

	/**
	 * Notify a schedule's resolved reviewer(s) that an approval is pending (Art. 14).
	 *
	 * The human-approval gate created a pending Approval and needs the reviewer — the
	 * designated user, or every member of the reviewer group — alerted where they
	 * work. Each reviewer receives a Nextcloud notification (rendered by the Hermiq
	 * INotifier and deep-linked to the approvals inbox) and, when Talk is available, a
	 * best-effort Note-to-self message. NEVER throws for a delivery problem: any
	 * failure is caught and reported through a DeliveryResult so the dispatch tick is
	 * never failed by a notification.
	 *
	 * @param ObjectEntity $schedule The gated schedule.
	 * @param ObjectEntity $approval The pending approval to link to.
	 * @param array<int,string> $reviewerUids The resolved reviewer user ids.
	 *
	 * @return DeliveryResult The notification outcome (warning ⇒ degraded delivery).
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#2-dispatcher-approval-gate-scheduleservice
	 */
	public function deliverApprovalRequest(ObjectEntity $schedule, ObjectEntity $approval, array $reviewerUids): DeliveryResult {
		$scheduleName = (string)($schedule->getObject()['name'] ?? '');

		// Bonus surface: post into the agent's bound Talk room as the bot so
		// the reviewer can decide with a reaction. Best-effort — the
		// notification below is the guaranteed delivery.
		$this->talkApprovalNotifier->postRequest(approval: $approval, displayName: $scheduleName);

		return $this->notifyApprovalReviewers(
			round: new ApprovalRound(approvalUuid: (string)$approval->getUuid(), reviewerUids: $reviewerUids),
			wording: new ApprovalWording(displayName: $scheduleName, messageParams: ['scheduleId' => (string)$schedule->getUuid()])
		);

	}//end deliverApprovalRequest()

	/**
	 * Notify a flow-triggered agent run's resolved reviewer(s) that an approval is
	 * pending (Art. 14) — the `sourceType: "flow"` counterpart to
	 * `deliverApprovalRequest()`. There is no Schedule ObjectEntity to read a display
	 * name from; the approval's own `flowContext.flowName` (or agentId as a fallback)
	 * is used instead. NEVER throws for a delivery problem.
	 *
	 * @param ObjectEntity $approval The pending approval to link to.
	 * @param array<int,string> $reviewerUids The resolved reviewer user ids.
	 *
	 * @return DeliveryResult The notification outcome (warning ⇒ degraded delivery).
	 *
	 * @spec openspec/changes/archive/2026-09-06-flow-agent-listener/tasks.md#3-approvalservice-generalisation-sourcetype-flow
	 */
	public function deliverApprovalRequestForFlowRun(ObjectEntity $approval, array $reviewerUids): DeliveryResult {
		$data = $approval->getObject();
		$flowContext = $data['flowContext'] ?? [];
		if (is_array($flowContext) === false) {
			$flowContext = [];
		}

		$displayName = (string)($flowContext['flowName'] ?? ($data['agentId'] ?? ''));

		return $this->notifyApprovalReviewers(
			round: new ApprovalRound(approvalUuid: (string)$approval->getUuid(), reviewerUids: $reviewerUids),
			wording: new ApprovalWording(displayName: $displayName, messageParams: ['correlationId' => (string)($flowContext['correlationId'] ?? '')])
		);

	}//end deliverApprovalRequestForFlowRun()

	/**
	 * Notify a webhook-triggered agent run's resolved reviewer(s) that an
	 * approval is pending (Art. 14) — the `sourceType: "webhook"` counterpart to
	 * `deliverApprovalRequest()`/`deliverApprovalRequestForFlowRun()`. There is
	 * no Schedule ObjectEntity to read a display name from; the approval's own
	 * `agentId` is used as the display name (a webhook trigger has no comparable
	 * "flowName"). NEVER throws for a delivery problem.
	 *
	 * @param ObjectEntity $approval The pending approval to link to.
	 * @param array<int,string> $reviewerUids The resolved reviewer user ids.
	 *
	 * @return DeliveryResult The notification outcome (warning ⇒ degraded delivery).
	 *
	 * @spec openspec/changes/archive/2026-07-12-agent-webhook-trigger/tasks.md#task-6-deliveryservice-webhook-approval-notification-shared-reviewer-notify-helper
	 */
	public function deliverApprovalRequestForWebhookRun(ObjectEntity $approval, array $reviewerUids): DeliveryResult {
		$data = $approval->getObject();
		$webhookContext = $data['webhookContext'] ?? [];
		if (is_array($webhookContext) === false) {
			$webhookContext = [];
		}

		$displayName = (string)($data['agentId'] ?? '');

		return $this->notifyApprovalReviewers(
			round: new ApprovalRound(approvalUuid: (string)$approval->getUuid(), reviewerUids: $reviewerUids),
			wording: new ApprovalWording(displayName: $displayName, messageParams: ['correlationId' => (string)($webhookContext['correlationId'] ?? '')])
		);

	}//end deliverApprovalRequestForWebhookRun()

	/**
	 * Notify a skill consolidation draft's resolved reviewer(s) that an approval is
	 * pending (skill-self-improvement, Art. 14) — the `sourceType: "skill-draft"`
	 * counterpart to the ensure-approval deliveries above. The display name is the
	 * skill's name (the human anchor of the review); the approval deep link leads to
	 * the inbox, whose payload carries the SkillDetail deep link with the full diff.
	 * NEVER throws for a delivery problem.
	 *
	 * @param ObjectEntity $approval The pending approval to link to.
	 * @param array<int,string> $reviewerUids The resolved reviewer user ids.
	 * @param string $skillName The skill's display name.
	 *
	 * @return DeliveryResult The notification outcome (warning ⇒ degraded delivery).
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
	 */
	public function deliverApprovalRequestForSkillDraft(
		ObjectEntity $approval,
		array $reviewerUids,
		string $skillName,
	): DeliveryResult {
		$data = $approval->getObject();

		return $this->notifyApprovalReviewers(
			round: new ApprovalRound(approvalUuid: (string)$approval->getUuid(), reviewerUids: $reviewerUids),
			wording: new ApprovalWording(displayName: $skillName, messageParams: ['draftId' => (string)($data['draftId'] ?? '')])
		);

	}//end deliverApprovalRequestForSkillDraft()

	/**
	 * Notify a skill's publisher that its published GitHub copy is now BEHIND the
	 * locally accepted version (skill-self-improvement republish signal). Raised once
	 * per newly-behind transition by the draft apply step — never on every pass, and
	 * never accompanied by any automatic GitHub call. NEVER throws.
	 *
	 * @param string $skillUuid The skill UUID (deep link target).
	 * @param string $skillName The skill's display name.
	 * @param string $recipientUid The publisher to notify.
	 *
	 * @return DeliveryResult The notification outcome.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-an-accepted-version-behind-the-published-copy-raises-an-explicit-republish-signal
	 */
	public function deliverSkillPublishedBehind(string $skillUuid, string $skillName, string $recipientUid): DeliveryResult {
		if ($recipientUid === '') {
			return new DeliveryResult(delivered: false, channel: 'none', fellBack: false, warning: 'No publisher to notify.');
		}

		return $this->deliverSkillNotice(
			recipientUid: $recipientUid,
			configure: fn (INotification $notification): INotification => $notification
				->setObject('skill', $skillUuid)
				->setSubject('skill_published_behind', ['name' => $skillName])
				->setMessage('skill_published_behind_summary', [])
				->setLink($this->buildSkillLink(uuid: $skillUuid))
		);
	}//end deliverSkillPublishedBehind()

	/**
	 * Notify the accepting reviewer that the NEXT eval run after their acceptance
	 * regressed — the advisory "roll back to previous version?" suggestion
	 * (skill-self-improvement regression watch). Advisory only: no rollback happens
	 * without an explicit request on SkillDetail. NEVER throws.
	 *
	 * @param string $skillUuid The skill UUID (deep link target).
	 * @param string $skillName The skill's display name.
	 * @param string $recipientUid The accepting reviewer to notify.
	 *
	 * @return DeliveryResult The notification outcome.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-post-acceptance-regression-surfaces-a-rollback-suggestion
	 */
	public function deliverSkillRollbackSuggestion(string $skillUuid, string $skillName, string $recipientUid): DeliveryResult {
		if ($recipientUid === '') {
			return new DeliveryResult(delivered: false, channel: 'none', fellBack: false, warning: 'No reviewer to notify.');
		}

		return $this->deliverSkillNotice(
			recipientUid: $recipientUid,
			configure: fn (INotification $notification): INotification => $notification
				->setObject('skill', $skillUuid)
				->setSubject('skill_rollback_suggested', ['name' => $skillName])
				->setMessage('skill_rollback_suggested_summary', [])
				->setLink($this->buildSkillLink(uuid: $skillUuid))
		);
	}//end deliverSkillRollbackSuggestion()

	/**
	 * Notify one recipient of a skill event, with no Talk message and no log line:
	 * `channel: none` when the notification could not be raised.
	 *
	 * @param string $recipientUid The recipient.
	 * @param Closure(INotification): INotification $configure Sets object, subject, message and link.
	 *
	 * @return DeliveryResult The notification outcome.
	 */
	private function deliverSkillNotice(string $recipientUid, Closure $configure): DeliveryResult {
		$warning = $this->sendNotification(uid: $recipientUid, configure: $configure);
		if ($warning !== null) {
			return new DeliveryResult(delivered: false, channel: 'none', fellBack: false, warning: $warning);
		}

		return new DeliveryResult(delivered: true, channel: 'notification', fellBack: false, warning: null);
	}//end deliverSkillNotice()

	/**
	 * Shared reviewer-notification loop behind `deliverApprovalRequest()`,
	 * `deliverApprovalRequestForFlowRun()`, and `deliverApprovalRequestForWebhookRun()`
	 * (design.md Decision 3) — extracted so a THIRD near-identical copy (after the
	 * original, then the flow-run counterpart) is not typed out again, which would
	 * otherwise let a future fourth source (or a fix) drift across three copies.
	 * Raises one Nextcloud notification per resolved reviewer (deep-linked to the
	 * approvals inbox) and, when Talk is available, a best-effort Note-to-self
	 * message. NEVER throws for a delivery problem.
	 *
	 * @param ApprovalRound $round The pending approval and its resolved reviewers.
	 * @param ApprovalWording $wording The human label for the gated run (schedule
	 *                                 name / flow name / agent id) and the
	 *                                 `approval_summary` message parameters.
	 *
	 * @return DeliveryResult The notification outcome (warning ⇒ degraded delivery).
	 *
	 * @spec openspec/changes/archive/2026-07-12-agent-webhook-trigger/tasks.md#task-6-deliveryservice-webhook-approval-notification-shared-reviewer-notify-helper
	 */
	private function notifyApprovalReviewers(ApprovalRound $round, ApprovalWording $wording): DeliveryResult {
		$displayName = $wording->displayName;
		$result = $this->notifyRecipients(
			uids: $round->reviewerUids,
			notice: new RecipientNotice(
				configure: $this->approvalConfigure(approvalUuid: $round->approvalUuid, wording: $wording),
				talkText: static fn (): string => sprintf(
					'Approval needed for %s. Review it in Hermiq.',
					(new TalkSafeText())->quoted(name: $displayName, fallback: 'an action')
				)
			)
		);

		$this->logWarning(warning: $result->getWarning(), uuid: $round->approvalUuid, channel: 'approval');
		return $result;
	}//end notifyApprovalReviewers()

	/**
	 * Fill in an approval request's notification: the approval, the gated run's
	 * name, the summary parameters and the approvals-inbox deep link.
	 *
	 * @param string $approvalUuid The pending approval's UUID.
	 * @param ApprovalWording $wording The display name and message parameters.
	 *
	 * @return Closure(INotification): INotification The notification filler.
	 */
	private function approvalConfigure(string $approvalUuid, ApprovalWording $wording): Closure {
		return fn (INotification $notification): INotification => $notification
			->setObject('approval', $approvalUuid)
			->setSubject('approval_requested', ['name' => $wording->displayName])
			->setMessage('approval_summary', $wording->messageParams)
			->setLink($this->buildApprovalLink(uuid: $approvalUuid));
	}//end approvalConfigure()

	/**
	 * Notify every non-empty recipient, and post the notice's Talk text to each
	 * one's Note-to-self when Talk is available (best-effort — a bonus channel,
	 * never required). NEVER throws for a delivery problem.
	 *
	 * @param array<int, mixed> $uids The recipient user ids.
	 * @param RecipientNotice $notice What to tell each of them.
	 *
	 * @return DeliveryResult Delivered when any notification was raised; the joined warnings otherwise.
	 */
	private function notifyRecipients(array $uids, RecipientNotice $notice): DeliveryResult {
		$talkOk = $this->isTalkAvailable();
		$failures = [];

		foreach ($uids as $uid) {
			$uid = (string)$uid;
			if ($uid === '') {
				continue;
			}

			$failures[] = $this->sendNotification(uid: $uid, configure: $notice->configure);

			if ($talkOk === true) {
				$this->tryPostToNoteToSelf(post: new TalkPost(owner: $uid, message: ($notice->talkText)()));
			}
		}

		return $this->noticeResult(failures: $failures);
	}//end notifyRecipients()

	/**
	 * The outcome of a notice round: `notification` channel, delivered when any
	 * notification was raised, with the joined warnings.
	 *
	 * @param array<int, string|null> $failures One warning (or null when raised) per recipient.
	 *
	 * @return DeliveryResult The outcome.
	 */
	private function noticeResult(array $failures): DeliveryResult {
		$warnings = array_values(array_filter($failures, static fn (?string $failure): bool => $failure !== null));
		$warning = null;
		if ($warnings !== []) {
			$warning = implode('; ', $warnings);
		}

		return new DeliveryResult(delivered: in_array(null, $failures, true), channel: 'notification', fellBack: false, warning: $warning);
	}//end noticeResult()

	/**
	 * Raise one Nextcloud notification from hermiq to $uid, filled in by $configure.
	 *
	 * @param string $uid The recipient.
	 * @param Closure(INotification): INotification $configure Sets object, subject, message and link.
	 *
	 * @return string|null Null when raised; the warning when it could not be.
	 */
	private function sendNotification(string $uid, Closure $configure): ?string {
		try {
			$notification = $this->notificationManager->createNotification();
			$configure($notification->setApp('hermiq')->setUser($uid)->setDateTime(new DateTime()));
			$this->notificationManager->notify($notification);
			return null;
		} catch (Throwable $e) {
			return sprintf('notify %s failed: %s', $uid, $e->getMessage());
		}
	}//end sendNotification()

	/**
	 * Notify a tool-invocation approval's resolved reviewer(s) that an
	 * un-granted destructive tool call is pending (Art. 14) — the
	 * `sourceType: "tool"` counterpart to `deliverApprovalRequestForFlowRun()`
	 * (agent-tool-governance-and-disclosure). The approval's own `toolId` (or
	 * `agentId` as a fallback) is used as the display name. NEVER throws for a
	 * delivery problem.
	 *
	 * @param ObjectEntity $approval The pending approval to link to.
	 * @param array<int,string> $reviewerUids The resolved reviewer user ids.
	 *
	 * @return DeliveryResult The notification outcome (warning ⇒ degraded delivery).
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#scenario-an-agent-attempts-an-un-granted-destructive-tool-call
	 */
	public function deliverApprovalRequestForToolInvocation(ObjectEntity $approval, array $reviewerUids): DeliveryResult {
		$approvalUuid = (string)$approval->getUuid();
		$data = $approval->getObject();
		$toolId = (string)($data['toolId'] ?? ($data['agentId'] ?? ''));

		$result = $this->notifyRecipients(
			uids: $reviewerUids,
			notice: $this->toolApprovalNotice(approvalUuid: $approvalUuid, toolId: $toolId)
		);

		$this->logWarning(warning: $result->getWarning(), uuid: $approvalUuid, channel: 'approval');
		return $result;
	}//end deliverApprovalRequestForToolInvocation()

	/**
	 * The approval notice for a tool call: named by the tool, with the Talk line
	 * that names the tool and its reach.
	 *
	 * @param string $approvalUuid The pending approval's UUID.
	 * @param string $toolId The tool id (or the agent id when the approval names no tool).
	 *
	 * @return RecipientNotice The notice.
	 */
	private function toolApprovalNotice(string $approvalUuid, string $toolId): RecipientNotice {
		return new RecipientNotice(
			configure: $this->approvalConfigure(
				approvalUuid: $approvalUuid,
				wording: new ApprovalWording(displayName: $toolId, messageParams: ['toolId' => $toolId])
			),
			talkText: fn (): string => $this->toolApprovalTalkText(toolId: $toolId)
		);
	}//end toolApprovalNotice()

	/**
	 * Notify a budget's resolved recipient(s) that its soft threshold was crossed
	 * (cost-guardrails). Fires at most once per period — `BudgetService` gates the
	 * call so this method is only ever invoked when a warning is actually due. NEVER
	 * throws for a delivery problem: any failure is caught and reported through a
	 * DeliveryResult so the dispatch tick/run is never failed by a notification.
	 *
	 * @param ObjectEntity $budget The budget whose soft threshold was crossed.
	 * @param array<int,string> $recipientUids The resolved recipient user ids (the
	 *                                         organisation owner).
	 *
	 * @return DeliveryResult The notification outcome (warning ⇒ degraded delivery).
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-3-wire-the-budget-gate-into-the-dispatch-path-soft-threshold-delivery
	 */
	public function deliverBudgetWarning(ObjectEntity $budget, array $recipientUids): DeliveryResult {
		$result = $this->notifyRecipients(uids: $recipientUids, notice: $this->budgetNotice(budget: $budget));

		$this->logWarning(warning: $result->getWarning(), uuid: (string)$budget->getUuid(), channel: 'budget');
		return $result;
	}//end deliverBudgetWarning()

	/**
	 * The soft-threshold notice for a budget, labelled by its organisation, or by
	 * its agent for an agent-scope budget.
	 *
	 * @param ObjectEntity $budget The budget whose soft threshold was crossed.
	 *
	 * @return RecipientNotice The notice.
	 */
	private function budgetNotice(ObjectEntity $budget): RecipientNotice {
		$budgetUuid = (string)$budget->getUuid();
		$scope = (string)($budget->getObject()['scope'] ?? 'organisation');
		$label = $this->budgetLabel(budget: $budget, scope: $scope);

		return new RecipientNotice(
			configure: fn (INotification $notification): INotification => $notification
				->setObject('budget', $budgetUuid)
				->setSubject('budget_soft_threshold', ['scope' => $scope, 'label' => $label])
				->setMessage('budget_soft_threshold_summary', ['budgetId' => $budgetUuid])
				->setLink($this->buildBudgetLink(uuid: $budgetUuid)),
			talkText: static fn (): string => sprintf('Budget "%s" crossed its soft threshold. Review it in Hermiq.', $label)
		);
	}//end budgetNotice()

	/**
	 * A budget's label: its organisation, or its agent for an agent-scope budget.
	 *
	 * @param ObjectEntity $budget The budget.
	 * @param string $scope The budget's scope.
	 *
	 * @return string The label.
	 */
	private function budgetLabel(ObjectEntity $budget, string $scope): string {
		if ($scope === 'agent') {
			return (string)($budget->getObject()['agentId'] ?? '');
		}

		return (string)($budget->getOrganisation() ?? '');
	}//end budgetLabel()

	/**
	 * Notify a schedule's owner that a run has been marked dead-letter after
	 * exhausting its retry budget (run-reliability). Fires REGARDLESS of the
	 * schedule's own `deliver` output-channel setting (including `deliver=none`) —
	 * this is a proactive reliability alert, not a run-output delivery. Raises a
	 * Nextcloud notification (rendered by the Hermiq INotifier, deep-linked to the
	 * schedule) and, when Talk is available, a best-effort Note-to-self message.
	 * NEVER throws for a delivery problem: any failure is caught and reported
	 * through a DeliveryResult so the dispatch tick/run is never failed by a
	 * notification (mirrors deliverApprovalRequest()/deliverBudgetWarning()).
	 *
	 * @param ObjectEntity $schedule The dead-lettered schedule.
	 * @param string $reason The failure reason (the last agent-turn error).
	 *
	 * @return DeliveryResult The notification outcome (warning ⇒ degraded delivery).
	 *
	 * @spec openspec/specs/talk-delivery/spec.md#requirement-deliver-a-failure-alert-to-the-schedule-owner-mvp
	 */
	public function deliverFailureAlert(ObjectEntity $schedule, string $reason): DeliveryResult {
		return $this->alertScheduleOwner(
			schedule: $schedule,
			alert: new ScheduleAlert(
				logChannel: 'dead_letter',
				notice: fn (string $scheduleUuid, string $scheduleName): RecipientNotice => new RecipientNotice(
					configure: fn (INotification $notification): INotification => $notification
						->setObject('schedule', $scheduleUuid)
						->setSubject('run_dead_letter', ['name' => $scheduleName])
						->setMessage('run_dead_letter_summary', ['reason' => $reason])
						->setLink($this->buildScheduleLink(uuid: $scheduleUuid)),
					talkText: static fn (): string => sprintf('Schedule “%s” failed permanently after exhausting its retries: %s', $scheduleName, $reason)
				)
			)
		);
	}//end deliverFailureAlert()

	/**
	 * Notify a schedule's owner that it has been auto-paused by the consecutive-
	 * dead-letter circuit breaker (run-reliability). Distinct from
	 * deliverFailureAlert(): the owner receives a SEPARATE notification stating the
	 * schedule was disabled, not merely that one occurrence failed. Fires
	 * REGARDLESS of the schedule's own `deliver` setting. NEVER throws for a
	 * delivery problem — same non-fatal contract as every other delivery method.
	 *
	 * @param ObjectEntity $schedule The auto-paused schedule.
	 *
	 * @return DeliveryResult The notification outcome (warning ⇒ degraded delivery).
	 *
	 * @spec openspec/specs/talk-delivery/spec.md#requirement-deliver-a-failure-alert-to-the-schedule-owner-mvp
	 */
	public function deliverCircuitBreakerAlert(ObjectEntity $schedule): DeliveryResult {
		return $this->alertScheduleOwner(
			schedule: $schedule,
			alert: new ScheduleAlert(
				logChannel: 'circuit_breaker',
				notice: fn (string $scheduleUuid, string $scheduleName): RecipientNotice => new RecipientNotice(
					configure: fn (INotification $notification): INotification => $notification
						->setObject('schedule', $scheduleUuid)
						->setSubject('schedule_paused_circuit_breaker', ['name' => $scheduleName])
						->setMessage('schedule_paused_circuit_breaker_summary', ['scheduleId' => $scheduleUuid])
						->setLink($this->buildScheduleLink(uuid: $scheduleUuid)),
					talkText: static fn (): string => sprintf('Schedule “%s” was automatically paused after repeated failures. Review it in Hermiq.', $scheduleName)
				)
			)
		);
	}//end deliverCircuitBreakerAlert()

	/**
	 * Send a schedule's owner a proactive alert (with its Talk Note-to-self) and log
	 * a degraded delivery under the alert's channel. Needs an owner.
	 *
	 * @param ObjectEntity $schedule The schedule the alert is about.
	 * @param ScheduleAlert $alert The alert.
	 *
	 * @return DeliveryResult The notification outcome (warning ⇒ degraded delivery).
	 */
	private function alertScheduleOwner(ObjectEntity $schedule, ScheduleAlert $alert): DeliveryResult {
		$owner = (string)($schedule->getOwner() ?? '');
		if ($owner === '') {
			return new DeliveryResult(delivered: false, channel: 'notification', fellBack: false, warning: 'Schedule has no owner to notify');
		}

		$scheduleUuid = (string)$schedule->getUuid();
		$scheduleName = (string)($schedule->getObject()['name'] ?? '');
		$result = $this->notifyRecipients(uids: [$owner], notice: ($alert->notice)($scheduleUuid, $scheduleName));

		$this->logWarning(warning: $result->getWarning(), uuid: $scheduleUuid, channel: $alert->logChannel);
		return $result;
	}//end alertScheduleOwner()

	/**
	 * Deliver via the Talk fallback chain: target room → Note-to-self → notification.
	 *
	 * Each fall-through accumulates a warning; the returned DeliveryResult carries the
	 * combined warning (to be persisted as lastDeliveryError) and the channel used.
	 *
	 * @param ScheduleDelivery $delivery The delivery.
	 *
	 * @return DeliveryResult The Talk delivery outcome.
	 *
	 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
	 */
	private function deliverTalk(ScheduleDelivery $delivery): DeliveryResult {
		if ($this->isTalkAvailable() === false) {
			return $this->deliverNotification(delivery: $delivery, fallbackReason: 'Talk (spreed) is not available');
		}

		$reasons = [];
		$target = $this->talkTarget(delivery: $delivery);

		// 1) Targeted room (membership-checked) when deliverTarget is set.
		if ($target !== '') {
			$posted = $this->tryPostToTargetRoom(token: $target, post: $delivery->talkPost());
			if ($posted === null) {
				return $this->deliveredToRoom(delivery: $delivery, token: $target);
			}

			$reasons[] = $posted;
		}

		return $this->deliverToNoteToSelf(delivery: $delivery, reasons: $reasons);

	}//end deliverTalk()

	/**
	 * The Talk room to deliver to: the schedule's target, or else the owner's default
	 * delivery room.
	 *
	 * When the schedule names no room, fall back to the owner's default delivery room
	 * (Personal settings) — lazily creating a "Hermiq" room the first time so a user
	 * with nothing configured still gets a real room rather than only Note-to-self.
	 * Any failure leaves the target empty and the Note-to-self fallback still applies.
	 *
	 * @param ScheduleDelivery $delivery The delivery.
	 *
	 * @return string The room token, or '' for none.
	 */
	private function talkTarget(ScheduleDelivery $delivery): string {
		$target = $delivery->target();
		if ($target === '') {
			$target = $this->resolveDefaultRoom(owner: $delivery->owner());
		}

		return $target;

	}//end talkTarget()

	/**
	 * The report reached a room: bind the conversation this run produced to that
	 * room, so a reply there continues THIS session instead of dead-ending
	 * (talk-chat-bridge). Strictly best-effort: bindByUuid() swallows its own
	 * failures, so a binding problem can never fail a delivery or a run.
	 *
	 * @param ScheduleDelivery $delivery The delivery.
	 * @param string           $token    The room the output was posted into.
	 *
	 * @return DeliveryResult The delivered outcome.
	 */
	private function deliveredToRoom(ScheduleDelivery $delivery, string $token): DeliveryResult {
		if ($delivery->conversationUuid !== null) {
			$this->talkRoomBinding->bindByUuid(conversationUuid: $delivery->conversationUuid, roomToken: $token);
		}

		return new DeliveryResult(delivered: true, channel: 'talk', fellBack: false, warning: null);

	}//end deliveredToRoom()

	/**
	 * 2) The owner's Note-to-self conversation; 3) else, a Nextcloud notification.
	 *
	 * @param ScheduleDelivery   $delivery The delivery.
	 * @param array<int, string> $reasons  Why the earlier steps fell through.
	 *
	 * @return DeliveryResult The Talk or notification outcome.
	 */
	private function deliverToNoteToSelf(ScheduleDelivery $delivery, array $reasons): DeliveryResult {
		$noteReason = $this->tryPostToNoteToSelf(post: $delivery->talkPost());
		if ($noteReason === null) {
			$warning = null;
			if ($reasons !== []) {
				$warning = implode('; ', $reasons) . ' — delivered to Note-to-self';
			}

			$this->logWarning(warning: $warning, uuid: $delivery->uuid(), channel: 'talk');
			return new DeliveryResult(delivered: true, channel: 'talk', fellBack: ($reasons !== []), warning: $warning);
		}

		$reasons[] = $noteReason;

		return $this->deliverNotification(delivery: $delivery, fallbackReason: implode('; ', $reasons));

	}//end deliverToNoteToSelf()

	/**
	 * Attempt to post to a specific Talk room the poster must be a member of.
	 *
	 * Membership is enforced by resolving the room owner-scoped via
	 * Manager::getRoomForUserByToken(token, owner), which throws when the owner has no
	 * access — Hermiq never posts to a room the owner is not in.
	 *
	 * @param string   $token The deliverTarget room token.
	 * @param TalkPost $post  The message and its poster (already impersonated).
	 *
	 * @return string|null Null on success, or a reason string when the room is unusable.
	 *
	 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
	 */
	private function tryPostToTargetRoom(string $token, TalkPost $post): ?string {
		try {
			$room = $this->talkManager()->getRoomForUserByToken($token, $post->owner);
			$this->postToRoom(room: $room, post: $post);
			return null;
		} catch (Throwable $e) {
			return sprintf("target room '%s' unavailable: %s", $token, $e->getMessage());
		}//end try

	}//end tryPostToTargetRoom()

	/**
	 * The Talk text for a held tool call: what needs approval, and one line
	 * naming the tool and how far it reaches, so the reviewer knows before
	 * opening Hermiq. Falls back to the text without that line when the preview
	 * builder cannot be had; the approval itself is never affected. A tool id
	 * Talk could read as a mention, link or markdown is not put in the text.
	 *
	 * @param string $toolId The held tool id.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-the-talk-request-names-the-tool-and-its-reach-req-apprev-002
	 */
	private function toolApprovalTalkText(string $toolId): string {
		$line = '';
		try {
			$builder = $this->container->get(ApprovalPreviewBuilder::class);
			if ($builder instanceof ApprovalPreviewBuilder) {
				$line = ' ' . $builder->toolLine(toolId: $toolId);
			}
		} catch (Throwable $e) {
			$this->logger->debug('[DeliveryService] no tool line for the approval message: ' . $e->getMessage());
		}

		$name = (new TalkSafeText())->identifier(identifier: $toolId);
		if ($name === null) {
			return sprintf('Approval needed to run a tool.%s Review it in Hermiq.', $line);
		}

		return sprintf('Approval needed to run “%s”.%s Review it in Hermiq.', $name, $line);
	}//end toolApprovalTalkText()

	/**
	 * Attempt to post to the poster's Note-to-self conversation.
	 *
	 * @param TalkPost $post The message and its poster.
	 *
	 * @return string|null Null on success, or a reason string on failure.
	 *
	 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
	 */
	private function tryPostToNoteToSelf(TalkPost $post): ?string {
		try {
			$room = $this->talkNoteToSelfService()->ensureNoteToSelfExistsForUser($post->owner);
			$this->postToRoom(room: $room, post: $post);
			return null;
		} catch (Throwable $e) {
			return 'Note-to-self unavailable: ' . $e->getMessage();
		}//end try

	}//end tryPostToNoteToSelf()

	/**
	 * Post a message to a resolved Talk room as its poster via ChatManager::sendMessage.
	 *
	 * @param object   $room The resolved spreed Room (the poster is a member).
	 * @param TalkPost $post The message and its poster (actorId).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
	 */
	private function postToRoom(object $room, TalkPost $post): void {
		$participant = $this->talkParticipantService()->getParticipant($room, $post->owner);
		$this->talkChatManager()->sendMessage(
			$room,
			$participant,
			self::ACTOR_USERS,
			$post->owner,
			$post->message,
			new DateTime()
		);

	}//end postToRoom()

	/**
	 * Raise a Nextcloud notification to the schedule owner linking to the schedule.
	 *
	 * The baseline channel and the terminal fallback of the Talk chain. Has no Talk
	 * dependency. A failure here is still non-fatal — it is caught and reported.
	 *
	 * @param ScheduleDelivery $delivery       The delivery.
	 * @param string|null      $fallbackReason The accumulated Talk fallback reason when this
	 *                                         is reached as a fallback; null otherwise.
	 *
	 * @return DeliveryResult The notification delivery outcome.
	 *
	 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
	 */
	private function deliverNotification(ScheduleDelivery $delivery, ?string $fallbackReason = null): DeliveryResult {
		$fellBack = ($fallbackReason !== null);
		try {
			$this->notifyOwner(delivery: $delivery);
			$this->logWarning(warning: $fallbackReason, uuid: $delivery->uuid(), channel: 'notification');
			return new DeliveryResult(delivered: true, channel: 'notification', fellBack: $fellBack, warning: $fallbackReason);
		} catch (Throwable $e) {
			$warning = implode('; ', array_filter([$fallbackReason, 'notification failed: ' . $e->getMessage()]));
			$this->logWarning(warning: $warning, uuid: $delivery->uuid(), channel: 'notification');
			return new DeliveryResult(delivered: false, channel: 'notification', fellBack: $fellBack, warning: $warning);
		}//end try

	}//end deliverNotification()

	/**
	 * Notify the schedule owner that the run completed, linking to the schedule.
	 *
	 * @param ScheduleDelivery $delivery The delivery.
	 *
	 * @return void
	 */
	private function notifyOwner(ScheduleDelivery $delivery): void {
		$uuid = $delivery->uuid();
		$notification = $this->notificationManager->createNotification();
		$notification->setApp('hermiq')
			->setUser($delivery->owner())
			->setDateTime(new DateTime())
			->setObject('schedule', $uuid)
			->setSubject('run_complete', ['name' => (string)($delivery->schedule->getObject()['name'] ?? '')])
			->setMessage('run_summary', ['length' => strlen($delivery->output)])
			->setLink($this->buildScheduleLink(uuid: $uuid));
		$this->notificationManager->notify($notification);

	}//end notifyOwner()

	/**
	 * Deliver via email (delivery-channels): the redacted output is sent to the
	 * schedule owner's own Nextcloud account email, or an explicit `deliverTarget`
	 * recipient, via Nextcloud's own `OCP\Mail\IMailer` — never a bespoke SMTP
	 * client. NEVER throws: a missing recipient or a mailer failure is reported as
	 * a warning, never fails the run.
	 *
	 * @param ObjectEntity $schedule The schedule being delivered.
	 * @param string $output The agent output to email (redacted before send).
	 *
	 * @return DeliveryResult The email delivery outcome.
	 *
	 * @spec openspec/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-email-mvp
	 */
	private function deliverEmail(ObjectEntity $schedule, string $output): DeliveryResult {
		$uuid = (string)$schedule->getUuid();
		$data = $schedule->getObject();
		$recipient = trim((string)($data['deliverTarget'] ?? ''));

		if ($recipient === '') {
			$recipient = $this->resolveOwnerEmail(owner: (string)($schedule->getOwner() ?? ''));
		}

		if ($recipient === '' || $this->mailer->validateMailAddress($recipient) === false) {
			$warning = 'No email recipient could be resolved';
			$this->logWarning(warning: $warning, uuid: $uuid, channel: 'email');
			return new DeliveryResult(delivered: false, channel: 'email', fellBack: false, warning: $warning);
		}

		$scheduleName = (string)($data['name'] ?? 'Hermiq schedule');
		$redacted = $this->redactionService->redact(text: $output);

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$recipient]);
			$message->setSubject(sprintf('[Hermiq] %s', $scheduleName));
			$message->setPlainBody($redacted);

			$failed = $this->mailer->send($message);
			if ($failed !== []) {
				$warning = 'Email send reported failed recipients: ' . implode(', ', $failed);
				$this->logWarning(warning: $warning, uuid: $uuid, channel: 'email');
				return new DeliveryResult(delivered: false, channel: 'email', fellBack: false, warning: $warning);
			}

			$this->logWarning(warning: null, uuid: $uuid, channel: 'email');
			return new DeliveryResult(delivered: true, channel: 'email', fellBack: false, warning: null);
		} catch (Throwable $e) {
			$warning = 'Email send failed: ' . $e->getMessage();
			$this->logWarning(warning: $warning, uuid: $uuid, channel: 'email');
			return new DeliveryResult(delivered: false, channel: 'email', fellBack: false, warning: $warning);
		}//end try

	}//end deliverEmail()

	/**
	 * Resolve the schedule owner's own Nextcloud account email address.
	 *
	 * @param string $owner The schedule owner UID.
	 *
	 * @return string The owner's email address, or '' when unresolvable.
	 *
	 * @spec openspec/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-email-mvp
	 */
	private function resolveOwnerEmail(string $owner): string {
		if ($owner === '') {
			return '';
		}

		$user = $this->userManager->get($owner);
		if ($user === null) {
			return '';
		}

		return (string)($user->getEMailAddress() ?? '');
	}//end resolveOwnerEmail()

	/**
	 * Deliver via a signed outbound webhook (delivery-channels): POST a redacted,
	 * size-capped JSON envelope to `deliverTarget`, signed with
	 * `X-Hermiq-Signature: sha256=<hex>` over the exact bytes sent, with a bounded
	 * exponential-backoff retry. NEVER throws: a missing URL/secret or an
	 * exhausted retry budget is reported as a warning, never fails the run.
	 *
	 * @param ObjectEntity $schedule The schedule being delivered.
	 * @param string $output The agent output to POST (redacted before send).
	 *
	 * @return DeliveryResult The webhook delivery outcome.
	 *
	 * @spec openspec/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-a-signed-outbound-webhook-mvp
	 * @spec openspec/specs/talk-delivery/spec.md#requirement-webhook-delivery-retries-with-bounded-exponential-backoff-mvp
	 * @spec openspec/specs/talk-delivery/spec.md#requirement-webhook-payload-is-size-capped-before-it-is-signed-and-sent-mvp
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-hermiq-reports-what-only-it-can-observe-req-hermiq-conn-003
	 */
	private function deliverWebhook(ObjectEntity $schedule, string $output): DeliveryResult {
		$uuid = (string)$schedule->getUuid();
		$data = $schedule->getObject();
		$url = trim((string)($data['deliverTarget'] ?? ''));

		if ($url === '') {
			$warning = 'No destination URL is configured';
			$this->logWarning(warning: $warning, uuid: $uuid, channel: 'webhook');
			return new DeliveryResult(delivered: false, channel: 'webhook', fellBack: false, warning: $warning);
		}

		$secret = $this->scheduleWebhookSecretService->retrieveSecret(schedule: $schedule);
		if ($secret === null) {
			$warning = 'No signing secret is configured';
			$this->logWarning(warning: $warning, uuid: $uuid, channel: 'webhook');
			return new DeliveryResult(delivered: false, channel: 'webhook', fellBack: false, warning: $warning);
		}

		$redacted = $this->redactionService->redact(text: $output);
		$body = $this->capWebhookPayload(
			envelope: $this->buildWebhookEnvelope(schedule: $schedule, agentId: (string)($data['agentId'] ?? ''), output: $redacted)
		);

		$maxAttempts = $this->clampInt(value: ($data['deliverWebhookMaxAttempts'] ?? 3), min: 1, max: 5);
		$backoffBase = $this->clampInt(value: ($data['deliverWebhookBackoffBaseSeconds'] ?? 2), min: 1, max: 30);

		$lastError = '';
		for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
			if ($attempt > 1) {
				$this->sleep(seconds: $backoffBase * (2 ** ($attempt - 2)));
			}

			$lastError = $this->tryPostWebhook(url: $url, body: $body, secret: $secret);
			if ($lastError === '') {
				$this->logWarning(warning: null, uuid: $uuid, channel: 'webhook');
				$this->reportWebhookOutcome(url: $url, delivered: true, attempts: $attempt);
				return new DeliveryResult(delivered: true, channel: 'webhook', fellBack: false, warning: null);
			}
		}

		$warning = sprintf('Webhook delivery failed after %d attempt(s): %s', $maxAttempts, $lastError);
		$this->logWarning(warning: $warning, uuid: $uuid, channel: 'webhook');
		$this->reportWebhookOutcome(url: $url, delivered: false, attempts: $maxAttempts);
		return new DeliveryResult(delivered: false, channel: 'webhook', fellBack: false, warning: $warning);
	}//end deliverWebhook()

	/**
	 * Tell integriq what a webhook delivery met, at most once an hour per status.
	 *
	 * Webhook targets are per schedule, so `webhook-delivery` is one row for the
	 * family and shows the last outcome (adopt-connection-registry design D4).
	 * The message names the target host only: the full URL may carry a token.
	 * A missing target or secret is a gap in one schedule and sends nothing.
	 *
	 * @param string $url       The target URL the POST went to.
	 * @param bool   $delivered Whether a 2xx answer came back.
	 * @param int    $attempts  The attempts made.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-hermiq-reports-what-only-it-can-observe-req-hermiq-conn-003
	 */
	private function reportWebhookOutcome(string $url, bool $delivered, int $attempts): void {
		if ($this->connectionReporter === null) {
			return;
		}

		$host = parse_url($url, PHP_URL_HOST);
		if (is_string($host) === false || $host === '') {
			$host = 'its target';
		}

		if ($delivered === true) {
			$this->connectionReporter->reportThrottled(
				key: 'webhook-delivery',
				status: 'configured',
				message: 'The last webhook delivery reached ' . $host . '.'
			);
			return;
		}

		$this->connectionReporter->reportThrottled(
			key: 'webhook-delivery',
			status: 'error',
			message: sprintf('The last webhook delivery to %s failed after %d attempt(s).', $host, $attempts)
		);
	}//end reportWebhookOutcome()

	/**
	 * Attempt a single signed webhook POST.
	 *
	 * @param string $url The destination URL.
	 * @param string $body The exact, final (redacted, capped) JSON body to sign and send.
	 * @param string $secret The schedule's webhook signing secret.
	 *
	 * @return string '' on a successful (2xx) response, or a reason string on failure.
	 *
	 * @spec openspec/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-a-signed-outbound-webhook-mvp
	 */
	private function tryPostWebhook(string $url, string $body, string $secret): string {
		$signature = 'sha256=' . hash_hmac('sha256', $body, $secret);

		try {
			$response = $this->clientService->newClient()->post(
				$url,
				[
					'body' => $body,
					'headers' => [
						'Content-Type' => 'application/json',
						self::WEBHOOK_SIGNATURE_HEADER => $signature,
					],
					'timeout' => self::WEBHOOK_HTTP_TIMEOUT_SECONDS,
				]
			);

			$status = $response->getStatusCode();
			if ($status >= 200 && $status < 300) {
				return '';
			}

			return sprintf('webhook responded with status %d', $status);
		} catch (Throwable $e) {
			return $e->getMessage();
		}//end try

	}//end tryPostWebhook()

	/**
	 * Build the outbound webhook JSON envelope (delivery-channels design.md
	 * Decision 4). `deliver()` only invokes webhook delivery from the SUCCESS
	 * path of a run (`ScheduleService::runDue()` calls it after
	 * `runAgentAsOwner()` returns without throwing), so `status` is always `ok`
	 * here — a failed run never reaches this method.
	 *
	 * @param ObjectEntity $schedule The schedule being delivered.
	 * @param string $agentId The bound agent's UUID.
	 * @param string $output The already-redacted output.
	 *
	 * @return array<string, mixed> The envelope (pre-size-cap).
	 *
	 * @spec openspec/specs/talk-delivery/spec.md#requirement-webhook-payload-is-size-capped-before-it-is-signed-and-sent-mvp
	 */
	private function buildWebhookEnvelope(ObjectEntity $schedule, string $agentId, string $output): array {
		return [
			'scheduleId' => (string)$schedule->getUuid(),
			'agentId' => $agentId,
			'status' => 'ok',
			'deliveredAt' => (new DateTime())->format('c'),
			'output' => $output,
		];

	}//end buildWebhookEnvelope()

	/**
	 * Encode the envelope, truncating ONLY the `output` field (never the
	 * identifying metadata) so the final JSON never exceeds
	 * `WEBHOOK_MAX_PAYLOAD_BYTES` (delivery-channels design.md Decision 4).
	 * Iterative, not a single-shot estimate: JSON-escaping can expand a
	 * substring's encoded length (quotes/backslashes), so each pass re-measures
	 * the ACTUAL encoded size until it fits — bounded so it can never loop
	 * unboundedly.
	 *
	 * @param array<string, mixed> $envelope The pre-cap envelope.
	 *
	 * @return string The final, size-capped JSON body — the exact bytes to sign and send.
	 *
	 * @spec openspec/specs/talk-delivery/spec.md#requirement-webhook-payload-is-size-capped-before-it-is-signed-and-sent-mvp
	 */
	private function capWebhookPayload(array $envelope): string {
		$encoded = (string)json_encode($envelope);

		for ($i = 0; $i < 20; $i++) {
			$excess = strlen($encoded) - self::WEBHOOK_MAX_PAYLOAD_BYTES;
			if ($excess <= 0) {
				break;
			}

			$output = (string)$envelope['output'];
			$cut = max($excess + strlen(self::WEBHOOK_TRUNCATION_MARKER), 1);
			$newLen = max(strlen($output) - $cut, 0);

			$envelope['output'] = substr($output, 0, $newLen) . self::WEBHOOK_TRUNCATION_MARKER;
			$encoded = (string)json_encode($envelope);
		}//end for

		return $encoded;
	}//end capWebhookPayload()

	/**
	 * Clamp an integer schedule field to its documented schema bounds,
	 * tolerating a missing/non-numeric stored value by falling back within range.
	 *
	 * @param mixed $value The raw stored value.
	 * @param int $min The minimum allowed value.
	 * @param int $max The maximum allowed value.
	 *
	 * @return int The clamped value.
	 *
	 * @spec openspec/specs/talk-delivery/spec.md#requirement-webhook-delivery-retries-with-bounded-exponential-backoff-mvp
	 */
	private function clampInt(mixed $value, int $min, int $max): int {
		$int = (int)$value;
		return max($min, min($max, $int));
	}//end clampInt()

	/**
	 * Pause the current tick for the given number of seconds before the next
	 * webhook retry attempt. A dedicated, overridable seam (rather than a bare
	 * `sleep()` call inline) so tests can stub it out instead of a unit run
	 * actually blocking for real backoff delays.
	 *
	 * @param int $seconds Seconds to sleep (a clamped backoff delay; never negative).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/talk-delivery/spec.md#requirement-webhook-delivery-retries-with-bounded-exponential-backoff-mvp
	 */
	protected function sleep(int $seconds): void {
		if ($seconds > 0) {
			sleep($seconds);
		}

	}//end sleep()

	/**
	 * Lazily resolve the spreed room manager from the server container.
	 *
	 * Called only after the isTalkAvailable() guard, so the concrete class is present.
	 *
	 * @return \OCA\Talk\Manager The spreed room manager.
	 *
	 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
	 */
	private function talkManager(): object {
		return $this->container->get(self::TALK_MANAGER);
	}//end talkManager()

	/**
	 * Lazily resolve the spreed participant service from the server container.
	 *
	 * @return \OCA\Talk\Service\ParticipantService The spreed participant service.
	 *
	 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
	 */
	private function talkParticipantService(): object {
		return $this->container->get(self::TALK_PARTICIPANT_SERVICE);
	}//end talkParticipantService()

	/**
	 * Lazily resolve the spreed Note-to-self service from the server container.
	 *
	 * @return \OCA\Talk\Service\NoteToSelfService The spreed Note-to-self service.
	 *
	 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
	 */
	private function talkNoteToSelfService(): object {
		return $this->container->get(self::TALK_NOTE_TO_SELF_SERVICE);
	}//end talkNoteToSelfService()

	/**
	 * Lazily resolve the spreed chat manager from the server container.
	 *
	 * @return \OCA\Talk\Chat\ChatManager The spreed chat manager.
	 *
	 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
	 */
	private function talkChatManager(): object {
		return $this->container->get(self::TALK_CHAT_MANAGER);
	}//end talkChatManager()

	/**
	 * Lazily resolve the spreed room service from the server container.
	 *
	 * @return \OCA\Talk\Service\RoomService The spreed room service.
	 *
	 * @spec exclude Lazy spreed resolver; mirrors the other Talk resolvers.
	 */
	private function talkRoomService(): object {
		return $this->container->get(self::TALK_ROOM_SERVICE);
	}//end talkRoomService()

	/**
	 * Resolve the owner's default delivery-room token, creating a "Hermiq" room on
	 * first use.
	 *
	 * Reads the owner's `delivertarget` preference (set from Personal settings);
	 * when unset, lazily creates a group Talk room named "Hermiq" owned by the
	 * user, persists its token as that preference, and returns it. Any failure
	 * (Talk unavailable, unknown user, creation error) returns '' so the caller
	 * cleanly falls through to Note-to-self.
	 *
	 * @param string $owner The schedule owner UID.
	 *
	 * @return string The room token, or '' when none could be resolved/created.
	 *
	 * @spec exclude Per-user default-room resolution; no behavioural spec yet.
	 */
	private function resolveDefaultRoom(string $owner): string {
		if ($owner === '') {
			return '';
		}

		$stored = (string)$this->config->getUserValue($owner, 'hermiq', self::DELIVER_TARGET_PREF, '');
		if ($stored !== '') {
			return $stored;
		}

		try {
			$ownerUser = $this->userManager->get($owner);
			if ($ownerUser === null) {
				return '';
			}

			$room = $this->talkRoomService()->createConversation(
				self::ROOM_TYPE_GROUP,
				self::DEFAULT_ROOM_NAME,
				$ownerUser,
			);
			$token = (string)$room->getToken();
			if ($token === '') {
				return '';
			}

			$this->config->setUserValue($owner, 'hermiq', self::DELIVER_TARGET_PREF, $token);
			return $token;
		} catch (Throwable $e) {
			$this->logger->debug('[Hermiq] default Talk room creation failed: ' . $e->getMessage());
			return '';
		}//end try

	}//end resolveDefaultRoom()

	/**
	 * Whether Talk is usable right now (core probe + spreed classes present).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
	 */
	private function isTalkAvailable(): bool {
		return $this->talkBroker->hasBackend() === true && class_exists(self::TALK_MANAGER) === true;
	}//end isTalkAvailable()

	/**
	 * Build an absolute deep link to the run list, scoped to one schedule.
	 *
	 * 🔴 This is the link EVERY Talk delivery and every failure alert carries, and it
	 * pointed at `/apps/hermiq/schedules/<uuid>`, which the manifest does not declare.
	 * The SPA catch-all answers 200 with the app shell, the router matches nothing, and
	 * the reader lands on the dashboard. So the one link on the app's headline path —
	 * scheduled run, delivered to Talk, click through to see it — went nowhere, and
	 * looked like it worked.
	 *
	 * @param string $uuid The schedule UUID.
	 *
	 * @return string The absolute URL.
	 */
	private function buildScheduleLink(string $uuid): string {
		return $this->urlGenerator->getAbsoluteURL(
			'/index.php/apps/hermiq/runs?schedule=' . rawurlencode($uuid)
		);
	}//end buildScheduleLink()

	/**
	 * Build an absolute deep link to the approvals inbox for a notification.
	 *
	 * @param string $uuid The approval UUID.
	 *
	 * @return string The absolute URL.
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#2-dispatcher-approval-gate-scheduleservice
	 */
	private function buildApprovalLink(string $uuid): string {
		return $this->urlGenerator->getAbsoluteURL('/index.php/apps/hermiq/approvals/' . $uuid);
	}//end buildApprovalLink()

	/**
	 * Build an absolute deep link to a skill's SkillDetail page for a notification
	 * (skill-self-improvement: behind-badge + rollback-suggestion notifications).
	 *
	 * @param string $uuid The skill UUID.
	 *
	 * @return string The absolute URL.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-an-accepted-version-behind-the-published-copy-raises-an-explicit-republish-signal
	 */
	private function buildSkillLink(string $uuid): string {
		return $this->urlGenerator->getAbsoluteURL('/index.php/apps/hermiq/skills/' . $uuid);
	}//end buildSkillLink()

	/**
	 * Build an absolute deep link to the tenant-ops budgets surface for a notification.
	 *
	 * @param string $uuid The budget UUID.
	 *
	 * @return string The absolute URL.
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-3-wire-the-budget-gate-into-the-dispatch-path-soft-threshold-delivery
	 */
	private function buildBudgetLink(string $uuid): string {
		return $this->urlGenerator->getAbsoluteURL('/index.php/apps/hermiq/tenant-ops?budget=' . $uuid);
	}//end buildBudgetLink()

	/**
	 * Emit a PSR-3 warning for a degraded/failed delivery (no-op when clean).
	 *
	 * @param string|null $warning The warning message, or null on clean success.
	 * @param string $uuid The schedule UUID.
	 * @param string $channel The channel involved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#3-wire-into-the-dispatcher
	 */
	private function logWarning(?string $warning, string $uuid, string $channel): void {
		if ($warning === null || $warning === '') {
			return;
		}

		$this->logger->warning(
			sprintf('Hermiq schedule %s delivery via %s degraded: %s', $uuid, $channel, $warning)
		);

	}//end logWarning()
}//end class
