<?php

/**
 * ScheduleWebhookSecretController when minting or rotating fails unexpectedly: a 500 with its own message, the cause logged.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use Error;
use OCA\Hermiq\Controller\ScheduleWebhookSecretController;
use OCA\Hermiq\Service\ScheduleWebhookSecretService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Hermiq\Controller\ScheduleWebhookSecretController
 */
class ScheduleWebhookSecretControllerFailureTest extends TestCase {

	/**
	 * @var ScheduleWebhookSecretService&MockObject
	 */
	private ScheduleWebhookSecretService $secrets;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->secrets = $this->createMock(ScheduleWebhookSecretService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function session(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	private static function owned(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner('alice');
		$entity->setObject(['name' => 'x']);
		return $entity;
	}//end owned()

	private function controller(ObjectEntity $schedule): ScheduleWebhookSecretController {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($schedule);

		return new ScheduleWebhookSecretController($this->createMock(IRequest::class), $objects, $this->session(), $this->secrets, $this->logger);
	}//end controller()

	public function testAnUnexpectedMintFailureIsA500(): void {
		$schedule = self::owned('sched-1');
		$this->secrets->expects($this->once())->method('mint')->with($schedule)->willThrowException(new Error('crypto missing'));
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('crypto missing'), $this->arrayHasKey('exception'));

		$response = $this->controller($schedule)->create('sched-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not mint webhook secret'], $response->getData());
	}//end testAnUnexpectedMintFailureIsA500()

	public function testAnUnexpectedRotateFailureIsA500(): void {
		$schedule = self::owned('sched-1');
		$this->secrets->expects($this->once())->method('rotate')->with($schedule)->willThrowException(new Error('store down'));
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('store down'), $this->arrayHasKey('exception'));

		$response = $this->controller($schedule)->rotate('sched-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not rotate webhook secret'], $response->getData());
	}//end testAnUnexpectedRotateFailureIsA500()
}//end class
