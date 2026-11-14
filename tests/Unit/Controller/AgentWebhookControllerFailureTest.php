<?php

/**
 * AgentWebhookController when the webhook service fails: each route answers 500 with its own message and logs the cause.
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
use OCA\Hermiq\Controller\AgentWebhookController;
use OCA\Hermiq\Service\WebhookSecretService;
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
 * @covers \OCA\Hermiq\Controller\AgentWebhookController
 */
class AgentWebhookControllerFailureTest extends TestCase {

	/**
	 * @var WebhookSecretService&MockObject
	 */
	private WebhookSecretService $webhooks;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->webhooks = $this->createMock(WebhookSecretService::class);
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

	private function controller(): AgentWebhookController {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn(self::owned('agent-1'));
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['enabled' => false]);

		return new AgentWebhookController($request, $objects, $this->session(), $this->webhooks, $this->logger);
	}//end controller()

	private function expectLogged(string $cause): void {
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains($cause), $this->arrayHasKey('exception'));
	}//end expectLogged()

	public function testAnUnexpectedCreateFailureIsA500(): void {
		$this->webhooks->expects($this->once())->method('create')->with('agent-1', 'alice')->willThrowException(new Error('crypto missing'));
		$this->expectLogged(cause: 'crypto missing');

		$response = $this->controller()->create('agent-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not create webhook'], $response->getData());
	}//end testAnUnexpectedCreateFailureIsA500()

	public function testAFailingRotateIsA500(): void {
		$webhook = self::owned('hook-1');
		$this->webhooks->method('findForAgent')->with('agent-1')->willReturn($webhook);
		$this->webhooks->expects($this->once())->method('rotate')->with($webhook)->willThrowException(new Error('store down'));
		$this->expectLogged(cause: 'store down');

		$response = $this->controller()->rotate('agent-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not rotate webhook'], $response->getData());
	}//end testAFailingRotateIsA500()

	public function testAFailingRevokeIsA500(): void {
		$webhook = self::owned('hook-1');
		$this->webhooks->method('findForAgent')->willReturn($webhook);
		$this->webhooks->expects($this->once())->method('revoke')->with($webhook)->willThrowException(new Error('store down'));
		$this->webhooks->expects($this->never())->method('status');
		$this->expectLogged(cause: 'store down');

		$response = $this->controller()->revoke('agent-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not revoke webhook'], $response->getData());
	}//end testAFailingRevokeIsA500()

	public function testAFailingPatchIsA500AndPassesTheRequestFields(): void {
		$webhook = self::owned('hook-1');
		$this->webhooks->method('findForAgent')->willReturn($webhook);
		$this->webhooks->expects($this->once())->method('patch')->with($webhook, ['enabled' => false])->willThrowException(new Error('store down'));
		$this->expectLogged(cause: 'store down');

		$response = $this->controller()->patch('agent-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not update webhook'], $response->getData());
	}//end testAFailingPatchIsA500AndPassesTheRequestFields()
}//end class
