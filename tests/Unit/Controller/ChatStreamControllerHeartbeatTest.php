<?php

/**
 * Unit tests for the ChatStreamController heartbeat-interleave ticker
 * (agent-engine-port, ported from OR's ChatStreamControllerHeartbeatTest).
 *
 * Extends the TestableChatStreamController capture pattern with a fake-clock
 * override of the protected now() hook so tests can drive controllable
 * wall-clock advances without a real timer. Drives forwardWithHeartbeat()
 * directly (protected → subclass exposes a public proxy) to isolate the
 * ticker logic from the full stream() entry flow.
 *
 * Acceptance (ADR-034 design D3, 15s threshold):
 *  - three token frames at +7s / +8s / +7s gaps → 0 interleaved heartbeats
 *  - one token at +20s gap → 1 interleaved heartbeat
 *  - two tokens at +20s gaps → 2 interleaved heartbeats
 *  - a tool_call frame triggers the same interleave
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ChatStreamController;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Subclass capturing SSE frames + driving a fake clock for now().
 */
class HeartbeatTestableChatStreamController extends ChatStreamController {

	/**
	 * Captured SSE frames in emit order.
	 *
	 * @var array<int, array{type: string, payload: array}>
	 */
	public array $capturedEvents = [];

	/**
	 * Controllable wall-clock value returned by now(). Tests advance it
	 * between forwardWithHeartbeat() calls.
	 *
	 * @var float
	 */
	public float $fakeNow = 0.0;

	/**
	 * Return the fake clock.
	 *
	 * @return float
	 */
	protected function now(): float {
		return $this->fakeNow;
	}//end now()

	/**
	 * Capture the frame in-memory instead of echoing.
	 *
	 * @param string $eventType Event type.
	 * @param array $payload Frame payload.
	 *
	 * @return void
	 */
	protected function emitSseEvent(string $eventType, array $payload): void {
		$this->capturedEvents[] = ['type' => $eventType, 'payload' => $payload];

	}//end emitSseEvent()

	/**
	 * Expose the protected forwardWithHeartbeat for direct testing.
	 *
	 * @param string $eventType Frame type.
	 * @param array $payload Frame payload.
	 *
	 * @return void
	 */
	public function forward(string $eventType, array $payload): void {
		$this->forwardWithHeartbeat(eventType: $eventType, payload: $payload);

	}//end forward()

	/**
	 * Expose the private $lastEventAt so tests can seed it after the
	 * "initial heartbeat" moment without going through the full stream()
	 * setup.
	 *
	 * @param float $value The seed value.
	 *
	 * @return void
	 */
	public function seedLastEventAt(float $value): void {
		$reflection = new ReflectionClass(ChatStreamController::class);
		$prop = $reflection->getProperty('lastEventAt');
		$prop->setAccessible(true);
		$prop->setValue($this, $value);

	}//end seedLastEventAt()
}//end class

/**
 * Tests for the agent-engine-port heartbeat interleave.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
 */
class ChatStreamControllerHeartbeatTest extends TestCase {

	/**
	 * Build the testable controller with inert mocks.
	 *
	 * @return HeartbeatTestableChatStreamController
	 */
	private function makeController(): HeartbeatTestableChatStreamController {
		return new HeartbeatTestableChatStreamController(
			$this->createMock(IRequest::class),
			$this->createMock(Engine::class),
			$this->createMock(ObjectService::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IL10N::class)
		);

	}//end makeController()

	/**
	 * Sub-15s gaps never interleave a heartbeat.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
	 */
	public function testSubFifteenSecondGapsEmitNoInterleavedHeartbeat(): void {
		$controller = $this->makeController();
		$controller->fakeNow = 100.0;
		$controller->seedLastEventAt(100.0);

		// +7s.
		$controller->fakeNow = 107.0;
		$controller->forward(eventType: 'token', payload: ['delta' => 'a']);

		// +8s.
		$controller->fakeNow = 115.0;
		$controller->forward(eventType: 'token', payload: ['delta' => 'b']);

		// +7s.
		$controller->fakeNow = 122.0;
		$controller->forward(eventType: 'token', payload: ['delta' => 'c']);

		$types = array_column($controller->capturedEvents, 'type');
		$this->assertSame(
			['token', 'token', 'token'],
			$types,
			'No heartbeats must interleave when each gap is under 15s.'
		);

	}//end testSubFifteenSecondGapsEmitNoInterleavedHeartbeat()

	/**
	 * A single 20s gap triggers exactly one interleaved heartbeat right
	 * before the token.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
	 */
	public function testTwentySecondGapTriggersOneInterleavedHeartbeat(): void {
		$controller = $this->makeController();
		$controller->fakeNow = 200.0;
		$controller->seedLastEventAt(200.0);

		// +20s.
		$controller->fakeNow = 220.0;
		$controller->forward(eventType: 'token', payload: ['delta' => 'late']);

		$this->assertSame(
			['heartbeat', 'token'],
			array_column($controller->capturedEvents, 'type'),
			'The heartbeat must precede the late token frame.'
		);

	}//end testTwentySecondGapTriggersOneInterleavedHeartbeat()

	/**
	 * Two forwards at +20s gaps each trigger their own interleaved heartbeat
	 * (the first emit resets $lastEventAt, the second gap still exceeds 15s).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
	 */
	public function testFortySecondTotalElapsedTriggersTwoHeartbeats(): void {
		$controller = $this->makeController();
		$controller->fakeNow = 300.0;
		$controller->seedLastEventAt(300.0);

		// First forward at +20s → 1 heartbeat + 1 token.
		$controller->fakeNow = 320.0;
		$controller->forward(eventType: 'token', payload: ['delta' => 'one']);

		// Second forward at +40s from origin (+20s from previous token) → 1 heartbeat + 1 token.
		$controller->fakeNow = 340.0;
		$controller->forward(eventType: 'token', payload: ['delta' => 'two']);

		$this->assertSame(
			['heartbeat', 'token', 'heartbeat', 'token'],
			array_column($controller->capturedEvents, 'type'),
			'Frames must interleave heartbeat-then-token twice.'
		);

	}//end testFortySecondTotalElapsedTriggersTwoHeartbeats()

	/**
	 * A non-token frame (tool_call) triggers the same interleave.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-2
	 */
	public function testToolCallFrameAlsoTriggersInterleavedHeartbeat(): void {
		$controller = $this->makeController();
		$controller->fakeNow = 0.0;
		$controller->seedLastEventAt(0.0);

		$controller->fakeNow = 20.0;
		$controller->forward(
			eventType: 'tool_call',
			payload: [
				'toolId' => 'x.y',
				'arguments' => [],
			]
		);

		$this->assertSame(
			['heartbeat', 'tool_call'],
			array_column($controller->capturedEvents, 'type')
		);

	}//end testToolCallFrameAlsoTriggersInterleavedHeartbeat()
}//end class
