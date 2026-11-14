<?php

/**
 * Unit tests for DeliveryService edges: an unknown channel, the email recipient
 * resolution and partial-send paths, a webhook whose POST throws against a
 * host-less target, and a webhook-run approval with a malformed context.
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
 * @spec openspec/specs/talk-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\ApprovalRound;
use OCA\Hermiq\Service\ApprovalWording;
use OCA\Hermiq\Service\RecipientNotice;
use OCA\Hermiq\Service\Connection\ConnectionReporter;
use OCA\Hermiq\Service\DeliveryResult;
use OCA\Hermiq\Service\DeliveryService;
use OCA\Hermiq\Service\Redaction\AssignmentRedactor;
use OCA\Hermiq\Service\Redaction\CredentialRedactor;
use OCA\Hermiq\Service\Redaction\FormBodyRedactor;
use OCA\Hermiq\Service\Redaction\PhoneRedactor;
use OCA\Hermiq\Service\Redaction\VendorKeyRedactor;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ScheduleDelivery;
use OCA\Hermiq\Service\ScheduleWebhookSecretService;
use OCA\Hermiq\Service\Talk\TalkApprovalNotifier;
use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Talk\IBroker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the DeliveryService edge paths.
 *
 * @spec openspec/specs/talk-delivery/spec.md
 */
#[CoversClass(DeliveryService::class)]
#[UsesClass(DeliveryResult::class)]
#[UsesClass(ScheduleDelivery::class)]
#[UsesClass(RedactionService::class)]
#[UsesClass(AssignmentRedactor::class)]
#[UsesClass(CredentialRedactor::class)]
#[UsesClass(FormBodyRedactor::class)]
#[UsesClass(PhoneRedactor::class)]
#[UsesClass(VendorKeyRedactor::class)]
#[UsesClass(ApprovalRound::class)]
#[UsesClass(ApprovalWording::class)]
#[UsesClass(RecipientNotice::class)]
class DeliveryServiceEdgesTest extends TestCase {

	/**
	 * Mock mailer.
	 *
	 * @var IMailer&MockObject
	 */
	private IMailer $mailer;

	/**
	 * Mock user manager.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager $userManager;

	/**
	 * Mock HTTP client.
	 *
	 * @var IClient&MockObject
	 */
	private IClient $client;

	/**
	 * Mock notification manager.
	 *
	 * @var INotificationManager&MockObject
	 */
	private INotificationManager $notificationManager;

	/**
	 * Every throttled report sent, as [key, status, message].
	 *
	 * @var array<int, array{0: string, 1: string, 2: string}>
	 */
	private array $reports = [];

	/**
	 * The service under test.
	 *
	 * @var DeliveryService
	 */
	private DeliveryService $service;

	/**
	 * Wire the boundaries and build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mailer = $this->createMock(IMailer::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->client = $this->createMock(IClient::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->reports = [];

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);

		$secrets = $this->createMock(ScheduleWebhookSecretService::class);
		$secrets->method('retrieveSecret')->willReturn('shh');

		$redactionConfig = $this->createMock(IConfig::class);
		$redactionConfig->method('getAppValue')->willReturn('yes');

		$reporter = $this->getMockBuilder(ConnectionReporter::class)
			->disableOriginalConstructor()
			->onlyMethods(['reportThrottled'])
			->getMock();
		$reporter->method('reportThrottled')->willReturnCallback(
			function (string $key, string $status, string $message = ''): bool {
				$this->reports[] = [$key, $status, $message];
				return true;
			}
		);

		$talkBroker = $this->createMock(IBroker::class);
		$talkBroker->method('hasBackend')->willReturn(false);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/apps/hermiq/');
		$urlGenerator->method('getAbsoluteURL')->willReturnArgument(0);

		$this->service = new DeliveryService(
			notificationManager: $this->notificationManager,
			talkBroker: $talkBroker,
			urlGenerator: $urlGenerator,
			container: $this->createMock(ContainerInterface::class),
			config: $this->createMock(IConfig::class),
			userManager: $this->userManager,
			mailer: $this->mailer,
			clientService: $clientService,
			redactionService: new RedactionService(config: $redactionConfig),
			scheduleWebhookSecretService: $secrets,
			logger: $this->createMock(LoggerInterface::class),
			talkRoomBinding: $this->createMock(TalkRoomBinding::class),
			talkApprovalNotifier: $this->createMock(TalkApprovalNotifier::class),
			connectionReporter: $reporter,
		);
	}//end setUp()

	/**
	 * A schedule with the given payload and owner.
	 *
	 * @param array<string, mixed> $payload The schedule body.
	 * @param string $owner The owner uid ('' for none).
	 *
	 * @return ObjectEntity
	 */
	private function schedule(array $payload, string $owner = 'alice'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('11111111-1111-1111-1111-111111111111');
		$entity->setOwner($owner);
		$entity->setObject($payload);
		return $entity;
	}//end schedule()

