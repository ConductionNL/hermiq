<?php

/**
 * Unit tests for EvalScheduleController (reuse-scheduled-bias-evals).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\EvalScheduleController;
use OCA\Hermiq\Kernel\Contracts\EvalScheduleServiceInterface;
use OCA\Hermiq\Kernel\Contracts\EvalTemplateServiceInterface;
use OCA\Hermiq\Service\EvalActionException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The controller passes the signed-in caller to the services and answers their refusals with their status.
 *
 * @covers \OCA\Hermiq\Controller\EvalScheduleController
 * @uses   \OCA\Hermiq\Service\EvalActionException
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
class EvalScheduleControllerTest extends TestCase {

	/**
	 * The controller for a caller (null: nobody signed in) with request parameters.
	 *
	 * @param string|null                  $uid       The caller.
	 * @param array<string, mixed>         $params    The request parameters.
	 * @param EvalTemplateServiceInterface $templates The template service.
	 * @param EvalScheduleServiceInterface $schedules The schedule service.
	 *
	 * @return EvalScheduleController
	 */
	private function controller(?string $uid, array $params, EvalTemplateServiceInterface $templates, EvalScheduleServiceInterface $schedules): EvalScheduleController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new EvalScheduleController(request: $request, userSession: $session, templates: $templates, schedules: $schedules);
	}//end controller()

	/**
	 * "Use for this agent" answers 201 with the copy, for the caller and the agent in the body.
	 *
	 * @return void
	 */
	public function testUseForAgentCreatesTheCopyForTheCaller(): void {
		$templates = $this->createMock(EvalTemplateServiceInterface::class);
		$templates->expects($this->once())->method('useForAgent')->with('tpl-en', 'agent-1', 'alice')
			->willReturn(['id' => 'copy-1', 'name' => 'Bias check — A', 'agentId' => 'agent-1', 'caseCount' => 20]);

		$response = $this->controller('alice', ['agentId' => 'agent-1'], $templates, $this->createMock(EvalScheduleServiceInterface::class))->useForAgent(datasetId: 'tpl-en');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame('copy-1', $response->getData()['id']);
	}//end testUseForAgentCreatesTheCopyForTheCaller()

	/**
	 * A non-owner's refusal is answered 404, and nobody signed in is 401 without asking the service.
	 *
	 * @return void
	 */
	public function testRefusalsKeepTheirStatusAndAnonymousIs401(): void {
		$schedules = $this->createMock(EvalScheduleServiceInterface::class);
		$schedules->method('save')->willThrowException(new EvalActionException('Dataset not found', 404));
		$schedules->method('listForDataset')->willThrowException(new EvalActionException('Dataset not found', 404));
		$templates = $this->createMock(EvalTemplateServiceInterface::class);
		$templates->method('useForAgent')->willThrowException(new EvalActionException('Only a template can be used for an agent', 400));

		$mallory = $this->controller('mallory', ['agentId' => 'agent-1'], $templates, $schedules);
		$this->assertSame([404, ['error' => 'Dataset not found']], [$mallory->save(datasetId: 'ds-1')->getStatus(), $mallory->save(datasetId: 'ds-1')->getData()]);
		$this->assertSame(404, $mallory->index(datasetId: 'ds-1')->getStatus());
		$this->assertSame(400, $mallory->useForAgent(datasetId: 'plain')->getStatus());

		$silent = $this->createMock(EvalScheduleServiceInterface::class);
		$silent->expects($this->never())->method('save');
		$anonymous = $this->controller(null, [], $this->createMock(EvalTemplateServiceInterface::class), $silent);
		$this->assertSame(401, $anonymous->save(datasetId: 'ds-1')->getStatus());
		$this->assertSame(401, $anonymous->index(datasetId: 'ds-1')->getStatus());
		$this->assertSame(401, $anonymous->useForAgent(datasetId: 'ds-1')->getStatus());
	}//end testRefusalsKeepTheirStatusAndAnonymousIs401()

	/**
	 * Saving passes the form's fields; an empty cron field leaves the default to the service.
	 *
	 * @return void
	 */
	public function testSavePassesTheFormFields(): void {
		$schedules = $this->createMock(EvalScheduleServiceInterface::class);
		$schedules->expects($this->once())->method('save')->with(
			'ds-1',
			['id' => '', 'agentId' => 'agent-1', 'enabled' => false, 'regressionThresholdPercent' => '10'],
			'alice'
		)->willReturn(['id' => 'sched-1', 'nextRun' => '2026-10-01T06:00:00+00:00']);

		$response = $this->controller(
			'alice',
			['agentId' => 'agent-1', 'cronExpr' => '', 'enabled' => 'false', 'regressionThresholdPercent' => '10'],
			$this->createMock(EvalTemplateServiceInterface::class),
			$schedules
		)->save(datasetId: 'ds-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('2026-10-01T06:00:00+00:00', $response->getData()['nextRun']);
	}//end testSavePassesTheFormFields()
}//end class
