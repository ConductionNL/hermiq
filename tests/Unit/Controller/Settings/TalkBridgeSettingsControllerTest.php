<?php

/**
 * Unit tests for TalkBridgeSettingsController.
 *
 * Describing the Talk bridge and binding a room to an agent: a missing room token is a 400 before anything is bound, inputs are trimmed, and a failure is a logged 500.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller\Settings;

use OCA\Hermiq\Controller\Settings\TalkBridgeSettingsController;
use OCA\Hermiq\Service\Talk\TalkAgentBinding;
use OCA\Hermiq\Service\Talk\TalkBridgeStatus;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An admin reads the Talk bridge state and binds rooms to agents.
 */
#[CoversClass(TalkBridgeSettingsController::class)]
final class TalkBridgeSettingsControllerTest extends TestCase {

	private TalkBridgeStatus&MockObject $status;
	private TalkAgentBinding&MockObject $binding;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->status  = $this->createMock(TalkBridgeStatus::class);
		$this->binding = $this->createMock(TalkAgentBinding::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A request whose getParam() answers from $params, else the given default.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return IRequest
	 */
	private function request(array $params = []): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => array_key_exists($key, $params) ? $params[$key] : $default
		);
		return $request;
	}//end request()

	/**
	 * The controller under test.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return TalkBridgeSettingsController
	 */
	private function controller(array $params = []): TalkBridgeSettingsController {
		return new TalkBridgeSettingsController($this->request($params), $this->status, $this->binding, $this->logger);
	}//end controller()

	public function testGetReturnsTheBridgeDescription(): void {
		$this->status->method('describe')->willReturn(['enabled' => true, 'rooms' => []]);

		$response = $this->controller()->get();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['enabled' => true, 'rooms' => []], $response->getData());
	}//end testGetReturnsTheBridgeDescription()

	public function testGetFailureIsALogged500(): void {
		$this->status->method('describe')->willThrowException(new RuntimeException('talk missing'));
		$this->logger->expects($this->once())->method('error')->with(
			'[TalkBridgeSettingsController] Failed to describe the Talk bridge',
			$this->callback(static fn (array $context): bool => $context['error'] === 'talk missing')
		);

		$response = $this->controller()->get();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to read the Talk bridge configuration'], $response->getData());
	}//end testGetFailureIsALogged500()

	public function testBindRoomRequiresAToken(): void {
		$this->binding->expects($this->never())->method('bindRoom');

		$response = $this->controller(['token' => '   ', 'agentId' => 'a1'])->bindRoom();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'A room token is required'], $response->getData());
	}//end testBindRoomRequiresAToken()

	public function testBindRoomBindsTheTrimmedTokenAndAgentAndReturnsTheNewState(): void {
		$this->binding->expects($this->once())->method('bindRoom')->with('room1', 'agent-1');
		$this->status->method('describe')->willReturn(['rooms' => ['room1' => 'agent-1']]);

		$response = $this->controller(['token' => ' room1 ', 'agentId' => ' agent-1 '])->bindRoom();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['rooms' => ['room1' => 'agent-1']], $response->getData());
	}//end testBindRoomBindsTheTrimmedTokenAndAgentAndReturnsTheNewState()

	public function testBindRoomWithoutAnAgentPassesAnEmptyAgent(): void {
		$this->binding->expects($this->once())->method('bindRoom')->with('room1', '');
		$this->status->method('describe')->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $this->controller(['token' => 'room1'])->bindRoom()->getStatus());
	}//end testBindRoomWithoutAnAgentPassesAnEmptyAgent()

	public function testBindRoomFailureIsALogged500(): void {
		$this->binding->method('bindRoom')->willThrowException(new RuntimeException('no such room'));
		$this->logger->expects($this->once())->method('error')->with('[TalkBridgeSettingsController] Failed to bind a room to an agent', $this->anything());

		$response = $this->controller(['token' => 'room1', 'agentId' => 'a'])->bindRoom();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to update the room binding'], $response->getData());
	}//end testBindRoomFailureIsALogged500()
}//end class
