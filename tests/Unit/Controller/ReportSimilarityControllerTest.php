<?php

/**
 * Unit tests for ReportSimilarityController.
 *
 * Asking the grouping question is something the owning app does on every intake, so
 * it is open to an authenticated caller. Taking a report back out of a group changes
 * what a handler sees about the public's reports, so it is gated, and the gate is
 * probed with the least privileged principal that should be refused.
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
 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ReportSimilarityController;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\ReportSimilarity\ReportSimilarityService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * ReportSimilarityController unit tests.
 *
 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md
 */
class ReportSimilarityControllerTest extends TestCase {

	/**
	 * A session for one user, or for nobody.
	 *
	 * @param string|null $uid The user id, or null for an unauthenticated session.
	 *
	 * @return IUserSession The session.
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);

		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * A request answering the given parameters.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return IRequest The double.
	 */
	private function request(array $params = []): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);

		return $request;
	}//end request()

	/**
	 * An ordinary authenticated user cannot take a report out of a group: what a
	 * handler sees about two hundred people's reports is not theirs to rearrange.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-pulling-one-report-out-costs-nothing
	 */
	public function testAnOrdinaryUserCannotUngroupAReport(): void {
		$similarity = $this->createMock(ReportSimilarityService::class);
		$similarity->expects($this->never())->method('removeMember');

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));

		$controller = new ReportSimilarityController(
			$this->request(),
			$similarity,
			$actionAuth,
			$this->session('mallory'),
			new NullLogger()
		);

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$controller->removeMember('group-1', 'melding-7')->getStatus()
		);

	}//end testAnOrdinaryUserCannotUngroupAReport()

	/**
	 * An unauthenticated caller asks nothing at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#requirement-hermiq-must-answer-which-group-a-report-belongs-to-and-must-not-act-on-the-answer
	 */
	public function testAnUnauthenticatedCallerAsksNothing(): void {
		$similarity = $this->createMock(ReportSimilarityService::class);
		$similarity->expects($this->never())->method('evaluate');

		$controller = new ReportSimilarityController(
			$this->request(['reportId' => 'melding-1', 'reportType' => 'melding']),
			$similarity,
			$this->createMock(ActionAuthService::class),
			$this->session(null),
			new NullLogger()
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->evaluate()->getStatus());
	}//end testAnUnauthenticatedCallerAsksNothing()

	/**
	 * A question missing the report or its type is refused with a 422 rather than
	 * answered with a group nobody asked about.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#requirement-hermiq-must-answer-which-group-a-report-belongs-to-and-must-not-act-on-the-answer
	 */
	public function testAQuestionWithoutAReportIsRefused(): void {
		$similarity = $this->createMock(ReportSimilarityService::class);
		$similarity->expects($this->never())->method('evaluate');

		$controller = new ReportSimilarityController(
			$this->request(['reportType' => 'melding']),
			$similarity,
			$this->createMock(ActionAuthService::class),
			$this->session('dossiq-service'),
			new NullLogger()
		);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $controller->evaluate()->getStatus());
	}//end testAQuestionWithoutAReportIsRefused()

	/**
	 * The answer is handed back as the service shaped it, with no acknowledgement
	 * instruction added on the way out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-grouping-carries-no-acknowledgement-effect
	 */
	public function testTheAnswerIsHandedBackUnembellished(): void {
		$answer = [
			'groupId' => 'group-1',
			'count' => 200,
			'score' => 1.0,
			'newGroup' => false,
			'uncertain' => false,
			'terms' => ['stroomstoring', 'kerkstraat'],
		];

		$similarity = $this->createMock(ReportSimilarityService::class);
		$similarity->method('evaluate')->willReturn($answer);

		$controller = new ReportSimilarityController(
			$this->request(['reportId' => 'melding-1', 'reportType' => 'melding', 'text' => 'Stroomstoring']),
			$similarity,
			$this->createMock(ActionAuthService::class),
			$this->session('dossiq-service'),
			new NullLogger()
		);

		$response = $controller->evaluate();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($answer, $response->getData());
		$this->assertArrayNotHasKey('acknowledgement', $response->getData());
	}//end testTheAnswerIsHandedBackUnembellished()

	/**
	 * Reading a group that does not exist is a 404, not an empty group that reads
	 * like one with nothing in it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-why-these-are-one-thing-is-answerable
	 */
	public function testAnAbsentGroupIsA404(): void {
		$similarity = $this->createMock(ReportSimilarityService::class);
		$similarity->method('group')->willReturn(null);

		$controller = new ReportSimilarityController(
			$this->request(),
			$similarity,
			$this->createMock(ActionAuthService::class),
			$this->session('handler'),
			new NullLogger()
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->show('group-404')->getStatus());
	}//end testAnAbsentGroupIsA404()
}//end class
