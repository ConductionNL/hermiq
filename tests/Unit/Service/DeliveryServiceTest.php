<?php

/**
 * Unit tests for DeliveryService (talk-delivery, delivery-channels).
 *
 * Exercises the delivery contract without a live Nextcloud/Talk:
 *   - talk with deliverTarget → posts to that room (membership-checked)
 *   - empty deliverTarget → owner's Note-to-self
 *   - non-member / RoomNotFound target → falls back (Note-to-self, then notification)
 *   - Talk unavailable → notification
 *   - notification channel; none / empty output no-op
 *   - a delivery failure is reported as a warning, never thrown
 *   - email: owner-default recipient, explicit recipient, no-recipient degrades
 *   - webhook: signed POST, missing secret/URL fail closed, retry with backoff,
 *     oversized output truncated before signing, redaction applied
 *
 * The notification manager, Talk broker, URL generator and the lazily-resolved spreed
 * classes (Manager, ParticipantService, NoteToSelfService, ChatManager) are all mocked
 * via the injected server container; spreed OCA\Talk stubs live under tests/Stubs/Talk.
 * `DeliveryServiceTestable` overrides the real (webhook-retry) sleep with a no-op so
 * the suite never actually blocks for a backoff delay.
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
 * @spec openspec/changes/delivery-channels/tasks.md#task-4-deliveryservicedeliveremail-redact-resolve-recipient-send-via-imailer
 * @spec openspec/changes/delivery-channels/tasks.md#task-5-deliveryservicedeliverwebhook-hmac-sign-bounded-retry-size-cap
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\DeliveryService;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ScheduleWebhookSecretService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Talk\Chat\ChatManager;
use OCA\Talk\Exceptions\RoomNotFoundException;
use OCA\Talk\Manager;
use OCA\Talk\Model\Participant;
use OCA\Talk\Room;
use OCA\Talk\Service\NoteToSelfService;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\RoomService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Talk\IBroker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Test-only subclass overriding the webhook-retry backoff sleep with a no-op,
 * so a unit run exercising `deliverWebhook()`'s retry loop never actually
 * blocks for a real backoff delay (worst case, several seconds per test).
 *
 * @spec openspec/changes/delivery-channels/tasks.md#task-5-deliveryservicedeliverwebhook-hmac-sign-bounded-retry-size-cap
 */
class DeliveryServiceTestable extends DeliveryService
{

    /**
     * Recorded sleep durations (seconds), in call order — asserted against the
     * exponential-backoff formula instead of actually waiting.
     *
     * @var array<int, int>
     */
    public array $sleeps = [];

    /**
     * No-op override: records the requested delay instead of sleeping.
     *
     * @param int $seconds Seconds the real implementation would sleep.
     *
     * @return void
     */
    protected function sleep(int $seconds): void
    {
        $this->sleeps[] = $seconds;

    }//end sleep()
}//end class

