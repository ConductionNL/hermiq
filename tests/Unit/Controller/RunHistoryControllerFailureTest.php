<?php

/**
 * RunHistoryController when the run-history store fails: the history and trace reads answer 500 and log the cause.
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

use OCA\Hermiq\Controller\RunHistoryController;
use OCA\Hermiq\Service\RunHistoryService;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Controller\RunHistoryController
 */
class RunHistoryControllerFailureTest extends TestCase {

	/**
	 * @var RunHistoryService&MockObject
	 */
	private RunHistoryService $history;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->history = $this->createMock(RunHistoryService::class);
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

	private function controller(): RunHistoryController {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn(self::owned('sched-1'));
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $default);

		return new RunHistoryController($request, $objects, $this->session(), $this->history, $this->createMock(ScheduleService::class), $this->logger);
	}//end controller()

	public function testAFailingHistoryReadIsA500(): void {
		$this->history->expects($this->once())->method('getRunHistory')->willThrowException(new RuntimeException('index gone'));
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('index gone'), $this->arrayHasKey('exception'));

		$response = $this->controller()->index('sched-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not load run history'], $response->getData());
	}//end testAFailingHistoryReadIsA500()

	public function testAFailingTraceReadIsA500(): void {
		$this->history->expects($this->once())->method('getRunTrace')->with('sched-1', 'run-9')->willThrowException(new RuntimeException('index gone'));
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('index gone'), $this->arrayHasKey('exception'));

		$response = $this->controller()->trace('sched-1', 'run-9');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not load run trace'], $response->getData());
	}//end testAFailingTraceReadIsA500()
}//end class
