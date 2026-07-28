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
 * @spec openspec/changes/talk-delivery/tasks.md#1-deliveryservice-core
 * @spec openspec/changes/archive/2026-07-13-delivery-channels/design.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTime;
use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
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
 * @spec openspec/changes/talk-delivery/tasks.md#1-deliveryservice-core
 * @spec openspec/changes/archive/2026-07-13-delivery-channels/design.md
 */
class DeliveryService
{

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

    /**
     * Constructor.
     *
     * All constructor dependencies are ALWAYS-present Nextcloud services. The optional
     * spreed classes are resolved lazily through $container inside the Talk guard, so
     * this service (and Hermiq) construct cleanly on an instance without Talk.
     *
     * @param INotificationManager         $notificationManager          Raises Nextcloud notifications (baseline + fallback).
     * @param IBroker                      $talkBroker                   Core Talk availability probe (hasBackend()).
     * @param IURLGenerator                $urlGenerator                 Builds the schedule/run deep link for notifications.
     * @param ContainerInterface           $container                    Server container for lazy spreed resolution.
     * @param IConfig                      $config                       Reads/writes the owner's default-room preference.
     * @param IUserManager                 $userManager                  Resolves the owner IUser for room creation / email fallback.
     * @param IMailer                      $mailer                       Sends `deliver=email` output (delivery-channels) — never a bespoke SMTP
     *                                                                   client.
     * @param IClientService               $clientService                Sends `deliver=webhook` POSTs (delivery-channels) — never a bespoke HTTP
     *                                                                   client.
     * @param RedactionService             $redactionService             Redacts output before it crosses the instance boundary (email/webhook only).
     * @param ScheduleWebhookSecretService $scheduleWebhookSecretService Retrieves a schedule's outbound webhook signing secret (delivery-channels).
     * @param LoggerInterface              $logger                       PSR-3 logger for delivery warnings.
     * @param TalkRoomBinding              $talkRoomBinding              Binds the run's conversation to the Talk room it
     *                                                                   was delivered into, so the report can be replied to
     *                                                                   (talk-chat-bridge).
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
     *   distinct always-present Nextcloud/Hermiq collaborator, not a logic-bearing list.
     */
    public function __construct(
        private readonly INotificationManager $notificationManager,
        private readonly IBroker $talkBroker,
        private readonly IURLGenerator $urlGenerator,
        private readonly ContainerInterface $container,
        private readonly IConfig $config,
        private readonly IUserManager $userManager,
        private readonly IMailer $mailer,
        private readonly IClientService $clientService,
        private readonly RedactionService $redactionService,
        private readonly ScheduleWebhookSecretService $scheduleWebhookSecretService,
        private readonly LoggerInterface $logger,
        private readonly TalkRoomBinding $talkRoomBinding,
    ) {
    }//end __construct()

    /**
     * The conversation this delivery's run produced, when the caller knows it.
     *
     * Set by `deliver()` for the duration of one delivery so `deliverTalk()`
     * can bind that conversation to the room it posts into. Deliberately NOT a
     * constructor dependency: it is per-delivery state, not configuration.
     *
     * @var string|null
     */
    private ?string $boundConversationUuid = null;

    /**
     * Deliver a run's output for one schedule.
     *
     * Never throws for a delivery problem: the outcome (including any warning to
     * persist as lastDeliveryError) is returned as a DeliveryResult.
     *
     * @param string       $channel          Delivery channel: talk|notification|email|webhook|none.
     * @param string       $output           The agent output to deliver.
     * @param ObjectEntity $schedule         The schedule the output belongs to.
     * @param string|null  $conversationUuid The conversation this run produced. When supplied AND
     *                                       the output reaches a Talk room, that conversation is
     *                                       bound to the room so a reply there continues this
     *                                       session (talk-chat-bridge). Null leaves the binding a
     *                                       no-op — every pre-existing caller is unchanged.
     *
     * @return DeliveryResult The delivery outcome.
     *
     * @spec openspec/changes/talk-delivery/tasks.md#1-deliveryservice-core
     * @spec openspec/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-email-mvp
     * @spec openspec/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-a-signed-outbound-webhook-mvp
     */
    public function deliver(string $channel, string $output, ObjectEntity $schedule, ?string $conversationUuid=null): DeliveryResult
    {
        // Per-delivery state: which conversation this run produced, so a Talk
        // room delivery can bind it and become repliable. Null for every caller
        // that does not know (and for every non-Talk channel), which leaves the
        // binding step a no-op.
        $this->boundConversationUuid = $conversationUuid;

        // None / empty channel, or silent/empty output → deliberate no-op.
        if ($channel === '' || $channel === 'none' || trim($output) === '') {
            return new DeliveryResult(delivered: false, channel: 'none', fellBack: false, warning: null);
        }

        if ($channel === 'notification') {
            return $this->deliverNotification(schedule: $schedule, output: $output, fellBack: false);
        }

        if ($channel === 'talk') {
            return $this->deliverTalk(schedule: $schedule, output: $output);
        }

        if ($channel === 'email') {
            return $this->deliverEmail(schedule: $schedule, output: $output);
        }

        if ($channel === 'webhook') {
            return $this->deliverWebhook(schedule: $schedule, output: $output);
        }

        // Unknown channel: record a warning but never fail the run.
        return new DeliveryResult(
            delivered: false,
            channel: $channel,
            fellBack: false,
            warning: sprintf("Unknown delivery channel '%s'", $channel)
        );

    }//end deliver()

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
     * @param ObjectEntity      $schedule     The gated schedule.
     * @param ObjectEntity      $approval     The pending approval to link to.
     * @param array<int,string> $reviewerUids The resolved reviewer user ids.
     *
     * @return DeliveryResult The notification outcome (warning ⇒ degraded delivery).
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#2-dispatcher-approval-gate-scheduleservice
     */
    public function deliverApprovalRequest(ObjectEntity $schedule, ObjectEntity $approval, array $reviewerUids): DeliveryResult
    {
        $scheduleName = (string) ($schedule->getObject()['name'] ?? '');

        return $this->notifyApprovalReviewers(
            approvalUuid: (string) $approval->getUuid(),
            displayName: $scheduleName,
            reviewerUids: $reviewerUids,
            messageParams: ['scheduleId' => (string) $schedule->getUuid()]
        );

    }//end deliverApprovalRequest()

