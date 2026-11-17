<?php

/**
 * Contract tests for `GET|POST /api/agents/{id}/availability`.
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
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AgentAvailabilityController;
use OCA\Hermiq\Service\Agent\AgentAvailabilityService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests AgentAvailabilityController.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
 */
class AgentAvailabilityControllerTest extends TestCase {

	/**
	 * Availability service double.
	 *
	 * @var AgentAvailabilityService&MockObject
	 */
	private $service;

	/**
	 * Request params the controller reads.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Build the controller for user alice.
	 *
	 * @return AgentAvailabilityController
	 */
	private function controller(): AgentAvailabilityController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new AgentAvailabilityController($request, $this->service, $session);

	}//end controller()

	/**
	 * Set up the service double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(AgentAvailabilityService::class);

	}//end setUp()

	/**
	 * A permitted switch answers 200 with the new state.
	 *
	 * @return void
	 */
	public function testSwitchOffAnswers200WithTheState(): void {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject(['active' => false, 'availabilityChangedBy' => 'alice', 'availabilityChangedAt' => '2026-09-30T12:00:00+00:00', 'availabilityReason' => 'Wrong reminders']);

		$this->params = ['active' => false, 'reason' => 'Wrong reminders'];
		$this->service->expects($this->once())->method('switchAgent')
			->with('agent-1', false, 'Wrong reminders', 'alice')
			->willReturn($agent);
		$this->service->method('scheduleCount')->willReturn(2);

		$response = $this->controller()->update(id: 'agent-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertFalse($response->getData()['active']);
		$this->assertSame('alice', $response->getData()['changedBy']);
		$this->assertSame('Wrong reminders', $response->getData()['reason']);

	}//end testSwitchOffAnswers200WithTheState()

	/**
	 * The service's refusals map to their status codes.
	 *
	 * @return void
	 */
	public function testRefusalsKeepTheirStatus(): void {
		foreach ([403, 400, 404] as $code) {
			$this->service = $this->createMock(AgentAvailabilityService::class);
			$this->service->method('switchAgent')->willThrowException(new RuntimeException('refused', $code));
			$this->params = ['active' => 'false', 'reason' => ''];

			$response = $this->controller()->update(id: 'agent-1');

			$this->assertSame($code, $response->getStatus());
			$this->assertArrayHasKey('error', $response->getData());
		}

	}//end testRefusalsKeepTheirStatus()

	/**
	 * `active` arrives as a string from a form post; "false" means off.
	 *
	 * @return void
	 */
	public function testStringFalseMeansOff(): void {
		$agent = new ObjectEntity();
		$agent->setObject(['active' => false]);
		$this->params = ['active' => 'false', 'reason' => 'x'];
		$this->service->expects($this->once())->method('switchAgent')
			->with('agent-1', false, 'x', 'alice')
			->willReturn($agent);

		$this->controller()->update(id: 'agent-1');

	}//end testStringFalseMeansOff()

	/**
	 * The read answers the state and the schedule count the delete dialog names.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-deleting-an-agent-removes-its-schedules-req-agoff-004
	 */
	public function testShowAnswersTheStateAndTheScheduleCount(): void {
		$agent = new ObjectEntity();
		$agent->setObject(['active' => true]);
		$this->service->method('readableAgent')->with('agent-1', 'alice')->willReturn($agent);
		$this->service->method('scheduleCount')->with('agent-1')->willReturn(2);
		$this->service->method('mayModify')->willReturn(true);

		$response = $this->controller()->show(id: 'agent-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['active']);
		$this->assertSame(2, $response->getData()['scheduleCount']);
		$this->assertTrue($response->getData()['canSwitch']);

		$this->service = $this->createMock(AgentAvailabilityService::class);
		$this->service->method('readableAgent')->willReturn(null);
		$this->assertSame(404, $this->controller()->show(id: 'hidden')->getStatus());

	}//end testShowAnswersTheStateAndTheScheduleCount()
}//end class
