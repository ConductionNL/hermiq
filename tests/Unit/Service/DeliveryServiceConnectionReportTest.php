<?php

/**
 * Webhook delivery and the throttled report it sends to integriq.
 *
 * A webhook delivery is the one moment hermiq meets a webhook target. The report
 * must say whether the POST landed, name the host and never the full URL, and
 * leave the delivery result exactly as it was. A schedule with no target or no
 * secret never attempted a POST, so it must report nothing.
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
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-hermiq-reports-what-only-it-can-observe-req-hermiq-conn-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\Connection\ConnectionReporter;
use OCA\Hermiq\Service\DeliveryService;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ScheduleWebhookSecretService;
use OCA\Hermiq\Service\Talk\TalkApprovalNotifier;
use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use OCP\Talk\IBroker;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the webhook delivery connection report.
 *
 * @covers \OCA\Hermiq\Service\DeliveryService
 */
class DeliveryServiceConnectionReportTest extends TestCase {

	/**
	 * Every throttled report sent, as [key, status, message].
	 *
	 * @var array<int, array{0: string, 1: string, 2: string}>
	 */
	private array $reports = [];

	/**
	 * A delivery service whose webhook POSTs answer with the given statuses, backoff skipped.
	 *
	 * @param array<int, int> $statuses One HTTP status per POST attempt.
	 * @param string|null     $secret   The schedule's signing secret, or null for none.
	 *
	 * @return DeliveryService
	 */
	private function service(array $statuses, ?string $secret = 'shh'): DeliveryService {
		$responses = array_map(
			function (int $status): IResponse {
				$response = $this->createMock(originalClassName: IResponse::class);
				$response->method('getStatusCode')->willReturn($status);
				return $response;
			},
			$statuses
		);
		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('post')->willReturnOnConsecutiveCalls(...$responses);
		$clientService = $this->createMock(originalClassName: IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$secrets = $this->createMock(originalClassName: ScheduleWebhookSecretService::class);
		$secrets->method('retrieveSecret')->willReturn($secret);

		$redactionConfig = $this->createMock(originalClassName: IConfig::class);
		$redactionConfig->method('getAppValue')->willReturn('yes');

		$reporter = $this->getMockBuilder(className: ConnectionReporter::class)
			->disableOriginalConstructor()
			->onlyMethods(['reportThrottled'])
			->getMock();
		$reporter->method('reportThrottled')->willReturnCallback(
			function (string $key, string $status, string $message = ''): bool {
				$this->reports[] = [$key, $status, $message];
				return true;
			}
		);

		return new class(
			notificationManager: $this->createMock(originalClassName: INotificationManager::class),
			talkBroker: $this->createMock(originalClassName: IBroker::class),
			urlGenerator: $this->createMock(originalClassName: IURLGenerator::class),
			container: $this->createMock(originalClassName: ContainerInterface::class),
			config: $this->createMock(originalClassName: IConfig::class),
			userManager: $this->createMock(originalClassName: IUserManager::class),
			mailer: $this->createMock(originalClassName: IMailer::class),
			clientService: $clientService,
			redactionService: new RedactionService(config: $redactionConfig),
			scheduleWebhookSecretService: $secrets,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			talkRoomBinding: $this->createMock(originalClassName: TalkRoomBinding::class),
			talkApprovalNotifier: $this->createMock(originalClassName: TalkApprovalNotifier::class),
			connectionReporter: $reporter,
		) extends DeliveryService {

			/**
			 * No backoff in a unit run.
			 *
			 * @param int $seconds Ignored.
			 *
			 * @return void
			 */
			protected function sleep(int $seconds): void {
			}//end sleep()
		};
	}//end service()

	/**
	 * A webhook schedule with the given target.
	 *
	 * @param string $target The deliverTarget URL.
	 *
	 * @return ObjectEntity
	 */
	private function schedule(string $target): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('00000000-0000-0000-0000-000000000000');
		$entity->setOwner('alice');
		$entity->setObject(
			[
				'deliver' => 'webhook',
				'deliverTarget' => $target,
				'agentId' => 'agent-1',
				'deliverWebhookMaxAttempts' => 2,
			]
		);
		return $entity;
	}//end schedule()

	/**
	 * A delivered webhook reports configured, naming the host and not the token in the URL.
	 *
	 * @return void
	 */
	public function testADeliveredWebhookReportsConfigured(): void {
		$result = $this->service(statuses: [500, 204])->deliver(
			channel: 'webhook',
			output: 'Run finished ok',
			schedule: $this->schedule(target: 'https://hooks.example.org/in?token=s3cret')
		);

		$this->assertTrue(condition: $result->isDelivered());
		$this->assertCount(expectedCount: 1, haystack: $this->reports);
		$this->assertSame(expected: ['webhook-delivery', 'configured'], actual: array_slice($this->reports[0], 0, 2));
		$this->assertStringContainsString(needle: 'hooks.example.org', haystack: $this->reports[0][2]);
		$this->assertStringNotContainsString(needle: 's3cret', haystack: $this->reports[0][2]);
	}//end testADeliveredWebhookReportsConfigured()

	/**
	 * A webhook that fails every attempt reports an error with the attempts, and the result is unchanged.
	 *
	 * @return void
	 */
	public function testAFailedWebhookReportsAnError(): void {
		$result = $this->service(statuses: [500, 500])->deliver(
			channel: 'webhook',
			output: 'Run finished ok',
			schedule: $this->schedule(target: 'https://hooks.example.org/in')
		);

		$this->assertFalse(condition: $result->isDelivered());
		$this->assertStringContainsString(needle: 'failed after 2 attempt(s)', haystack: (string)$result->getWarning());
		$this->assertSame(expected: ['webhook-delivery', 'error'], actual: array_slice($this->reports[0], 0, 2));
		$this->assertStringContainsString(needle: 'hooks.example.org failed after 2 attempt(s)', haystack: $this->reports[0][2]);
	}//end testAFailedWebhookReportsAnError()

	/**
	 * A schedule without a secret never posted, so nothing is reported.
	 *
	 * @return void
	 */
	public function testAScheduleWithoutASecretReportsNothing(): void {
		$result = $this->service(statuses: [], secret: null)->deliver(
			channel: 'webhook',
			output: 'Run finished ok',
			schedule: $this->schedule(target: 'https://hooks.example.org/in')
		);

		$this->assertFalse(condition: $result->isDelivered());
		$this->assertSame(expected: [], actual: $this->reports);
	}//end testAScheduleWithoutASecretReportsNothing()
}//end class
