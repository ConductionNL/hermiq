<?php

/**
 * Unit tests for DeliveryService (talk-delivery).
 *
 * Exercises the delivery contract without a live Nextcloud/Talk:
 *   - talk with deliverTarget → posts to that room (membership-checked)
 *   - empty deliverTarget → owner's Note-to-self
 *   - non-member / RoomNotFound target → falls back (Note-to-self, then notification)
 *   - Talk unavailable → notification
 *   - notification channel; none / empty output no-op
 *   - a delivery failure is reported as a warning, never thrown
 *
 * The notification manager, Talk broker, URL generator and the lazily-resolved spreed
 * classes (Manager, ParticipantService, NoteToSelfService, ChatManager) are all mocked
 * via the injected server container; spreed OCA\Talk stubs live under tests/Stubs/Talk.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/talk-delivery/tasks.md#4-tests
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\DeliveryService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Manager;
use OCA\Talk\Model\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\NoteToSelfService;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\RoomService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Talk\IBroker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the talk-delivery DeliveryService.
 *
 * @spec openspec/changes/talk-delivery/tasks.md#4-tests
 */
class DeliveryServiceTest extends TestCase
{

    /**
     * Mock notification manager.
     *
     * @var INotificationManager&MockObject
     */
    private INotificationManager $notificationManager;

    /**
     * Mock Talk broker.
     *
     * @var IBroker&MockObject
     */
    private IBroker $talkBroker;

    /**
     * Mock URL generator.
     *
     * @var IURLGenerator&MockObject
     */
    private IURLGenerator $urlGenerator;

    /**
     * Mock server container (lazy spreed resolution).
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface $container;

    /**
     * Registry of class-string ⇒ resolved service used by the container mock.
     *
     * @var array<string, object>
     */
    private array $services = [];

    /**
     * Mock config (owner default-room preference).
     *
     * @var IConfig&MockObject
     */
    private IConfig $config;

    /**
     * Mock user manager (owner IUser resolution for room creation).
     *
     * @var IUserManager&MockObject
     */
    private IUserManager $userManager;

    /**
     * Service under test.
     *
     * @var DeliveryService
     */
    private DeliveryService $service;