    /**
     * Notify a flow-triggered agent run's resolved reviewer(s) that an approval is
     * pending (Art. 14) — the `sourceType: "flow"` counterpart to
     * `deliverApprovalRequest()`. There is no Schedule ObjectEntity to read a display
     * name from; the approval's own `flowContext.flowName` (or agentId as a fallback)
     * is used instead. NEVER throws for a delivery problem.
     *
     * @param ObjectEntity      $approval     The pending approval to link to.
     * @param array<int,string> $reviewerUids The resolved reviewer user ids.
     *
     * @return DeliveryResult The notification outcome (warning ⇒ degraded delivery).
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#3-approvalservice-generalisation-sourcetype-flow
     */
    public function deliverApprovalRequestForFlowRun(ObjectEntity $approval, array $reviewerUids): DeliveryResult
    {
        $data        = $approval->getObject();
        $flowContext = $data['flowContext'] ?? [];
        if (is_array($flowContext) === false) {
            $flowContext = [];
        }

        $displayName = (string) ($flowContext['flowName'] ?? ($data['agentId'] ?? ''));

        return $this->notifyApprovalReviewers(
            approvalUuid: (string) $approval->getUuid(),
            displayName: $displayName,
            reviewerUids: $reviewerUids,
            messageParams: ['correlationId' => (string) ($flowContext['correlationId'] ?? '')]
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
     * @param ObjectEntity      $approval     The pending approval to link to.
     * @param array<int,string> $reviewerUids The resolved reviewer user ids.
     *
     * @return DeliveryResult The notification outcome (warning ⇒ degraded delivery).
     *
     * @spec openspec/changes/archive/2026-07-12-agent-webhook-trigger/tasks.md#task-6-deliveryservice-webhook-approval-notification-shared-reviewer-notify-helper
     */
    public function deliverApprovalRequestForWebhookRun(ObjectEntity $approval, array $reviewerUids): DeliveryResult
    {
        $data           = $approval->getObject();
        $webhookContext = $data['webhookContext'] ?? [];
        if (is_array($webhookContext) === false) {
            $webhookContext = [];
        }

        $displayName = (string) ($data['agentId'] ?? '');

        return $this->notifyApprovalReviewers(
            approvalUuid: (string) $approval->getUuid(),
            displayName: $displayName,
            reviewerUids: $reviewerUids,
            messageParams: ['correlationId' => (string) ($webhookContext['correlationId'] ?? '')]
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
     * @param ObjectEntity      $approval     The pending approval to link to.
     * @param array<int,string> $reviewerUids The resolved reviewer user ids.
     * @param string            $skillName    The skill's display name.
     *
     * @return DeliveryResult The notification outcome (warning ⇒ degraded delivery).
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    public function deliverApprovalRequestForSkillDraft(
        ObjectEntity $approval,
        array $reviewerUids,
        string $skillName
    ): DeliveryResult {
        $data = $approval->getObject();

        return $this->notifyApprovalReviewers(
            approvalUuid: (string) $approval->getUuid(),
            displayName: $skillName,
            reviewerUids: $reviewerUids,
            messageParams: ['draftId' => (string) ($data['draftId'] ?? '')]
        );

    }//end deliverApprovalRequestForSkillDraft()

    /**
     * Notify a skill's publisher that its published GitHub copy is now BEHIND the
     * locally accepted version (skill-self-improvement republish signal). Raised once
     * per newly-behind transition by the draft apply step — never on every pass, and
     * never accompanied by any automatic GitHub call. NEVER throws.
     *
     * @param string $skillUuid    The skill UUID (deep link target).
     * @param string $skillName    The skill's display name.
     * @param string $recipientUid The publisher to notify.
     *
     * @return DeliveryResult The notification outcome.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-an-accepted-version-behind-the-published-copy-raises-an-explicit-republish-signal
     */
    public function deliverSkillPublishedBehind(string $skillUuid, string $skillName, string $recipientUid): DeliveryResult
    {
        if ($recipientUid === '') {
            return new DeliveryResult(delivered: false, channel: 'none', fellBack: false, warning: 'No publisher to notify.');
        }

        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp('hermiq')
                ->setUser($recipientUid)
                ->setDateTime(new DateTime())
                ->setObject('skill', $skillUuid)
                ->setSubject('skill_published_behind', ['name' => $skillName])
                ->setMessage('skill_published_behind_summary', [])
                ->setLink($this->buildSkillLink(uuid: $skillUuid));
            $this->notificationManager->notify($notification);

            return new DeliveryResult(delivered: true, channel: 'notification', fellBack: false, warning: null);
        } catch (Throwable $e) {
            return new DeliveryResult(
                delivered: false,
                channel: 'none',
                fellBack: false,
                warning: sprintf('notify %s failed: %s', $recipientUid, $e->getMessage())
            );
        }

    }//end deliverSkillPublishedBehind()

    /**
     * Notify the accepting reviewer that the NEXT eval run after their acceptance
     * regressed — the advisory "roll back to previous version?" suggestion
     * (skill-self-improvement regression watch). Advisory only: no rollback happens
     * without an explicit request on SkillDetail. NEVER throws.
     *
     * @param string $skillUuid    The skill UUID (deep link target).
     * @param string $skillName    The skill's display name.
     * @param string $recipientUid The accepting reviewer to notify.
     *
     * @return DeliveryResult The notification outcome.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-post-acceptance-regression-surfaces-a-rollback-suggestion
     */
    public function deliverSkillRollbackSuggestion(string $skillUuid, string $skillName, string $recipientUid): DeliveryResult
    {
        if ($recipientUid === '') {
            return new DeliveryResult(delivered: false, channel: 'none', fellBack: false, warning: 'No reviewer to notify.');
        }

        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp('hermiq')
                ->setUser($recipientUid)
                ->setDateTime(new DateTime())
                ->setObject('skill', $skillUuid)
                ->setSubject('skill_rollback_suggested', ['name' => $skillName])
                ->setMessage('skill_rollback_suggested_summary', [])
                ->setLink($this->buildSkillLink(uuid: $skillUuid));
            $this->notificationManager->notify($notification);

            return new DeliveryResult(delivered: true, channel: 'notification', fellBack: false, warning: null);
        } catch (Throwable $e) {
            return new DeliveryResult(
                delivered: false,
                channel: 'none',
                fellBack: false,
                warning: sprintf('notify %s failed: %s', $recipientUid, $e->getMessage())
            );
        }

    }//end deliverSkillRollbackSuggestion()

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
     * @param string              $approvalUuid  The pending approval's UUID.
     * @param string              $displayName   The human label for the gated run
     *                                           (schedule name / flow name /
     *                                           agent id).
     * @param array<int,string>   $reviewerUids  The resolved reviewer user ids.
     * @param array<string,mixed> $messageParams The `approval_summary` notification's
     *                                           message parameters (scheduleId /
     *                                           correlationId).
     *
     * @return DeliveryResult The notification outcome (warning ⇒ degraded delivery).
     *
     * @spec openspec/changes/archive/2026-07-12-agent-webhook-trigger/tasks.md#task-6-deliveryservice-webhook-approval-notification-shared-reviewer-notify-helper
     */
    private function notifyApprovalReviewers(
        string $approvalUuid,
        string $displayName,
        array $reviewerUids,
        array $messageParams
    ): DeliveryResult {
        $talkOk    = $this->isTalkAvailable();
        $warnings  = [];
        $delivered = false;

        foreach ($reviewerUids as $uid) {
            $uid = (string) $uid;
            if ($uid === '') {
                continue;
            }

            try {
                $notification = $this->notificationManager->createNotification();
                $notification->setApp('hermiq')
                    ->setUser($uid)
                    ->setDateTime(new DateTime())
                    ->setObject('approval', $approvalUuid)
                    ->setSubject('approval_requested', ['name' => $displayName])
                    ->setMessage('approval_summary', $messageParams)
                    ->setLink($this->buildApprovalLink(uuid: $approvalUuid));
                $this->notificationManager->notify($notification);
                $delivered = true;
            } catch (Throwable $e) {
                $warnings[] = sprintf("notify %s failed: %s", $uid, $e->getMessage());
            }

            // Best-effort Talk Note-to-self — a bonus channel, never required.
            if ($talkOk === true) {
                $this->tryPostToNoteToSelf(
                    owner: $uid,
                    output: sprintf('Approval needed for “%s”. Review it in Hermiq.', $displayName)
                );
            }
        }//end foreach

        $warning = null;
        if ($warnings !== []) {
            $warning = implode('; ', $warnings);
        }

        $this->logWarning(warning: $warning, uuid: $approvalUuid, channel: 'approval');
        return new DeliveryResult(delivered: $delivered, channel: 'notification', fellBack: false, warning: $warning);

    }//end notifyApprovalReviewers()

    /**
     * Notify a tool-invocation approval's resolved reviewer(s) that an
     * un-granted destructive tool call is pending (Art. 14) — the
     * `sourceType: "tool"` counterpart to `deliverApprovalRequestForFlowRun()`
     * (agent-tool-governance-and-disclosure). The approval's own `toolId` (or
     * `agentId` as a fallback) is used as the display name. NEVER throws for a
     * delivery problem.
     *
     * @param ObjectEntity      $approval     The pending approval to link to.
     * @param array<int,string> $reviewerUids The resolved reviewer user ids.
     *
     * @return DeliveryResult The notification outcome (warning ⇒ degraded delivery).
     *
     * @spec openspec/specs/human-approval-gate/spec.md#scenario-an-agent-attempts-an-un-granted-destructive-tool-call
     */
    public function deliverApprovalRequestForToolInvocation(ObjectEntity $approval, array $reviewerUids): DeliveryResult
    {
        $approvalUuid = (string) $approval->getUuid();
        $data         = $approval->getObject();
        $toolId       = (string) ($data['toolId'] ?? ($data['agentId'] ?? ''));

        $talkOk    = $this->isTalkAvailable();
        $warnings  = [];
        $delivered = false;

        foreach ($reviewerUids as $uid) {
            $uid = (string) $uid;
            if ($uid === '') {
                continue;
            }

            try {
                $notification = $this->notificationManager->createNotification();
                $notification->setApp('hermiq')
                    ->setUser($uid)
                    ->setDateTime(new DateTime())
                    ->setObject('approval', $approvalUuid)
                    ->setSubject('approval_requested', ['name' => $toolId])
                    ->setMessage('approval_summary', ['toolId' => $toolId])
                    ->setLink($this->buildApprovalLink(uuid: $approvalUuid));
                $this->notificationManager->notify($notification);
                $delivered = true;
            } catch (Throwable $e) {
                $warnings[] = sprintf("notify %s failed: %s", $uid, $e->getMessage());
            }

            // Best-effort Talk Note-to-self — a bonus channel, never required.
            if ($talkOk === true) {
                $this->tryPostToNoteToSelf(
                    owner: $uid,
                    output: sprintf('Approval needed to run “%s”. Review it in Hermiq.', $toolId)
                );
            }
        }//end foreach

        $warning = null;
        if ($warnings !== []) {
            $warning = implode('; ', $warnings);
        }

        $this->logWarning(warning: $warning, uuid: $approvalUuid, channel: 'approval');
        return new DeliveryResult(delivered: $delivered, channel: 'notification', fellBack: false, warning: $warning);

    }//end deliverApprovalRequestForToolInvocation()

    /**
     * Notify a budget's resolved recipient(s) that its soft threshold was crossed
     * (cost-guardrails). Fires at most once per period — `BudgetService` gates the
     * call so this method is only ever invoked when a warning is actually due. NEVER
     * throws for a delivery problem: any failure is caught and reported through a
     * DeliveryResult so the dispatch tick/run is never failed by a notification.
     *
     * @param ObjectEntity      $budget        The budget whose soft threshold was crossed.
     * @param array<int,string> $recipientUids The resolved recipient user ids (the
     *                                         organisation owner).
     *
     * @return DeliveryResult The notification outcome (warning ⇒ degraded delivery).
     *
     * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-3-wire-the-budget-gate-into-the-dispatch-path-soft-threshold-delivery
     */
    public function deliverBudgetWarning(ObjectEntity $budget, array $recipientUids): DeliveryResult
    {
        $budgetUuid = (string) $budget->getUuid();
        $data       = $budget->getObject();
        $scope      = (string) ($data['scope'] ?? 'organisation');
        $label      = (string) ($budget->getOrganisation() ?? '');
        if ($scope === 'agent') {
            $label = (string) ($data['agentId'] ?? '');
        }

        $talkOk    = $this->isTalkAvailable();
        $warnings  = [];
        $delivered = false;

        foreach ($recipientUids as $uid) {
            $uid = (string) $uid;
            if ($uid === '') {
                continue;
            }

            try {
                $notification = $this->notificationManager->createNotification();
                $notification->setApp('hermiq')
                    ->setUser($uid)
                    ->setDateTime(new DateTime())
                    ->setObject('budget', $budgetUuid)
                    ->setSubject('budget_soft_threshold', ['scope' => $scope, 'label' => $label])
                    ->setMessage('budget_soft_threshold_summary', ['budgetId' => $budgetUuid])
                    ->setLink($this->buildBudgetLink(uuid: $budgetUuid));
                $this->notificationManager->notify($notification);
                $delivered = true;
            } catch (Throwable $e) {
                $warnings[] = sprintf("notify %s failed: %s", $uid, $e->getMessage());
            }

            // Best-effort Talk Note-to-self — a bonus channel, never required.
            if ($talkOk === true) {
                $this->tryPostToNoteToSelf(
                    owner: $uid,
                    output: sprintf('Budget "%s" crossed its soft threshold. Review it in Hermiq.', $label)
                );
            }
        }//end foreach

        $warning = null;
        if ($warnings !== []) {
            $warning = implode('; ', $warnings);
        }

        $this->logWarning(warning: $warning, uuid: $budgetUuid, channel: 'budget');
        return new DeliveryResult(delivered: $delivered, channel: 'notification', fellBack: false, warning: $warning);

    }//end deliverBudgetWarning()

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
     * @param string       $reason   The failure reason (the last agent-turn error).
     *
     * @return DeliveryResult The notification outcome (warning ⇒ degraded delivery).
     *
     * @spec openspec/specs/talk-delivery/spec.md#requirement-deliver-a-failure-alert-to-the-schedule-owner-mvp
     */
    public function deliverFailureAlert(ObjectEntity $schedule, string $reason): DeliveryResult
    {
        $owner = (string) ($schedule->getOwner() ?? '');
        if ($owner === '') {
            return new DeliveryResult(delivered: false, channel: 'notification', fellBack: false, warning: 'Schedule has no owner to notify');
        }

        $scheduleUuid = (string) $schedule->getUuid();
        $scheduleName = (string) ($schedule->getObject()['name'] ?? '');
        $talkOk       = $this->isTalkAvailable();
        $delivered    = false;
        $warning      = null;

        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp('hermiq')
                ->setUser($owner)
                ->setDateTime(new DateTime())
                ->setObject('schedule', $scheduleUuid)
                ->setSubject('run_dead_letter', ['name' => $scheduleName])
                ->setMessage('run_dead_letter_summary', ['reason' => $reason])
                ->setLink($this->buildScheduleLink(uuid: $scheduleUuid));
            $this->notificationManager->notify($notification);
            $delivered = true;
        } catch (Throwable $e) {
            $warning = sprintf('notify %s failed: %s', $owner, $e->getMessage());
        }

        // Best-effort Talk Note-to-self — a bonus channel, never required.
        if ($talkOk === true) {
            $this->tryPostToNoteToSelf(
                owner: $owner,
                output: sprintf('Schedule “%s” failed permanently after exhausting its retries: %s', $scheduleName, $reason)
            );
        }

        $this->logWarning(warning: $warning, uuid: $scheduleUuid, channel: 'dead_letter');
        return new DeliveryResult(delivered: $delivered, channel: 'notification', fellBack: false, warning: $warning);

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
    public function deliverCircuitBreakerAlert(ObjectEntity $schedule): DeliveryResult
    {
        $owner = (string) ($schedule->getOwner() ?? '');
        if ($owner === '') {
            return new DeliveryResult(delivered: false, channel: 'notification', fellBack: false, warning: 'Schedule has no owner to notify');
        }

        $scheduleUuid = (string) $schedule->getUuid();
        $scheduleName = (string) ($schedule->getObject()['name'] ?? '');
        $talkOk       = $this->isTalkAvailable();
        $delivered    = false;
        $warning      = null;

        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp('hermiq')
                ->setUser($owner)
                ->setDateTime(new DateTime())
                ->setObject('schedule', $scheduleUuid)
                ->setSubject('schedule_paused_circuit_breaker', ['name' => $scheduleName])
                ->setMessage('schedule_paused_circuit_breaker_summary', ['scheduleId' => $scheduleUuid])
                ->setLink($this->buildScheduleLink(uuid: $scheduleUuid));
            $this->notificationManager->notify($notification);
            $delivered = true;
        } catch (Throwable $e) {
            $warning = sprintf('notify %s failed: %s', $owner, $e->getMessage());
        }

        // Best-effort Talk Note-to-self — a bonus channel, never required.
        if ($talkOk === true) {
            $this->tryPostToNoteToSelf(
                owner: $owner,
                output: sprintf('Schedule “%s” was automatically paused after repeated failures. Review it in Hermiq.', $scheduleName)
            );
        }

        $this->logWarning(warning: $warning, uuid: $scheduleUuid, channel: 'circuit_breaker');
        return new DeliveryResult(delivered: $delivered, channel: 'notification', fellBack: false, warning: $warning);

    }//end deliverCircuitBreakerAlert()

    /**
     * Deliver via the Talk fallback chain: target room → Note-to-self → notification.
     *
     * Each fall-through accumulates a warning; the returned DeliveryResult carries the
     * combined warning (to be persisted as lastDeliveryError) and the channel used.
     *
     * @param ObjectEntity $schedule The schedule being delivered.
     * @param string       $output   The agent output to post.
     *
     * @return DeliveryResult The Talk delivery outcome.
     *
     * @spec openspec/changes/talk-delivery/tasks.md#1-deliveryservice-core
     * @spec openspec/changes/talk-delivery/tasks.md#1-deliveryservice-core
     */
    private function deliverTalk(ObjectEntity $schedule, string $output): DeliveryResult
    {
        $owner   = (string) ($schedule->getOwner() ?? '');
        $data    = $schedule->getObject();
        $target  = trim((string) ($data['deliverTarget'] ?? ''));
        $reasons = [];

        if ($this->isTalkAvailable() === false) {
            return $this->deliverNotification(
                schedule: $schedule,
                output: $output,
                fellBack: true,
                reason: 'Talk (spreed) is not available'
            );
        }

        // When the schedule names no room, fall back to the owner's default
        // delivery room (Personal settings) — lazily creating a "Hermiq" room the
        // first time so a user with nothing configured still gets a real room
        // rather than only Note-to-self. Any failure leaves $target empty and the
        // Note-to-self fallback below still applies.
        if ($target === '') {
            $target = $this->resolveDefaultRoom(owner: $owner);
        }

        // 1) Targeted room (membership-checked) when deliverTarget is set.
        if ($target !== '') {
            $posted = $this->tryPostToTargetRoom(token: $target, owner: $owner, output: $output);
            if ($posted === null) {
                // The report reached a room, so bind the conversation this run
                // produced to that room — a reply there then continues THIS
                // session instead of dead-ending (talk-chat-bridge). Strictly
                // best-effort: bindByUuid() swallows its own failures, so a
                // binding problem can never fail a delivery or a run.
                if ($this->boundConversationUuid !== null) {
                    $this->talkRoomBinding->bindByUuid(
                        conversationUuid: $this->boundConversationUuid,
                        roomToken: $target
                    );
                }

                return new DeliveryResult(delivered: true, channel: 'talk', fellBack: false, warning: null);
            }

            $reasons[] = $posted;
        }

        // 2) Owner's Note-to-self conversation.
        $noteReason = $this->tryPostToNoteToSelf(owner: $owner, output: $output);
        if ($noteReason === null) {
            $warning = null;
            if ($reasons !== []) {
                $warning = implode('; ', $reasons).' — delivered to Note-to-self';
            }

            $this->logWarning(warning: $warning, uuid: (string) $schedule->getUuid(), channel: 'talk');
            return new DeliveryResult(delivered: true, channel: 'talk', fellBack: ($reasons !== []), warning: $warning);
        }

        $reasons[] = $noteReason;

        // 3) Final fallback: a Nextcloud notification.
        return $this->deliverNotification(
            schedule: $schedule,
            output: $output,
            fellBack: true,
            reason: implode('; ', $reasons)
        );

    }//end deliverTalk()

    /**
     * Attempt to post to a specific Talk room the owner must be a member of.
     *
     * Membership is enforced by resolving the room owner-scoped via
     * Manager::getRoomForUserByToken(token, owner), which throws when the owner has no
     * access — Hermiq never posts to a room the owner is not in.
     *
     * @param string $token  The deliverTarget room token.
     * @param string $owner  The schedule owner UID (already impersonated).
     * @param string $output The message to post.
     *
     * @return string|null Null on success, or a reason string when the room is unusable.
     *
     * @spec openspec/changes/talk-delivery/tasks.md#1-deliveryservice-core
     */
    private function tryPostToTargetRoom(string $token, string $owner, string $output): ?string
    {
        try {
            $room        = $this->talkManager()->getRoomForUserByToken($token, $owner);
            $participant = $this->talkParticipantService()->getParticipant($room, $owner);
            $this->postToRoom(room: $room, participant: $participant, owner: $owner, output: $output);
            return null;
        } catch (Throwable $e) {
            return sprintf("target room '%s' unavailable: %s", $token, $e->getMessage());
        }//end try

    }//end tryPostToTargetRoom()

    /**
     * Attempt to post to the owner's Note-to-self conversation.
     *
     * @param string $owner  The schedule owner UID.
     * @param string $output The message to post.
     *
     * @return string|null Null on success, or a reason string on failure.
     *
     * @spec openspec/changes/talk-delivery/tasks.md#1-deliveryservice-core
     */
    private function tryPostToNoteToSelf(string $owner, string $output): ?string
    {
        try {
            $room        = $this->talkNoteToSelfService()->ensureNoteToSelfExistsForUser($owner);
            $participant = $this->talkParticipantService()->getParticipant($room, $owner);
            $this->postToRoom(room: $room, participant: $participant, owner: $owner, output: $output);
            return null;
        } catch (Throwable $e) {
            return 'Note-to-self unavailable: '.$e->getMessage();
        }//end try

    }//end tryPostToNoteToSelf()

    /**
     * Post a message to a resolved Talk room as the owner via ChatManager::sendMessage.
     *
     * @param object      $room        The resolved spreed Room (owner is a member).
     * @param object|null $participant The owner's Participant, or null.
     * @param string      $owner       The owner UID (actorId).
     * @param string      $output      The message body.
     *
     * @return void
     *
     * @spec openspec/changes/talk-delivery/tasks.md#1-deliveryservice-core
     */
    private function postToRoom(object $room, ?object $participant, string $owner, string $output): void
    {
        $this->talkChatManager()->sendMessage(
            $room,
            $participant,
            self::ACTOR_USERS,
            $owner,
            $output,
            new DateTime()
        );

    }//end postToRoom()

    /**
     * Raise a Nextcloud notification to the schedule owner linking to the schedule.
     *
     * The baseline channel and the terminal fallback of the Talk chain. Has no Talk
     * dependency. A failure here is still non-fatal — it is caught and reported.
     *
     * @param ObjectEntity $schedule The schedule being delivered.
     * @param string       $output   The agent output (used to size a short summary).
     * @param bool         $fellBack Whether this was reached as a fallback.
     * @param string|null  $reason   The accumulated Talk fallback reason, if any.
     *
     * @return DeliveryResult The notification delivery outcome.
     *
     * @spec openspec/changes/talk-delivery/tasks.md#1-deliveryservice-core
     */
    private function deliverNotification(ObjectEntity $schedule, string $output, bool $fellBack, ?string $reason=null): DeliveryResult
    {
        $uuid = (string) $schedule->getUuid();

        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp('hermiq')
                ->setUser((string) ($schedule->getOwner() ?? ''))
                ->setDateTime(new DateTime())
                ->setObject('schedule', $uuid)
                ->setSubject('run_complete', ['name' => (string) ($schedule->getObject()['name'] ?? '')])
                ->setMessage('run_summary', ['length' => strlen($output)])
                ->setLink($this->buildScheduleLink(uuid: $uuid));
            $this->notificationManager->notify($notification);

            $warning = null;
            if ($fellBack === true) {
                $warning = $reason;
            }

            $this->logWarning(warning: $warning, uuid: $uuid, channel: 'notification');
            return new DeliveryResult(delivered: true, channel: 'notification', fellBack: $fellBack, warning: $warning);
        } catch (Throwable $e) {
            $parts   = array_filter([$reason, 'notification failed: '.$e->getMessage()]);
            $warning = implode('; ', $parts);
            $this->logWarning(warning: $warning, uuid: $uuid, channel: 'notification');
            return new DeliveryResult(delivered: false, channel: 'notification', fellBack: $fellBack, warning: $warning);
        }//end try

    }//end deliverNotification()

