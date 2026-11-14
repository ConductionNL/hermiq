<?php

/**
 * AgentVersionController when its service or the agent lookup fails: the answer each route gives and what it logs.
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

use OCA\Hermiq\Controller\AgentVersionController;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\AgentVersionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Controller\AgentVersionController
 * @uses \OCA\Hermiq\Service\AgentAccessService
 */
class AgentVersionControllerFailureTest extends TestCase {

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * @var AgentVersionService&MockObject
	 */
	private AgentVersionService $versions;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->versions = $this->createMock(AgentVersionService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function ownedAgent(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('agent-1');
		$entity->setOwner('alice');
		$entity->setObject(['name' => 'Support triage', 'isPrivate' => true]);
		return $entity;
	}//end ownedAgent()

	/**
	 * @param array<string,mixed> $params The request's parameters.
	 */
	private function controller(array $params = []): AgentVersionController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		return new AgentVersionController(
			$request,
			$this->objectService,
			$this->versions,
			$session,
			$this->logger,
			new AgentAccessService($this->objectService, $this->createMock(LoggerInterface::class), $this->createMock(IGroupManager::class))
		);
	}//end controller()

	public function testAFailingHistoryReadIsA500ThatIsLogged(): void {
		$this->objectService->method('find')->willReturn($this->ownedAgent());
		$this->versions->method('listVersions')->willThrowException(new RuntimeException('store down'));
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('store down'), $this->arrayHasKey('exception'));

		$response = $this->controller()->index('agent-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not load version history'], $response->getData());
	}//end testAFailingHistoryReadIsA500ThatIsLogged()

	public function testALookupThatFindsNoEntityIsNotFound(): void {
		$this->objectService->method('find')->willReturn(null);
		$this->versions->expects($this->never())->method('listVersions');

		$response = $this->controller()->index('agent-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Agent not found'], $response->getData());
	}//end testALookupThatFindsNoEntityIsNotFound()

	public function testAFailingDiffIsA400ThatIsLogged(): void {
		$this->objectService->method('find')->willReturn($this->ownedAgent());
		$this->versions->expects($this->once())->method('diff')
			->with('agent-1', 'v1', 'v2')
			->willThrowException(new RuntimeException('unknown version'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('unknown version'), $this->arrayHasKey('exception'));

		$response = $this->controller(['from' => 'v1', 'to' => 'v2'])->diff('agent-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Could not diff the requested versions'], $response->getData());
	}//end testAFailingDiffIsA400ThatIsLogged()

	public function testAFailingRollbackIsA400ThatIsLogged(): void {
		$this->objectService->method('find')->willReturn($this->ownedAgent());
		$this->versions->expects($this->once())->method('rollback')
			->with('agent-1', 'v1')
			->willThrowException(new RuntimeException('snapshot unreadable'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('snapshot unreadable'), $this->arrayHasKey('exception'));

		$response = $this->controller()->rollback('agent-1', 'v1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Could not roll back to the requested version'], $response->getData());
	}//end testAFailingRollbackIsA400ThatIsLogged()
}//end class
