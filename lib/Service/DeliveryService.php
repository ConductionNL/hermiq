<?php

/**
 * Hermiq DeliveryService.
 *
 * The real delivery layer behind ScheduleService's dispatch loop. It replaces the
 * former logging-only deliver() seam: after an agent run it posts the output to the
 * owner where they work — a specific Nextcloud Talk room, the owner's Note-to-self
 * conversation, or a Nextcloud notification — following the ADR-005 fallback chain.
 *
 * This is a legitimate ADR-031 imperative external-integration service: it makes
 * side-effecting calls into Nextcloud subsystems (Talk / Notifications). It owns no
 * schema, no derived value, no lifecycle — those stay declarative in OpenRegister.
 *
 * Design invariants:
 *   - Delivery NEVER throws for a delivery problem; every failure is caught and
 *     reported through a DeliveryResult so the run is never failed by delivery.
 *   - Talk (spreed) is an OPTIONAL runtime dependency: its server-side classes are
 *     resolved LAZILY through the injected server container, only after the core
 *     OCP\Talk\IBroker::hasBackend() probe and a class_exists() guard pass, so
 *     Hermiq boots and still delivers (via notification) when Talk is absent.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Talk\IBroker;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivers agent run output to Talk / Notifications per the schedule's deliver setting.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Coordinates several Nextcloud subsystems.
 *
 * @spec openspec/changes/talk-delivery/tasks.md#1-deliveryservice-core
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
     * Constructor.
     *
     * All constructor dependencies are ALWAYS-present Nextcloud services. The optional
     * spreed classes are resolved lazily through $container inside the Talk guard, so
     * this service (and Hermiq) construct cleanly on an instance without Talk.
     *
     * @param INotificationManager $notificationManager Raises Nextcloud notifications (baseline + fallback).
     * @param IBroker              $talkBroker          Core Talk availability probe (hasBackend()).
     * @param IURLGenerator        $urlGenerator        Builds the schedule/run deep link for notifications.
     * @param ContainerInterface   $container           Server container for lazy spreed resolution.
     * @param IConfig              $config              Reads/writes the owner's default-room preference.
     * @param IUserManager         $userManager         Resolves the owner IUser for room creation.
     * @param LoggerInterface      $logger              PSR-3 logger for delivery warnings.
     */
    public function __construct(
        private readonly INotificationManager $notificationManager,
        private readonly IBroker $talkBroker,
        private readonly IURLGenerator $urlGenerator,
        private readonly ContainerInterface $container,
        private readonly IConfig $config,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Deliver a run's output for one schedule.
     *
     * Never throws for a delivery problem: the outcome (including any warning to
     * persist as lastDeliveryError) is returned as a DeliveryResult.
     *
     * @param string       $channel  Delivery channel: talk|notification|none.
     * @param string       $output   The agent output to deliver.
     * @param ObjectEntity $schedule The schedule the output belongs to.
     *
     * @return DeliveryResult The delivery outcome.
     *
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-1
     */
    public function deliver(string $channel, string $output, ObjectEntity $schedule): DeliveryResult
    {
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
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-2-2
     */
    public function deliverApprovalRequest(ObjectEntity $schedule, ObjectEntity $approval, array $reviewerUids): DeliveryResult
    {
        $approvalUuid = (string) $approval->getUuid();
        $scheduleName = (string) ($schedule->getObject()['name'] ?? '');
        $talkOk       = $this->isTalkAvailable();
        $warnings     = [];
        $delivered    = false;

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
                    ->setSubject('approval_requested', ['name' => $scheduleName])
                    ->setMessage('approval_summary', ['scheduleId' => (string) $schedule->getUuid()])
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
                    output: sprintf('Approval needed for “%s”. Review it in Hermiq.', $scheduleName)
                );
            }
        }//end foreach

        $warning = null;
        if ($warnings !== []) {
            $warning = implode('; ', $warnings);
        }

        $this->logWarning(warning: $warning, uuid: $approvalUuid, channel: 'approval');
        return new DeliveryResult(delivered: $delivered, channel: 'notification', fellBack: false, warning: $warning);

    }//end deliverApprovalRequest()

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
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-4
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-5
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
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-4
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
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-4
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
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-4
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
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-3
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
     * Lazily resolve the spreed room manager from the server container.
     *
     * Called only after the isTalkAvailable() guard, so the concrete class is present.
     *
     * @return \OCA\Talk\Manager The spreed room manager.
     *
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-4
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
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-4
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
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-4
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
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-4
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
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-4
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
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-2-2
     */
    private function buildApprovalLink(string $uuid): string
    {
        return $this->urlGenerator->getAbsoluteURL('/index.php/apps/hermiq/approvals/'.$uuid);

    }//end buildApprovalLink()

    /**
     * Emit a PSR-3 warning for a degraded/failed delivery (no-op when clean).
     *
     * @param string|null $warning The warning message, or null on clean success.
     * @param string      $uuid    The schedule UUID.
     * @param string      $channel The channel involved.
     *
     * @return void
     *
     * @spec openspec/changes/talk-delivery/tasks.md#task-3-2
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