    /**
     * Wire fresh mocks before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->talkBroker          = $this->createMock(IBroker::class);
        $this->urlGenerator        = $this->createMock(IURLGenerator::class);
        $this->container           = $this->createMock(ContainerInterface::class);
        $this->config              = $this->createMock(IConfig::class);
        $this->userManager         = $this->createMock(IUserManager::class);
        $this->services            = [];

        // getUserValue is left unstubbed → returns '' (no default-room pref) by
        // default; tests that exercise the default-room path stub it explicitly.
        $this->urlGenerator->method('imagePath')->willReturn('/img/app-dark.svg');
        $this->urlGenerator->method('getAbsoluteURL')->willReturnArgument(0);

        // The container resolves spreed classes from the per-test registry.
        $this->container->method('get')->willReturnCallback(
            function (string $id): object {
                if (isset($this->services[$id]) === false) {
                    throw new \RuntimeException('No stub registered for '.$id);
                }

                return $this->services[$id];
            }
        );

        $this->service = new DeliveryService(
            notificationManager: $this->notificationManager,
            talkBroker: $this->talkBroker,
            urlGenerator: $this->urlGenerator,
            container: $this->container,
            config: $this->config,
            userManager: $this->userManager,
            logger: $this->createMock(LoggerInterface::class),
        );

    }//end setUp()

    /**
     * Build a schedule ObjectEntity for delivery.
     *
     * @param array<string,mixed> $payload The schedule body.
     * @param string              $owner   The owner UID.
     *
     * @return ObjectEntity
     */
    private function schedule(array $payload, string $owner='alice'): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('00000000-0000-0000-0000-000000000000');
        $entity->setOwner($owner);
        $entity->setObject($payload);
        return $entity;

    }//end schedule()

    /**
     * Build an INotification whose fluent setters return itself.
     *
     * @return INotification&MockObject
     */
    private function notificationMock(): INotification
    {
        $notification = $this->createMock(INotification::class);
        foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject', 'setMessage', 'setLink'] as $setter) {
            $notification->method($setter)->willReturnSelf();
        }

        return $notification;

    }//end notificationMock()

    /**
     * deliver=talk with a deliverTarget posts to that room after a membership-checked resolve.
     *
     * @return void
     *
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-4
     */
    public function testTalkWithDeliverTargetPostsToRoom(): void
    {
        $this->talkBroker->method('hasBackend')->willReturn(true);

        $room    = new Room();
        $manager = $this->createMock(Manager::class);
        $manager->expects($this->once())
            ->method('getRoomForUserByToken')
            ->with('room-x', 'alice')
            ->willReturn($room);

        $participantService = $this->createMock(ParticipantService::class);
        $participantService->method('getParticipant')->willReturn(new Participant());

        $chatManager = $this->createMock(ChatManager::class);
        $chatManager->expects($this->once())->method('sendMessage');

        // Note-to-self must NOT be used on the happy target path.
        $noteToSelf = $this->createMock(NoteToSelfService::class);
        $noteToSelf->expects($this->never())->method('ensureNoteToSelfExistsForUser');

        $this->services = [
            Manager::class            => $manager,
            ParticipantService::class => $participantService,
            ChatManager::class        => $chatManager,
            NoteToSelfService::class  => $noteToSelf,
        ];

        $result = $this->service->deliver(
            channel: 'talk',
            output: 'Daily briefing',
            schedule: $this->schedule(['deliver' => 'talk', 'deliverTarget' => 'room-x'])
        );

        $this->assertTrue($result->isDelivered());
        $this->assertSame('talk', $result->getChannel());
        $this->assertFalse($result->didFallBack());
        $this->assertNull($result->getWarning());

    }//end testTalkWithDeliverTargetPostsToRoom()

    /**
     * deliver=talk with no deliverTarget posts to the owner's Note-to-self.
     *
     * @return void
     *
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-4
     */
    public function testEmptyDeliverTargetUsesNoteToSelf(): void
    {
        $this->talkBroker->method('hasBackend')->willReturn(true);

        // Target room resolution must NOT happen when deliverTarget is empty.
        $manager = $this->createMock(Manager::class);
        $manager->expects($this->never())->method('getRoomForUserByToken');

        $noteToSelf = $this->createMock(NoteToSelfService::class);
        $noteToSelf->expects($this->once())
            ->method('ensureNoteToSelfExistsForUser')
            ->with('alice')
            ->willReturn(new Room());

        $participantService = $this->createMock(ParticipantService::class);
        $participantService->method('getParticipant')->willReturn(new Participant());

        $chatManager = $this->createMock(ChatManager::class);
        $chatManager->expects($this->once())->method('sendMessage');

        $this->services = [
            Manager::class            => $manager,
            NoteToSelfService::class  => $noteToSelf,
            ParticipantService::class => $participantService,
            ChatManager::class        => $chatManager,
        ];

        $result = $this->service->deliver(
            channel: 'talk',
            output: 'Note to self please',
            schedule: $this->schedule(['deliver' => 'talk', 'deliverTarget' => ''])
        );

        $this->assertTrue($result->isDelivered());
        $this->assertSame('talk', $result->getChannel());
        $this->assertFalse($result->didFallBack());
        $this->assertNull($result->getWarning());

    }//end testEmptyDeliverTargetUsesNoteToSelf()

    /**
     * No schedule target but a stored default-room preference posts to that room
     * (no creation, no Note-to-self).
     *
     * @return void
     *
     * @spec exclude Per-user default-room fallback; no behavioural spec yet.
     */
    public function testEmptyTargetUsesStoredDefaultRoom(): void
    {
        $this->talkBroker->method('hasBackend')->willReturn(true);
        $this->config->method('getUserValue')
            ->with('alice', 'hermiq', 'pref_delivertarget', '')
            ->willReturn('default-room');

        $manager = $this->createMock(Manager::class);
        $manager->expects($this->once())
            ->method('getRoomForUserByToken')
            ->with('default-room', 'alice')
            ->willReturn(new Room());

        // A stored default must NOT create a room nor fall to Note-to-self.
        $roomService = $this->createMock(RoomService::class);
        $roomService->expects($this->never())->method('createConversation');
        $noteToSelf = $this->createMock(NoteToSelfService::class);
        $noteToSelf->expects($this->never())->method('ensureNoteToSelfExistsForUser');

        $participantService = $this->createMock(ParticipantService::class);
        $participantService->method('getParticipant')->willReturn(new Participant());
        $chatManager = $this->createMock(ChatManager::class);
        $chatManager->expects($this->once())->method('sendMessage');

        $this->services = [
            Manager::class            => $manager,
            RoomService::class        => $roomService,
            NoteToSelfService::class  => $noteToSelf,
            ParticipantService::class => $participantService,
            ChatManager::class        => $chatManager,
        ];

        $result = $this->service->deliver(
            channel: 'talk',
            output: 'Briefing',
            schedule: $this->schedule(['deliver' => 'talk', 'deliverTarget' => ''])
        );

        $this->assertTrue($result->isDelivered());
        $this->assertSame('talk', $result->getChannel());
        $this->assertFalse($result->didFallBack());

    }//end testEmptyTargetUsesStoredDefaultRoom()

    /**
     * No target and no stored preference lazily creates a "Hermiq" room, persists
     * its token as the owner's preference, and delivers there.
     *
     * @return void
     *
     * @spec exclude Lazy default-room creation; no behavioural spec yet.
     */
    public function testEmptyTargetLazilyCreatesHermiqRoom(): void
    {
        $this->talkBroker->method('hasBackend')->willReturn(true);
        // No stored pref (default '' from the unstubbed mock).
        $this->userManager->method('get')->with('alice')->willReturn($this->createMock(IUser::class));

        $createdRoom = $this->createMock(Room::class);
        $createdRoom->method('getToken')->willReturn('new-hermiq-token');

        $roomService = $this->createMock(RoomService::class);
        $roomService->expects($this->once())
            ->method('createConversation')
            ->with(2, 'Hermiq', $this->anything())
            ->willReturn($createdRoom);

        // The freshly-created token must be persisted as the owner's default.
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('alice', 'hermiq', 'pref_delivertarget', 'new-hermiq-token');

        $manager = $this->createMock(Manager::class);
        $manager->expects($this->once())
            ->method('getRoomForUserByToken')
            ->with('new-hermiq-token', 'alice')
            ->willReturn(new Room());

        $noteToSelf = $this->createMock(NoteToSelfService::class);
        $noteToSelf->expects($this->never())->method('ensureNoteToSelfExistsForUser');
        $participantService = $this->createMock(ParticipantService::class);
        $participantService->method('getParticipant')->willReturn(new Participant());
        $chatManager = $this->createMock(ChatManager::class);
        $chatManager->expects($this->once())->method('sendMessage');

        $this->services = [
            Manager::class            => $manager,
            RoomService::class        => $roomService,
            NoteToSelfService::class  => $noteToSelf,
            ParticipantService::class => $participantService,
            ChatManager::class        => $chatManager,
        ];

        $result = $this->service->deliver(
            channel: 'talk',
            output: 'First run',
            schedule: $this->schedule(['deliver' => 'talk', 'deliverTarget' => ''])
        );

        $this->assertTrue($result->isDelivered());
        $this->assertSame('talk', $result->getChannel());

    }//end testEmptyTargetLazilyCreatesHermiqRoom()

    /**
     * A non-member / RoomNotFound target falls back to Note-to-self and records a warning.
     *
     * @return void
     *
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-5
     */
    public function testNonMemberTargetFallsBackToNoteToSelf(): void
    {
        $this->talkBroker->method('hasBackend')->willReturn(true);

        $manager = $this->createMock(Manager::class);
        $manager->method('getRoomForUserByToken')
            ->willThrowException(new RoomNotFoundException('not a member'));

        $noteToSelf = $this->createMock(NoteToSelfService::class);
        $noteToSelf->expects($this->once())
            ->method('ensureNoteToSelfExistsForUser')
            ->willReturn(new Room());

        $participantService = $this->createMock(ParticipantService::class);
        $participantService->method('getParticipant')->willReturn(new Participant());

        $chatManager = $this->createMock(ChatManager::class);
        $chatManager->expects($this->once())->method('sendMessage');

        $this->services = [
            Manager::class            => $manager,
            NoteToSelfService::class  => $noteToSelf,
            ParticipantService::class => $participantService,
            ChatManager::class        => $chatManager,
        ];

        $result = $this->service->deliver(
            channel: 'talk',
            output: 'Briefing',
            schedule: $this->schedule(['deliver' => 'talk', 'deliverTarget' => 'ghost-room'])
        );

        $this->assertTrue($result->isDelivered());
        $this->assertSame('talk', $result->getChannel());
        $this->assertTrue($result->didFallBack());
        $this->assertNotNull($result->getWarning());
        $this->assertStringContainsString('ghost-room', (string) $result->getWarning());

    }//end testNonMemberTargetFallsBackToNoteToSelf()

    /**
     * When target AND Note-to-self fail, delivery falls all the way back to a notification.
     *
     * @return void
     *
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-5
     */
    public function testTargetAndNoteToSelfFailFallBackToNotification(): void
    {
        $this->talkBroker->method('hasBackend')->willReturn(true);

        $manager = $this->createMock(Manager::class);
        $manager->method('getRoomForUserByToken')
            ->willThrowException(new RoomNotFoundException('not a member'));

        $noteToSelf = $this->createMock(NoteToSelfService::class);
        $noteToSelf->method('ensureNoteToSelfExistsForUser')
            ->willThrowException(new \RuntimeException('note-to-self down'));

        $this->services = [
            Manager::class           => $manager,
            NoteToSelfService::class => $noteToSelf,
        ];

        $this->notificationManager->method('createNotification')->willReturn($this->notificationMock());
        $this->notificationManager->expects($this->once())->method('notify');

        $result = $this->service->deliver(
            channel: 'talk',
            output: 'Briefing',
            schedule: $this->schedule(['deliver' => 'talk', 'deliverTarget' => 'ghost-room'])
        );

        $this->assertTrue($result->isDelivered());
        $this->assertSame('notification', $result->getChannel());
        $this->assertTrue($result->didFallBack());
        $this->assertNotNull($result->getWarning());

    }//end testTargetAndNoteToSelfFailFallBackToNotification()

    /**
     * deliver=talk with Talk unavailable falls back to a notification.
     *
     * @return void
     *
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-5
     */
    public function testTalkAbsentFallsBackToNotification(): void
    {
        $this->talkBroker->method('hasBackend')->willReturn(false);

        $this->notificationManager->method('createNotification')->willReturn($this->notificationMock());
        $this->notificationManager->expects($this->once())->method('notify');

        $result = $this->service->deliver(
            channel: 'talk',
            output: 'Briefing',
            schedule: $this->schedule(['deliver' => 'talk', 'deliverTarget' => 'room-x'])
        );

        $this->assertTrue($result->isDelivered());
        $this->assertSame('notification', $result->getChannel());
        $this->assertTrue($result->didFallBack());
        $this->assertNotNull($result->getWarning());

    }//end testTalkAbsentFallsBackToNotification()

    /**
     * deliver=notification is a first-class channel: raises a notification to the owner.
     *
     * @return void
     *
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-3
     */
    public function testNotificationChannel(): void
    {
        $this->notificationManager->method('createNotification')->willReturn($this->notificationMock());
        $this->notificationManager->expects($this->once())->method('notify');

        $result = $this->service->deliver(
            channel: 'notification',
            output: 'Weekly digest',
            schedule: $this->schedule(['deliver' => 'notification'])
        );

        $this->assertTrue($result->isDelivered());
        $this->assertSame('notification', $result->getChannel());
        $this->assertFalse($result->didFallBack());
        $this->assertNull($result->getWarning());

    }//end testNotificationChannel()

    /**
     * deliver=none performs no delivery.
     *
     * @return void
     *
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-1
     */
    public function testNoneChannelNoOp(): void
    {
        $this->notificationManager->expects($this->never())->method('notify');

        $result = $this->service->deliver(
            channel: 'none',
            output: 'ignored',
            schedule: $this->schedule(['deliver' => 'none'])
        );

        $this->assertFalse($result->isDelivered());
        $this->assertSame('none', $result->getChannel());
        $this->assertNull($result->getWarning());

    }//end testNoneChannelNoOp()

    /**
     * Empty / whitespace-only output posts nothing on any channel.
     *
     * @return void
     *
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-1
     */
    public function testEmptyOutputNoOp(): void
    {
        $this->notificationManager->expects($this->never())->method('notify');

        $result = $this->service->deliver(
            channel: 'talk',
            output: "   \n  ",
            schedule: $this->schedule(['deliver' => 'talk', 'deliverTarget' => 'room-x'])
        );

        $this->assertFalse($result->isDelivered());
        $this->assertSame('none', $result->getChannel());
        $this->assertNull($result->getWarning());

    }//end testEmptyOutputNoOp()

    /**
     * A notification failure is reported as a warning, not thrown (never fails the run).
     *
     * @return void
     *
     * @spec openspec/changes/talk-delivery/tasks.md#task-1-2
     */
    public function testDeliveryFailureIsReportedNotThrown(): void
    {
        $this->notificationManager->method('createNotification')->willReturn($this->notificationMock());
        $this->notificationManager->method('notify')->willThrowException(new \RuntimeException('bus down'));

        $result = $this->service->deliver(
            channel: 'notification',
            output: 'Weekly digest',
            schedule: $this->schedule(['deliver' => 'notification'])
        );

        $this->assertFalse($result->isDelivered());
        $this->assertNotNull($result->getWarning());
        $this->assertStringContainsString('notification failed', (string) $result->getWarning());

    }//end testDeliveryFailureIsReportedNotThrown()

    /**
     * deliverApprovalRequest raises one notification per resolved reviewer (Art. 14),
     * deep-linked to the approvals inbox, and never throws for a delivery problem.
     *
     * @return void
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-2-2
     */
    public function testApprovalRequestNotifiesEachReviewer(): void
    {
        // Talk unavailable → notification-only path.
        $this->talkBroker->method('hasBackend')->willReturn(false);
        $this->notificationManager->method('createNotification')->willReturn($this->notificationMock());
        $this->notificationManager->expects($this->exactly(2))->method('notify');

        $approval = new ObjectEntity();
        $approval->setUuid('appr-1');

        $result = $this->service->deliverApprovalRequest(
            schedule: $this->schedule(['name' => 'Permit drafts']),
            approval: $approval,
            reviewerUids: ['bob', 'carol']
        );

        $this->assertTrue($result->isDelivered(), 'At least one reviewer must be notified.');
        $this->assertNull($result->getWarning(), 'A clean notification run carries no warning.');

    }//end testApprovalRequestNotifiesEachReviewer()

    /**
     * deliverApprovalRequestForFlowRun — the flow-triggered counterpart to
     * deliverApprovalRequest — raises one notification per resolved reviewer using
     * the approval's own flowContext (no Schedule ObjectEntity involved), and never
     * throws for a delivery problem.
     *
     * @return void
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-3-2
     */
    public function testFlowRunApprovalRequestNotifiesEachReviewer(): void
    {
        // Talk unavailable → notification-only path.
        $this->talkBroker->method('hasBackend')->willReturn(false);
        $this->notificationManager->method('createNotification')->willReturn($this->notificationMock());
        $this->notificationManager->expects($this->exactly(2))->method('notify');

        $approval = new ObjectEntity();
        $approval->setUuid('appr-flow-1');
        $approval->setObject([
            'status'      => 'pending',
            'sourceType'  => 'flow',
            'agentId'     => 'agent-uuid-1',
            'flowContext' => ['flowName' => 'classify-tender', 'correlationId' => 'corr-1'],
        ]);

        $result = $this->service->deliverApprovalRequestForFlowRun(
            approval: $approval,
            reviewerUids: ['bob', 'carol']
        );

        $this->assertTrue($result->isDelivered(), 'At least one reviewer must be notified.');
        $this->assertNull($result->getWarning(), 'A clean notification run carries no warning.');

    }//end testFlowRunApprovalRequestNotifiesEachReviewer()

    /**
     * run-reliability: deliverFailureAlert notifies the schedule owner even when
     * Talk is unavailable, and never throws.
     *
     * @return void
     *
     * @spec openspec/changes/run-reliability/specs/talk-delivery/spec.md#requirement-deliver-a-failure-alert-to-the-schedule-owner-mvp
     */
    public function testDeliverFailureAlertNotifiesOwner(): void
    {
        $this->talkBroker->method('hasBackend')->willReturn(false);
        $this->notificationManager->method('createNotification')->willReturn($this->notificationMock());
        $this->notificationManager->expects($this->once())->method('notify');

        $result = $this->service->deliverFailureAlert(
            schedule: $this->schedule(['name' => 'Flaky monitor']),
            reason: 'agent exploded'
        );

        $this->assertTrue($result->isDelivered());
        $this->assertSame('notification', $result->getChannel());
        $this->assertNull($result->getWarning());

    }//end testDeliverFailureAlertNotifiesOwner()

    /**
     * run-reliability: deliverFailureAlert fires regardless of the schedule's own
     * `deliver` setting — even `deliver=none` still gets the alert (it is not a
     * run-output delivery).
     *
     * @return void
     *
     * @spec openspec/changes/run-reliability/specs/talk-delivery/spec.md#requirement-deliver-a-failure-alert-to-the-schedule-owner-mvp
     */
    public function testDeliverFailureAlertFiresEvenWhenDeliverIsNone(): void
    {
        $this->talkBroker->method('hasBackend')->willReturn(false);
        $this->notificationManager->method('createNotification')->willReturn($this->notificationMock());
        $this->notificationManager->expects($this->once())->method('notify');

        $result = $this->service->deliverFailureAlert(
            schedule: $this->schedule(['name' => 'Silent schedule', 'deliver' => 'none']),
            reason: 'exhausted retries'
        );

        $this->assertTrue($result->isDelivered(), 'A dead-letter alert must fire even when deliver=none.');

    }//end testDeliverFailureAlertFiresEvenWhenDeliverIsNone()

    /**
     * run-reliability: a failed notification is reported as a warning, never thrown.
     *
     * @return void
     *
     * @spec openspec/changes/run-reliability/specs/talk-delivery/spec.md#requirement-deliver-a-failure-alert-to-the-schedule-owner-mvp
     */
    public function testDeliverFailureAlertNeverThrowsOnNotificationFailure(): void
    {
        $this->talkBroker->method('hasBackend')->willReturn(false);
        $this->notificationManager->method('createNotification')->willReturn($this->notificationMock());
        $this->notificationManager->method('notify')->willThrowException(new \RuntimeException('bus down'));

        $result = $this->service->deliverFailureAlert(
            schedule: $this->schedule(['name' => 'Flaky monitor']),
            reason: 'agent exploded'
        );

        $this->assertFalse($result->isDelivered());
        $this->assertNotNull($result->getWarning());

    }//end testDeliverFailureAlertNeverThrowsOnNotificationFailure()

    /**
     * run-reliability: deliverCircuitBreakerAlert notifies the owner, distinct from
     * the dead-letter alert.
     *
     * @return void
     *
     * @spec openspec/changes/run-reliability/specs/talk-delivery/spec.md#requirement-deliver-a-failure-alert-to-the-schedule-owner-mvp
     */
    public function testDeliverCircuitBreakerAlertNotifiesOwner(): void
    {
        $this->talkBroker->method('hasBackend')->willReturn(false);
        $this->notificationManager->method('createNotification')->willReturn($this->notificationMock());
        $this->notificationManager->expects($this->once())->method('notify');

        $result = $this->service->deliverCircuitBreakerAlert(
            schedule: $this->schedule(['name' => 'Chronically failing'])
        );

        $this->assertTrue($result->isDelivered());
        $this->assertSame('notification', $result->getChannel());

    }//end testDeliverCircuitBreakerAlertNotifiesOwner()

    /**
     * run-reliability: a failed circuit-breaker alert is reported as a warning,
     * never thrown — the already-recorded state is unaffected by this method.
     *
     * @return void
     *
     * @spec openspec/changes/run-reliability/specs/talk-delivery/spec.md#requirement-deliver-a-failure-alert-to-the-schedule-owner-mvp
     */
    public function testDeliverCircuitBreakerAlertNeverThrowsOnNotificationFailure(): void
    {
        $this->talkBroker->method('hasBackend')->willReturn(false);
        $this->notificationManager->method('createNotification')->willReturn($this->notificationMock());
        $this->notificationManager->method('notify')->willThrowException(new \RuntimeException('bus down'));

        $result = $this->service->deliverCircuitBreakerAlert(
            schedule: $this->schedule(['name' => 'Chronically failing'])
        );

        $this->assertFalse($result->isDelivered());
        $this->assertNotNull($result->getWarning());

    }//end testDeliverCircuitBreakerAlertNeverThrowsOnNotificationFailure()
}//end class