/**
 * Tests for the talk-delivery / delivery-channels DeliveryService.
 *
 * @spec openspec/changes/talk-delivery/tasks.md#4-tests
 * @spec openspec/changes/delivery-channels/tasks.md#task-4-deliveryservicedeliveremail-redact-resolve-recipient-send-via-imailer
 * @spec openspec/changes/delivery-channels/tasks.md#task-5-deliveryservicedeliverwebhook-hmac-sign-bounded-retry-size-cap
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
     * Mock user manager (owner IUser resolution for room creation / email fallback).
     *
     * @var IUserManager&MockObject
     */
    private IUserManager $userManager;

    /**
     * Mock mailer (delivery-channels: deliver=email).
     *
     * @var IMailer&MockObject
     */
    private IMailer $mailer;

    /**
     * Mock HTTP client service (delivery-channels: deliver=webhook).
     *
     * @var IClientService&MockObject
     */
    private IClientService $clientService;

    /**
     * Mock schedule webhook-secret service (delivery-channels).
     *
     * @var ScheduleWebhookSecretService&MockObject
     */
    private ScheduleWebhookSecretService $scheduleWebhookSecretService;

    /**
     * Real RedactionService (cheap, pure — no need to mock it).
     *
     * @var RedactionService
     */
    private RedactionService $redactionService;

    /**
     * Service under test (testable subclass — see `DeliveryServiceTestable` above).
     *
     * @var DeliveryServiceTestable
     */
    private DeliveryServiceTestable $service;

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
        $this->mailer                       = $this->createMock(IMailer::class);
        $this->clientService                = $this->createMock(IClientService::class);
        $this->scheduleWebhookSecretService = $this->createMock(ScheduleWebhookSecretService::class);
        $this->redactionService              = new RedactionService(config: $this->stubbedRedactionConfig());
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

        $this->service = new DeliveryServiceTestable(
            notificationManager: $this->notificationManager,
            talkBroker: $this->talkBroker,
            urlGenerator: $this->urlGenerator,
            container: $this->container,
            config: $this->config,
            userManager: $this->userManager,
            mailer: $this->mailer,
            clientService: $this->clientService,
            redactionService: $this->redactionService,
            scheduleWebhookSecretService: $this->scheduleWebhookSecretService,
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
     * A minimal IConfig mock satisfying RedactionService's constructor
     * (reads the frozen `redact_secrets` app setting; defaults it "on").
     *
     * @return IConfig&MockObject
     */
    private function stubbedRedactionConfig(): IConfig
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('yes');

        return $config;

    }//end stubbedRedactionConfig()

    /**
     * Build an IMessage mock whose fluent setters return itself (delivery-channels).
     *
     * @return IMessage&MockObject
     */
    private function messageMock(): IMessage
    {
        $message = $this->createMock(IMessage::class);
        foreach (['setTo', 'setSubject', 'setPlainBody', 'setFrom'] as $setter) {
            $message->method($setter)->willReturnSelf();
        }

        return $message;

    }//end messageMock()

    /**
     * Build an IResponse mock reporting the given HTTP status code.
     *
     * @param int $status The HTTP status code.
     *
     * @return IResponse&MockObject
     */
    private function responseMock(int $status): IResponse
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($status);

        return $response;

    }//end responseMock()

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
     * deliverApprovalRequestForWebhookRun — the webhook-triggered counterpart to
     * deliverApprovalRequest/deliverApprovalRequestForFlowRun — raises one
     * notification per resolved reviewer using the approval's own
     * webhookContext (no Schedule ObjectEntity involved), and never throws for
     * a delivery problem (agent-webhook-trigger).
     *
     * @return void
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-6-deliveryservice-webhook-approval-notification-shared-reviewer-notify-helper
     */
    public function testWebhookRunApprovalRequestNotifiesEachReviewer(): void
    {
        // Talk unavailable → notification-only path.
        $this->talkBroker->method('hasBackend')->willReturn(false);
        $this->notificationManager->method('createNotification')->willReturn($this->notificationMock());
        $this->notificationManager->expects($this->exactly(2))->method('notify');

        $approval = new ObjectEntity();
        $approval->setUuid('appr-webhook-1');
        $approval->setObject(
            [
                'status'         => 'pending',
                'sourceType'     => 'webhook',
                'agentId'        => 'agent-uuid-1',
                'webhookContext' => ['agentId' => 'agent-uuid-1', 'correlationId' => 'corr-1'],
            ]
        );

        $result = $this->service->deliverApprovalRequestForWebhookRun(
            approval: $approval,
            reviewerUids: ['bob', 'carol']
        );

        $this->assertTrue($result->isDelivered(), 'At least one reviewer must be notified.');
        $this->assertNull($result->getWarning(), 'A clean notification run carries no warning.');

    }//end testWebhookRunApprovalRequestNotifiesEachReviewer()

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

    /**
     * deliver=email with an empty deliverTarget emails the owner's own account address.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-email-mvp
     */
    public function testEmailDeliversToOwnerWhenTargetEmpty(): void
    {
        $owner = $this->createMock(IUser::class);
        $owner->method('getEMailAddress')->willReturn('alice@example.org');
        $this->userManager->method('get')->with('alice')->willReturn($owner);

        $this->mailer->method('validateMailAddress')->willReturn(true);
        $message = $this->messageMock();
        $this->mailer->method('createMessage')->willReturn($message);
        $message->expects($this->once())->method('setTo')->with(['alice@example.org'])->willReturnSelf();
        $this->mailer->expects($this->once())->method('send')->willReturn([]);

        $result = $this->service->deliver(
            channel: 'email',
            output: 'Daily briefing text',
            schedule: $this->schedule(['deliver' => 'email', 'deliverTarget' => ''])
        );

        $this->assertTrue($result->isDelivered());
        $this->assertSame('email', $result->getChannel());
        $this->assertNull($result->getWarning());

    }//end testEmailDeliversToOwnerWhenTargetEmpty()

    /**
     * deliver=email with an explicit deliverTarget emails that address instead of the owner's.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-email-mvp
     */
    public function testEmailDeliversToExplicitTarget(): void
    {
        $this->userManager->expects($this->never())->method('get');

        $this->mailer->method('validateMailAddress')->willReturn(true);
        $message = $this->messageMock();
        $this->mailer->method('createMessage')->willReturn($message);
        $message->expects($this->once())->method('setTo')->with(['ops@example.org'])->willReturnSelf();
        $this->mailer->expects($this->once())->method('send')->willReturn([]);

        $result = $this->service->deliver(
            channel: 'email',
            output: 'Weekly digest text',
            schedule: $this->schedule(['deliver' => 'email', 'deliverTarget' => 'ops@example.org'])
        );

        $this->assertTrue($result->isDelivered());
        $this->assertSame('email', $result->getChannel());

    }//end testEmailDeliversToExplicitTarget()

    /**
     * No resolvable email recipient (empty target, owner has no email) degrades
     * gracefully: no send is attempted, a warning is recorded, never thrown.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-email-mvp
     */
    public function testEmailNoRecipientResolvedDegradesGracefully(): void
    {
        $owner = $this->createMock(IUser::class);
        $owner->method('getEMailAddress')->willReturn(null);
        $this->userManager->method('get')->with('alice')->willReturn($owner);

        $this->mailer->expects($this->never())->method('createMessage');

        $result = $this->service->deliver(
            channel: 'email',
            output: 'Daily briefing text',
            schedule: $this->schedule(['deliver' => 'email', 'deliverTarget' => ''])
        );

        $this->assertFalse($result->isDelivered());
        $this->assertSame('email', $result->getChannel());
        $this->assertNotNull($result->getWarning());

    }//end testEmailNoRecipientResolvedDegradesGracefully()

    /**
     * A mailer send() failure is reported as a warning, never thrown (regression:
     * delivery failures are recorded, not fatal).
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-delivery-failures-are-recorded-not-fatal-mvp
     */
    public function testEmailSendFailureIsReportedNotThrown(): void
    {
        $this->mailer->method('validateMailAddress')->willReturn(true);
        $this->mailer->method('createMessage')->willReturn($this->messageMock());
        $this->mailer->method('send')->willThrowException(new \RuntimeException('SMTP down'));

        $result = $this->service->deliver(
            channel: 'email',
            output: 'Daily briefing text',
            schedule: $this->schedule(['deliver' => 'email', 'deliverTarget' => 'ops@example.org'])
        );

        $this->assertFalse($result->isDelivered());
        $this->assertNotNull($result->getWarning());
        $this->assertStringContainsString('SMTP down', (string) $result->getWarning());

    }//end testEmailSendFailureIsReportedNotThrown()

    /**
     * The email body is redacted before it is handed to IMailer.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-output-crossing-the-instance-boundary-is-redacted-before-delivery-mvp
     */
    public function testEmailBodyIsRedactedBeforeSend(): void
    {
        $this->mailer->method('validateMailAddress')->willReturn(true);
        $message = $this->messageMock();
        $this->mailer->method('createMessage')->willReturn($message);
        $message->expects($this->once())
            ->method('setPlainBody')
            ->with($this->callback(static fn (string $body): bool => str_contains($body, 'sk-secret1234567890') === false))
            ->willReturnSelf();
        $this->mailer->method('send')->willReturn([]);

        $result = $this->service->deliver(
            channel: 'email',
            output: 'Here is your key: sk-secret1234567890',
            schedule: $this->schedule(['deliver' => 'email', 'deliverTarget' => 'ops@example.org'])
        );

        $this->assertTrue($result->isDelivered());

    }//end testEmailBodyIsRedactedBeforeSend()

    /**
     * A configured URL and secret result in a signed POST to the webhook target.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-a-signed-outbound-webhook-mvp
     */
    public function testWebhookPostsSignedPayload(): void
    {
        $schedule = $this->schedule(
            ['deliver' => 'webhook', 'deliverTarget' => 'https://sink.example.org/hook', 'agentId' => 'agent-1']
        );
        $this->scheduleWebhookSecretService->method('retrieveSecret')->willReturn('shh-secret');

        $client = $this->createMock(IClient::class);
        $this->clientService->method('newClient')->willReturn($client);

        $client->expects($this->once())
            ->method('post')
            ->with(
                'https://sink.example.org/hook',
                $this->callback(function (array $options): bool {
                    $signature = $options['headers']['X-Hermiq-Signature'] ?? '';
                    $expected  = 'sha256='.hash_hmac('sha256', (string) $options['body'], 'shh-secret');
                    return $signature === $expected && str_contains((string) $options['body'], 'agent-1');
                })
            )
            ->willReturn($this->responseMock(200));

        $result = $this->service->deliver(channel: 'webhook', output: 'Run finished ok', schedule: $schedule);

        $this->assertTrue($result->isDelivered());
        $this->assertSame('webhook', $result->getChannel());
        $this->assertNull($result->getWarning());

    }//end testWebhookPostsSignedPayload()

    /**
     * An empty deliverTarget URL fails closed without attempting any POST.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-a-signed-outbound-webhook-mvp
     */
    public function testWebhookNoUrlFailsClosed(): void
    {
        $this->clientService->expects($this->never())->method('newClient');

        $result = $this->service->deliver(
            channel: 'webhook',
            output: 'Run finished ok',
            schedule: $this->schedule(['deliver' => 'webhook', 'deliverTarget' => ''])
        );

        $this->assertFalse($result->isDelivered());
        $this->assertSame('webhook', $result->getChannel());
        $this->assertNotNull($result->getWarning());

    }//end testWebhookNoUrlFailsClosed()

    /**
     * No signing secret ever minted fails closed without attempting any POST.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-a-signed-outbound-webhook-mvp
     */
    public function testWebhookNoSecretFailsClosed(): void
    {
        $this->scheduleWebhookSecretService->method('retrieveSecret')->willReturn(null);
        $this->clientService->expects($this->never())->method('newClient');

        $result = $this->service->deliver(
            channel: 'webhook',
            output: 'Run finished ok',
            schedule: $this->schedule(['deliver' => 'webhook', 'deliverTarget' => 'https://sink.example.org/hook'])
        );

        $this->assertFalse($result->isDelivered());
        $this->assertSame('webhook', $result->getChannel());
        $this->assertNotNull($result->getWarning());
        $this->assertStringContainsString('signing secret', (string) $result->getWarning());

    }//end testWebhookNoSecretFailsClosed()

    /**
     * A transient failure retries with growing backoff and eventually succeeds.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-webhook-delivery-retries-with-bounded-exponential-backoff-mvp
     */
    public function testWebhookRetriesWithBackoffThenSucceeds(): void
    {
        $schedule = $this->schedule(
            [
                'deliver'                          => 'webhook',
                'deliverTarget'                     => 'https://sink.example.org/hook',
                'deliverWebhookMaxAttempts'         => 3,
                'deliverWebhookBackoffBaseSeconds'  => 2,
            ]
        );
        $this->scheduleWebhookSecretService->method('retrieveSecret')->willReturn('shh-secret');

        $client = $this->createMock(IClient::class);
        $this->clientService->method('newClient')->willReturn($client);
        $client->expects($this->exactly(3))
            ->method('post')
            ->willReturnOnConsecutiveCalls(
                $this->responseMock(500),
                $this->responseMock(500),
                $this->responseMock(200)
            );

        $result = $this->service->deliver(channel: 'webhook', output: 'Run finished ok', schedule: $schedule);

        $this->assertTrue($result->isDelivered());
        $this->assertNull($result->getWarning());
        $this->assertSame([2, 4], $this->service->sleeps);

    }//end testWebhookRetriesWithBackoffThenSucceeds()

    /**
     * A webhook retry budget exhaustion makes exactly maxAttempts attempts and
     * records a warning, never fails the run.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-webhook-delivery-retries-with-bounded-exponential-backoff-mvp
     */
    public function testWebhookRetryBudgetExhaustedRecordsWarning(): void
    {
        $schedule = $this->schedule(
            ['deliver' => 'webhook', 'deliverTarget' => 'https://sink.example.org/hook', 'deliverWebhookMaxAttempts' => 3]
        );
        $this->scheduleWebhookSecretService->method('retrieveSecret')->willReturn('shh-secret');

        $client = $this->createMock(IClient::class);
        $this->clientService->method('newClient')->willReturn($client);
        $client->expects($this->exactly(3))->method('post')->willReturn($this->responseMock(500));

        $result = $this->service->deliver(channel: 'webhook', output: 'Run finished ok', schedule: $schedule);

        $this->assertFalse($result->isDelivered());
        $this->assertSame('webhook', $result->getChannel());
        $this->assertNotNull($result->getWarning());

    }//end testWebhookRetryBudgetExhaustedRecordsWarning()

    /**
     * An oversized output is truncated before signing; the received body stays
     * within the size cap and the signature verifies over the exact truncated bytes.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-webhook-payload-is-size-capped-before-it-is-signed-and-sent-mvp
     */
    public function testWebhookTruncatesOversizedOutputBeforeSigning(): void
    {
        $schedule = $this->schedule(
            ['deliver' => 'webhook', 'deliverTarget' => 'https://sink.example.org/hook']
        );
        $this->scheduleWebhookSecretService->method('retrieveSecret')->willReturn('shh-secret');

        $client = $this->createMock(IClient::class);
        $this->clientService->method('newClient')->willReturn($client);

        $capturedBody      = '';
        $capturedSignature = '';
        $client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (array $options) use (&$capturedBody, &$capturedSignature): bool {
                    $capturedBody      = (string) $options['body'];
                    $capturedSignature = (string) ($options['headers']['X-Hermiq-Signature'] ?? '');
                    return true;
                })
            )
            ->willReturn($this->responseMock(200));

        $hugeOutput = str_repeat('a', 100000);
        $result     = $this->service->deliver(channel: 'webhook', output: $hugeOutput, schedule: $schedule);

        $this->assertTrue($result->isDelivered());
        $this->assertLessThanOrEqual(65536, strlen($capturedBody));
        $this->assertStringContainsString('[truncated]', $capturedBody);
        $this->assertSame('sha256='.hash_hmac('sha256', $capturedBody, 'shh-secret'), $capturedSignature);

    }//end testWebhookTruncatesOversizedOutputBeforeSigning()

    /**
     * The webhook payload's output field is redacted before it is signed/sent.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-output-crossing-the-instance-boundary-is-redacted-before-delivery-mvp
     */
    public function testWebhookPayloadIsRedactedBeforeSend(): void
    {
        $schedule = $this->schedule(
            ['deliver' => 'webhook', 'deliverTarget' => 'https://sink.example.org/hook']
        );
        $this->scheduleWebhookSecretService->method('retrieveSecret')->willReturn('shh-secret');

        $client = $this->createMock(IClient::class);
        $this->clientService->method('newClient')->willReturn($client);
        $client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(static fn (array $options): bool => str_contains((string) $options['body'], 'sk-secret1234567890') === false)
            )
            ->willReturn($this->responseMock(200));

        $result = $this->service->deliver(
            channel: 'webhook',
            output: 'Here is your key: sk-secret1234567890',
            schedule: $schedule
        );

        $this->assertTrue($result->isDelivered());

    }//end testWebhookPayloadIsRedactedBeforeSend()
}//end class
