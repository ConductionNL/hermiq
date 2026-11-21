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

use InvalidArgumentException;
use OCA\Hermiq\Controller\EvalScheduleController;
use OCA\Hermiq\Service\Eval\EvalScheduleEditor;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Controller\EvalScheduleController
 */
class EvalScheduleControllerTest extends TestCase {

	/**
	 * The controller for $uid (null: nobody signed in) over the given editor.
	 *
	 * @param string|null        $uid    The caller.
	 * @param EvalScheduleEditor $editor The editor double.
	 *
	 * @return EvalScheduleController
	 */
	private function controller(?string $uid, EvalScheduleEditor $editor): EvalScheduleController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnMap(
			[
				['agentId', null, 'a-1'],
				['cronExpr', null, '0 6 1 * *'],
				['enabled', null, true],
				['regressionThresholdPercent', null, 10],
			]
		);

		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new EvalScheduleController(request: $request, userSession: $session, editor: $editor);
	}//end controller()

	/**
	 * Adding a schedule passes the request fields and the caller, and answers 201.
	 *
	 * @return void
	 */
	public function testAddingAScheduleAnswers201(): void {
		$editor = $this->createMock(originalClassName: EvalScheduleEditor::class);
		$editor->expects($this->once())->method('save')->with(
			'ds-1',
			'alice',
			['agentId' => 'a-1', 'cronExpr' => '0 6 1 * *', 'enabled' => true, 'regressionThresholdPercent' => 10],
			null
		)->willReturn(['id' => 's-1', 'nextRun' => '2026-12-01T06:00:00+00:00']);

		$response = $this->controller(uid: 'alice', editor: $editor)->create(datasetId: 'ds-1');

		self::assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		self::assertSame(expected: 's-1', actual: $response->getData()['id']);
	}//end testAddingAScheduleAnswers201()

	/**
	 * Changing a schedule names it and answers 200.
	 *
	 * @return void
	 */
	public function testChangingAScheduleAnswers200(): void {
		$editor = $this->createMock(originalClassName: EvalScheduleEditor::class);
		$editor->expects($this->once())->method('save')->with('ds-1', 'alice', $this->anything(), 's-1')->willReturn(['id' => 's-1']);

		$response = $this->controller(uid: 'alice', editor: $editor)->update(datasetId: 'ds-1', id: 's-1');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
	}//end testChangingAScheduleAnswers200()

	/**
	 * What the caller does not own is a 404, for the list and for a save.
	 *
	 * @return void
	 */
	public function testANonOwnerGets404(): void {
		$editor = $this->createMock(originalClassName: EvalScheduleEditor::class);
		$editor->method('save')->willReturn(null);
		$editor->method('listFor')->willReturn(null);
		$controller = $this->controller(uid: 'bob', editor: $editor);

		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $controller->create(datasetId: 'ds-1')->getStatus());
		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $controller->index(datasetId: 'ds-1')->getStatus());
	}//end testANonOwnerGets404()

	/**
	 * A schedule that is not valid is a 400 with the reason.
	 *
	 * @return void
	 */
	public function testAnInvalidScheduleIs400(): void {
		$editor = $this->createMock(originalClassName: EvalScheduleEditor::class);
		$editor->method('save')->willThrowException(new InvalidArgumentException('The schedule is not a valid cron expression.'));

		$response = $this->controller(uid: 'alice', editor: $editor)->create(datasetId: 'ds-1');

		self::assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		self::assertSame(expected: ['error' => 'The schedule is not a valid cron expression.'], actual: $response->getData());
	}//end testAnInvalidScheduleIs400()

	/**
	 * The list answers the caller's schedules.
	 *
	 * @return void
	 */
	public function testTheListAnswersTheSchedules(): void {
		$editor = $this->createMock(originalClassName: EvalScheduleEditor::class);
		$editor->method('listFor')->with('ds-1', 'alice')->willReturn([['id' => 's-1']]);

		$response = $this->controller(uid: 'alice', editor: $editor)->index(datasetId: 'ds-1');

		self::assertSame(expected: ['results' => [['id' => 's-1']]], actual: $response->getData());
	}//end testTheListAnswersTheSchedules()

	/**
	 * Nobody signed in is a 401, and the editor is not asked.
	 *
	 * @return void
	 */
	public function testNobodySignedInIs401(): void {
		$editor = $this->createMock(originalClassName: EvalScheduleEditor::class);
		$editor->expects($this->never())->method('save');
		$editor->expects($this->never())->method('listFor');
		$controller = $this->controller(uid: null, editor: $editor);

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $controller->create(datasetId: 'ds-1')->getStatus());
		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $controller->index(datasetId: 'ds-1')->getStatus());
	}//end testNobodySignedInIs401()
}//end class
