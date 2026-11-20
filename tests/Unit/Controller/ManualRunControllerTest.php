<?php

/**
 * POST /api/agents/{id}/run (scheduling-overview-and-start-choice, REQ-SCHED-002).
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
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-an-agents-page-offers-one-choice-of-how-it-starts-req-sched-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ManualRunController;
use OCA\Hermiq\Kernel\Contracts\LiteracyRequirementInterface;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\Schedule\ManualRunService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ManualRunController::class)]
final class ManualRunControllerTest extends TestCase {

	private AgentAccessService&MockObject $access;

	private ManualRunService&MockObject $runs;

	/**
	 * The controller for a caller, with the access check and the run doubled.
	 *
	 * @param string|null                       $uid      The caller, null for none.
	 * @param string                            $prompt   The body's prompt.
	 * @param LiteracyRequirementInterface|null $literacy The course requirement.
	 *
	 * @return ManualRunController
	 */
	private function controller(?string $uid = 'clerk', string $prompt = 'Summarise the open objections', ?LiteracyRequirementInterface $literacy = null): ManualRunController {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $key === 'prompt' ? $prompt : $default
		);

		$this->access = $this->createMock(AgentAccessService::class);
		$this->runs   = $this->createMock(ManualRunService::class);

		return new ManualRunController(
			request: $request,
			userSession: $session,
			access: $this->access,
			runs: $this->runs,
			literacy: $literacy
		);
	}//end controller()

	public function testAUserWhoMayUseTheAgentRunsItByHand(): void {
		$controller = $this->controller();
		$agent      = new ObjectEntity();
		$this->access->method('loadAccessibleAgent')->with('agent-1', 'clerk')->willReturn($agent);
		$this->runs->expects($this->once())->method('run')
			->with($agent, 'clerk', 'Summarise the open objections')
			->willReturn(['status' => 'ok', 'trigger' => 'manual', 'output' => 'Three are open.', 'error' => null]);

		$response = $controller->run(id: 'agent-1');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['status' => 'ok', 'trigger' => 'manual', 'output' => 'Three are open.'], $response->getData());
	}//end testAUserWhoMayUseTheAgentRunsItByHand()

	public function testAnAgentTheCallerMayNotUseIsNotFoundAndNotRun(): void {
		$controller = $this->controller();
		$this->access->method('loadAccessibleAgent')->willReturn(null);
		$this->runs->expects($this->never())->method('run');

		$response = $controller->run(id: 'private-agent');

		self::assertSame(404, $response->getStatus());
	}//end testAnAgentTheCallerMayNotUseIsNotFoundAndNotRun()

	public function testWithoutASessionNothingIsLoaded(): void {
		$controller = $this->controller(uid: null);
		$this->access->expects($this->never())->method('loadAccessibleAgent');

		self::assertSame(401, $controller->run(id: 'agent-1')->getStatus());
	}//end testWithoutASessionNothingIsLoaded()

	public function testAnEmptyOrOverlongPromptIsRefusedBeforeTheRun(): void {
		foreach (['   ', str_repeat('x', 10001)] as $prompt) {
			$controller = $this->controller(prompt: $prompt);
			$this->access->method('loadAccessibleAgent')->willReturn(new ObjectEntity());
			$this->runs->expects($this->never())->method('run');

			self::assertSame(400, $controller->run(id: 'agent-1')->getStatus());
		}
	}//end testAnEmptyOrOverlongPromptIsRefusedBeforeTheRun()

	public function testARequiredCourseNotYetDoneRefusesTheRun(): void {
		$literacy = $this->createMock(LiteracyRequirementInterface::class);
		$literacy->method('refusal')->with('clerk')->willReturn(['message' => 'Finish the course first.', 'course' => 'working-with-ai']);
		$controller = $this->controller(literacy: $literacy);
		$this->access->method('loadAccessibleAgent')->willReturn(new ObjectEntity());
		$this->runs->expects($this->never())->method('run');

		$response = $controller->run(id: 'agent-1');

		self::assertSame(403, $response->getStatus());
		self::assertSame('Finish the course first.', $response->getData()['error']);
	}//end testARequiredCourseNotYetDoneRefusesTheRun()

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function gates(): array {
		return [
			'switched off' => ['skipped_agent_off', 409],
			'kill switch' => ['skipped_killswitch', 423],
			'budget' => ['skipped_budget', 402],
		];
	}//end gates()

	#[DataProvider('gates')]
	public function testAHeldRunAnswersWithItsGate(string $gate, int $status): void {
		$controller = $this->controller();
		$this->access->method('loadAccessibleAgent')->willReturn(new ObjectEntity());
		$this->runs->method('run')->willReturn(['status' => $gate, 'trigger' => 'manual', 'output' => '', 'error' => null]);

		$response = $controller->run(id: 'agent-1');

		self::assertSame($status, $response->getStatus());
		self::assertSame(['status' => $gate, 'trigger' => 'manual'], $response->getData());
	}//end testAHeldRunAnswersWithItsGate()

	public function testAFailedRunIs502WithoutTheProviderMessage(): void {
		$controller = $this->controller();
		$this->access->method('loadAccessibleAgent')->willReturn(new ObjectEntity());
		$this->runs->method('run')->willReturn(['status' => 'error', 'trigger' => 'manual', 'output' => '', 'error' => 'internal provider detail']);

		$response = $controller->run(id: 'agent-1');

		self::assertSame(502, $response->getStatus());
		self::assertSame(['status' => 'error', 'trigger' => 'manual', 'error' => 'The run failed.'], $response->getData());
	}//end testAFailedRunIs502WithoutTheProviderMessage()
}//end class