    /**
     * Deliver via email (delivery-channels): the redacted output is sent to the
     * schedule owner's own Nextcloud account email, or an explicit `deliverTarget`
     * recipient, via Nextcloud's own `OCP\Mail\IMailer` — never a bespoke SMTP
     * client. NEVER throws: a missing recipient or a mailer failure is reported as
     * a warning, never fails the run.
     *
     * @param ObjectEntity $schedule The schedule being delivered.
     * @param string       $output   The agent output to email (redacted before send).
     *
     * @return DeliveryResult The email delivery outcome.
     *
     * @spec openspec/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-email-mvp
     */
    private function deliverEmail(ObjectEntity $schedule, string $output): DeliveryResult
    {
        $uuid      = (string) $schedule->getUuid();
        $data      = $schedule->getObject();
        $recipient = trim((string) ($data['deliverTarget'] ?? ''));

        if ($recipient === '') {
            $recipient = $this->resolveOwnerEmail(owner: (string) ($schedule->getOwner() ?? ''));
        }

        if ($recipient === '' || $this->mailer->validateMailAddress($recipient) === false) {
            $warning = 'No email recipient could be resolved';
            $this->logWarning(warning: $warning, uuid: $uuid, channel: 'email');
            return new DeliveryResult(delivered: false, channel: 'email', fellBack: false, warning: $warning);
        }

        $scheduleName = (string) ($data['name'] ?? 'Hermiq schedule');
        $redacted     = $this->redactionService->redact(text: $output);

        try {
            $message = $this->mailer->createMessage();
            $message->setTo([$recipient]);
            $message->setSubject(sprintf('[Hermiq] %s', $scheduleName));
            $message->setPlainBody($redacted);

            $failed = $this->mailer->send($message);
            if ($failed !== []) {
                $warning = 'Email send reported failed recipients: '.implode(', ', $failed);
                $this->logWarning(warning: $warning, uuid: $uuid, channel: 'email');
                return new DeliveryResult(delivered: false, channel: 'email', fellBack: false, warning: $warning);
            }

            $this->logWarning(warning: null, uuid: $uuid, channel: 'email');
            return new DeliveryResult(delivered: true, channel: 'email', fellBack: false, warning: null);
        } catch (Throwable $e) {
            $warning = 'Email send failed: '.$e->getMessage();
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
    private function resolveOwnerEmail(string $owner): string
    {
        if ($owner === '') {
            return '';
        }

        $user = $this->userManager->get($owner);
        if ($user === null) {
            return '';
        }

        return (string) ($user->getEMailAddress() ?? '');

    }//end resolveOwnerEmail()

    /**
     * Deliver via a signed outbound webhook (delivery-channels): POST a redacted,
     * size-capped JSON envelope to `deliverTarget`, signed with
     * `X-Hermiq-Signature: sha256=<hex>` over the exact bytes sent, with a bounded
     * exponential-backoff retry. NEVER throws: a missing URL/secret or an
     * exhausted retry budget is reported as a warning, never fails the run.
     *
     * @param ObjectEntity $schedule The schedule being delivered.
     * @param string       $output   The agent output to POST (redacted before send).
     *
     * @return DeliveryResult The webhook delivery outcome.
     *
     * @spec openspec/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-a-signed-outbound-webhook-mvp
     * @spec openspec/specs/talk-delivery/spec.md#requirement-webhook-delivery-retries-with-bounded-exponential-backoff-mvp
     * @spec openspec/specs/talk-delivery/spec.md#requirement-webhook-payload-is-size-capped-before-it-is-signed-and-sent-mvp
     */
    private function deliverWebhook(ObjectEntity $schedule, string $output): DeliveryResult
    {
        $uuid = (string) $schedule->getUuid();
        $data = $schedule->getObject();
        $url  = trim((string) ($data['deliverTarget'] ?? ''));

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
        $body     = $this->capWebhookPayload(
            envelope: $this->buildWebhookEnvelope(schedule: $schedule, agentId: (string) ($data['agentId'] ?? ''), output: $redacted)
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
                return new DeliveryResult(delivered: true, channel: 'webhook', fellBack: false, warning: null);
            }
        }

        $warning = sprintf('Webhook delivery failed after %d attempt(s): %s', $maxAttempts, $lastError);
        $this->logWarning(warning: $warning, uuid: $uuid, channel: 'webhook');
        return new DeliveryResult(delivered: false, channel: 'webhook', fellBack: false, warning: $warning);

    }//end deliverWebhook()

    /**
     * Attempt a single signed webhook POST.
     *
     * @param string $url    The destination URL.
     * @param string $body   The exact, final (redacted, capped) JSON body to sign and send.
     * @param string $secret The schedule's webhook signing secret.
     *
     * @return string '' on a successful (2xx) response, or a reason string on failure.
     *
     * @spec openspec/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-a-signed-outbound-webhook-mvp
     */
    private function tryPostWebhook(string $url, string $body, string $secret): string
    {
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

        try {
            $response = $this->clientService->newClient()->post(
                $url,
                [
                    'body'    => $body,
                    'headers' => [
                        'Content-Type'                 => 'application/json',
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
     * @param string       $agentId  The bound agent's UUID.
     * @param string       $output   The already-redacted output.
     *
     * @return array<string, mixed> The envelope (pre-size-cap).
     *
     * @spec openspec/specs/talk-delivery/spec.md#requirement-webhook-payload-is-size-capped-before-it-is-signed-and-sent-mvp
     */
    private function buildWebhookEnvelope(ObjectEntity $schedule, string $agentId, string $output): array
    {
        return [
            'scheduleId'  => (string) $schedule->getUuid(),
            'agentId'     => $agentId,
            'status'      => 'ok',
            'deliveredAt' => (new DateTime())->format('c'),
            'output'      => $output,
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
    private function capWebhookPayload(array $envelope): string
    {
        $encoded = (string) json_encode($envelope);

        for ($i = 0; $i < 20; $i++) {
            $excess = strlen($encoded) - self::WEBHOOK_MAX_PAYLOAD_BYTES;
            if ($excess <= 0) {
                break;
            }

            $output = (string) $envelope['output'];
            $cut    = max($excess + strlen(self::WEBHOOK_TRUNCATION_MARKER), 1);
            $newLen = max(strlen($output) - $cut, 0);

            $envelope['output'] = substr($output, 0, $newLen).self::WEBHOOK_TRUNCATION_MARKER;
            $encoded            = (string) json_encode($envelope);
        }//end for

        return $encoded;

    }//end capWebhookPayload()

    /**
     * Clamp an integer schedule field to its documented schema bounds,
     * tolerating a missing/non-numeric stored value by falling back within range.
     *
     * @param mixed $value The raw stored value.
     * @param int   $min   The minimum allowed value.
     * @param int   $max   The maximum allowed value.
     *
     * @return int The clamped value.
     *
     * @spec openspec/specs/talk-delivery/spec.md#requirement-webhook-delivery-retries-with-bounded-exponential-backoff-mvp
     */
    private function clampInt(mixed $value, int $min, int $max): int
    {
        $int = (int) $value;
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
    protected function sleep(int $seconds): void
    {
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
     * @spec openspec/changes/talk-delivery/tasks.md#1-deliveryservice-core
     */
    private function talkManager(): object
    {
        return $this->container->get(self::TALK_MANAGER);

    }//end talkManager()

    /**
     * Lazily resolve the spreed participant service from the server container.
     *
     * @return \OCA\Talk\Service\ParticipantService The spreed participant service.
     *
     * @spec openspec/changes/talk-delivery/tasks.md#1-deliveryservice-core
     */
    private function talkParticipantService(): object
    {
        return $this->container->get(self::TALK_PARTICIPANT_SERVICE);

    }//end talkParticipantService()

    /**
     * Lazily resolve the spreed Note-to-self service from the server container.
     *
     * @return \OCA\Talk\Service\NoteToSelfService The spreed Note-to-self service.
     *
     * @spec openspec/changes/talk-delivery/tasks.md#1-deliveryservice-core
     */
    private function talkNoteToSelfService(): object
    {
        return $this->container->get(self::TALK_NOTE_TO_SELF_SERVICE);

    }//end talkNoteToSelfService()

    /**
     * Lazily resolve the spreed chat manager from the server container.
     *
     * @return \OCA\Talk\Chat\ChatManager The spreed chat manager.
     *
     * @spec openspec/changes/talk-delivery/tasks.md#1-deliveryservice-core
     */
    private function talkChatManager(): object
    {
        return $this->container->get(self::TALK_CHAT_MANAGER);

    }//end talkChatManager()

    /**
     * Lazily resolve the spreed room service from the server container.
     *
     * @return \OCA\Talk\Service\RoomService The spreed room service.
     *
     * @spec exclude Lazy spreed resolver; mirrors the other Talk resolvers.
     */
    private function talkRoomService(): object
    {
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
    private function resolveDefaultRoom(string $owner): string
    {
        if ($owner === '') {
            return '';
        }

        $stored = (string) $this->config->getUserValue($owner, 'hermiq', self::DELIVER_TARGET_PREF, '');
        if ($stored !== '') {
            return $stored;
        }

        try {
            $ownerUser = $this->userManager->get($owner);
            if ($ownerUser === null) {
                return '';
            }

            $room  = $this->talkRoomService()->createConversation(
                self::ROOM_TYPE_GROUP,
                self::DEFAULT_ROOM_NAME,
                $ownerUser,
            );
            $token = (string) $room->getToken();
            if ($token === '') {
                return '';
            }

            $this->config->setUserValue($owner, 'hermiq', self::DELIVER_TARGET_PREF, $token);
            return $token;
        } catch (Throwable $e) {
            $this->logger->debug('[Hermiq] default Talk room creation failed: '.$e->getMessage());
            return '';
        }//end try

    }//end resolveDefaultRoom()

    /**
     * Whether Talk is usable right now (core probe + spreed classes present).
     *
     * @return bool
     *
     * @spec openspec/changes/talk-delivery/tasks.md#1-deliveryservice-core
     */
    private function isTalkAvailable(): bool
    {
        return $this->talkBroker->hasBackend() === true && class_exists(self::TALK_MANAGER) === true;

    }//end isTalkAvailable()

    /**
     * Build an absolute deep link to the schedule for a notification.
     *
     * @param string $uuid The schedule UUID.
     *
     * @return string The absolute URL.
     */
    private function buildScheduleLink(string $uuid): string
    {
        return $this->urlGenerator->getAbsoluteURL('/index.php/apps/hermiq/schedules/'.$uuid);

    }//end buildScheduleLink()

    /**
     * Build an absolute deep link to the approvals inbox for a notification.
     *
     * @param string $uuid The approval UUID.
     *
     * @return string The absolute URL.
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#2-dispatcher-approval-gate-scheduleservice
     */
    private function buildApprovalLink(string $uuid): string
    {
        return $this->urlGenerator->getAbsoluteURL('/index.php/apps/hermiq/approvals/'.$uuid);

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
    private function buildSkillLink(string $uuid): string
    {
        return $this->urlGenerator->getAbsoluteURL('/index.php/apps/hermiq/skills/'.$uuid);

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
    private function buildBudgetLink(string $uuid): string
    {
        return $this->urlGenerator->getAbsoluteURL('/index.php/apps/hermiq/tenant-ops?budget='.$uuid);

    }//end buildBudgetLink()

    /**
     * Emit a PSR-3 warning for a degraded/failed delivery (no-op when clean).
     *
     * @param string|null $warning The warning message, or null on clean success.
     * @param string      $uuid    The schedule UUID.
     * @param string      $channel The channel involved.
     *
     * @return void
     *
     * @spec openspec/changes/talk-delivery/tasks.md#3-wire-into-the-dispatcher
     */
    private function logWarning(?string $warning, string $uuid, string $channel): void
    {
        if ($warning === null || $warning === '') {
            return;
        }

        $this->logger->warning(
            sprintf('Hermiq schedule %s delivery via %s degraded: %s', $uuid, $channel, $warning)
        );

    }//end logWarning()
}//end class