	/**
	 * An unknown channel is reported as a warning on that channel, never delivered.
	 *
	 * @return void
	 */
	public function testUnknownChannelIsAWarningNotAFailure(): void {
		$result = $this->service->deliver(channel: 'pigeon', output: 'hello', schedule: $this->schedule([]));

		$this->assertFalse($result->isDelivered());
		$this->assertSame('pigeon', $result->getChannel());
		$this->assertSame("Unknown delivery channel 'pigeon'", $result->getWarning());
	}//end testUnknownChannelIsAWarningNotAFailure()

	/**
	 * With no explicit target and no owner, email has no recipient and no user lookup runs.
	 *
	 * @return void
	 */
	public function testEmailWithoutTargetOrOwnerHasNoRecipient(): void {
		$this->userManager->expects($this->never())->method('get');
		$this->mailer->expects($this->never())->method('send');

		$result = $this->service->deliver(channel: 'email', output: 'report', schedule: $this->schedule([], ''));

		$this->assertFalse($result->isDelivered());
		$this->assertSame('No email recipient could be resolved', $result->getWarning());
	}//end testEmailWithoutTargetOrOwnerHasNoRecipient()

	/**
	 * An owner who no longer exists resolves no recipient.
	 *
	 * @return void
	 */
	public function testEmailForUnknownOwnerHasNoRecipient(): void {
		$this->userManager->expects($this->once())->method('get')->with('ghost')->willReturn(null);
		$this->mailer->expects($this->never())->method('send');

		$result = $this->service->deliver(channel: 'email', output: 'report', schedule: $this->schedule([], 'ghost'));

		$this->assertSame('No email recipient could be resolved', $result->getWarning());
	}//end testEmailForUnknownOwnerHasNoRecipient()

	/**
	 * A send that reports failed recipients is not delivered and names them.
	 *
	 * @return void
	 */
	public function testEmailWithFailedRecipientsIsNotDelivered(): void {
		$message = $this->createMock(IMessage::class);
		foreach (['setTo', 'setSubject', 'setPlainBody'] as $setter) {
			$message->method($setter)->willReturnSelf();
		}

		$message->expects($this->once())->method('setSubject')->with('[Hermiq] Weekly digest');
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->expects($this->once())->method('send')->with($message)->willReturn(['bob@example.org']);

		$result = $this->service->deliver(
			channel: 'email',
			output: 'report',
			schedule: $this->schedule(['name' => 'Weekly digest', 'deliverTarget' => 'bob@example.org'])
		);

		$this->assertFalse($result->isDelivered());
		$this->assertSame('email', $result->getChannel());
		$this->assertSame('Email send reported failed recipients: bob@example.org', $result->getWarning());
	}//end testEmailWithFailedRecipientsIsNotDelivered()

	/**
	 * A POST that throws carries the exception message into the warning, and a target
	 * without a host is reported as "its target".
	 *
	 * @return void
	 */
	public function testThrowingWebhookPostAgainstHostlessTarget(): void {
		$this->client->expects($this->once())->method('post')
			->with('not-a-url', $this->anything())
			->willThrowException(new RuntimeException('connection refused'));

		$result = $this->service->deliver(
			channel: 'webhook',
			output: 'done',
			schedule: $this->schedule(['deliverTarget' => 'not-a-url', 'agentId' => 'agent-1', 'deliverWebhookMaxAttempts' => 1])
		);

		$this->assertFalse($result->isDelivered());
		$this->assertSame('Webhook delivery failed after 1 attempt(s): connection refused', $result->getWarning());
		$this->assertSame(
			[['webhook-delivery', 'error', 'The last webhook delivery to its target failed after 1 attempt(s).']],
			$this->reports
		);
	}//end testThrowingWebhookPostAgainstHostlessTarget()

	/**
	 * A webhook-run approval whose context is not an array still notifies the reviewer,
	 * with an empty correlation id and the agent id as display name.
	 *
	 * @return void
	 */
	public function testWebhookRunApprovalWithMalformedContext(): void {
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setLink'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}

		$notification->expects($this->once())->method('setSubject')
			->with('approval_requested', ['name' => 'agent-7'])->willReturnSelf();
		$notification->expects($this->once())->method('setMessage')
			->with('approval_summary', ['correlationId' => ''])->willReturnSelf();
		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects($this->once())->method('notify')->with($notification);

		$approval = new ObjectEntity();
		$approval->setUuid('appr-1');
		$approval->setObject(['agentId' => 'agent-7', 'webhookContext' => 'garbage']);

		$result = $this->service->deliverApprovalRequestForWebhookRun($approval, ['carol']);

		$this->assertTrue($result->isDelivered());
		$this->assertNull($result->getWarning());
	}//end testWebhookRunApprovalWithMalformedContext()
}//end class
