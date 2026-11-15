<?php

/**
 * Unit tests for ReportSimilarityController's failure and read answers.
 *
 * ReportSimilarityControllerTest covers the guards and the happy evaluate path.
 * This file pins what the controller answers when the grouping service throws on
 * each route, when a group is read or ungrouped successfully, when an
 * unauthenticated caller reads or ungroups, and when the group to ungroup from
 * no longer exists: the status code, the body, and that failures are logged.
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
 * @spec openspec/specs/report-similarity/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ReportSimilarityController;
use OCA\Hermiq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ReportSimilarityServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Failure and read answers of ReportSimilarityController.
 *
 * @covers \OCA\Hermiq\Controller\ReportSimilarityController
 *
 * @spec openspec/specs/report-similarity/spec.md
 */
final class ReportSimilarityControllerUncoveredPathsTest extends TestCase {

	/**
	 * @var ReportSimilarityServiceInterface&MockObject
	 */
	private ReportSimilarityServiceInterface $similarity;

	/**
	 * @var ActionAuthServiceInterface&MockObject
	 */
	private ActionAuthServiceInterface $actionAuth;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->similarity = $this->createMock(ReportSimilarityServiceInterface::class);
		$this->actionAuth = $this->createMock(ActionAuthServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller for a session user (or nobody) and request parameters.
	 *
	 * @param IUser|null           $user   The session user, or null.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return ReportSimilarityController The controller.
	 */
	private function controller(?IUser $user, array $params = []): ReportSimilarityController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ReportSimilarityController(
			$request,
			$this->similarity,
			$this->actionAuth,
			$session,
			$this->logger
		);
	}//end controller()

	/**
	 * A user double.
	 *
	 * @return IUser&MockObject The user.
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler');
		return $user;
	}//end user()

	/**
	 * When the grouping service throws, evaluate answers 500 with a fixed message
	 * and logs the cause; the exception text does not leak to the caller.
	 *
	 * @return void
	 */
	public function testEvaluateFailureIsLoggedAndAnswered500(): void {
		$this->similarity->expects(self::once())
			->method('evaluate')
			->with('melding-1', 'melding', 'Stroomstoring', 'key-9')
			->willThrowException(new RuntimeException('index down'));
		$this->logger->expects(self::once())
			->method('error')
			->with('Hermiq report-similarity evaluation failed: index down', self::arrayHasKey('exception'));

		$response = $this->controller(
			$this->user(),
			['reportId' => ' melding-1 ', 'reportType' => 'melding', 'text' => 'Stroomstoring', 'deterministicKey' => ' key-9 ']
		)->evaluate();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'The grouping question could not be answered'], $response->getData());
	}//end testEvaluateFailureIsLoggedAndAnswered500()

	/**
	 * An unauthenticated caller reads no group.
	 *
	 * @return void
	 */
	public function testShowUnauthenticatedIs401(): void {
		$this->similarity->expects(self::never())->method('group');

		$response = $this->controller(null)->show('group-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Unauthenticated'], $response->getData());
	}//end testShowUnauthenticatedIs401()

	/**
	 * A failing group read is logged and answered 500.
	 *
	 * @return void
	 */
	public function testShowFailureIsLoggedAndAnswered500(): void {
		$this->similarity->expects(self::once())
			->method('group')
			->with('group-1')
			->willThrowException(new RuntimeException('db gone'));
		$this->logger->expects(self::once())
			->method('error')
			->with('Hermiq report-group read failed: db gone', self::arrayHasKey('exception'));

		$response = $this->controller($this->user())->show('group-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Could not read the group'], $response->getData());
	}//end testShowFailureIsLoggedAndAnswered500()

	/**
	 * An existing group is handed back as the service shaped it.
	 *
	 * @return void
	 */
	public function testShowReturnsTheGroup(): void {
		$group = ['groupId' => 'group-1', 'count' => 3, 'members' => [['reportId' => 'm-1', 'score' => 0.9]]];
		$this->similarity->expects(self::once())->method('group')->with('group-1')->willReturn($group);

		$response = $this->controller($this->user())->show('group-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($group, $response->getData());
	}//end testShowReturnsTheGroup()

	/**
	 * An unauthenticated caller cannot ungroup, and the gate is not even asked.
	 *
	 * @return void
	 */
	public function testRemoveMemberUnauthenticatedIs401(): void {
		$this->actionAuth->expects(self::never())->method('requireAction');
		$this->similarity->expects(self::never())->method('removeMember');

		$response = $this->controller(null)->removeMember('group-1', 'm-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Unauthenticated'], $response->getData());
	}//end testRemoveMemberUnauthenticatedIs401()

	/**
	 * A permitted ungroup returns the group as it now stands, after asking the gate
	 * for exactly the reportgroup.ungroup action.
	 *
	 * @return void
	 */
	public function testRemoveMemberReturnsTheRemainingGroup(): void {
		$user = $this->user();
		$group = ['groupId' => 'group-1', 'count' => 2];
		$this->actionAuth->expects(self::once())
			->method('requireAction')
			->with($user, 'reportgroup.ungroup');
		$this->similarity->expects(self::once())
			->method('removeMember')
			->with('group-1', 'm-1')
			->willReturn($group);

		$response = $this->controller($user)->removeMember('group-1', 'm-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($group, $response->getData());
	}//end testRemoveMemberReturnsTheRemainingGroup()

	/**
	 * Ungrouping from a group that does not exist is a 404.
	 *
	 * @return void
	 */
	public function testRemoveMemberFromAbsentGroupIs404(): void {
		$this->similarity->expects(self::once())
			->method('removeMember')
			->with('group-404', 'm-1')
			->willReturn(null);

		$response = $this->controller($this->user())->removeMember('group-404', 'm-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Group not found'], $response->getData());
	}//end testRemoveMemberFromAbsentGroupIs404()

	/**
	 * A failing ungroup is logged and answered 500.
	 *
	 * @return void
	 */
	public function testRemoveMemberFailureIsLoggedAndAnswered500(): void {
		$this->similarity->expects(self::once())
			->method('removeMember')
			->willThrowException(new RuntimeException('write refused'));
		$this->logger->expects(self::once())
			->method('error')
			->with('Hermiq report-group ungroup failed: write refused', self::arrayHasKey('exception'));

		$response = $this->controller($this->user())->removeMember('group-1', 'm-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Could not remove the report from the group'], $response->getData());
	}//end testRemoveMemberFailureIsLoggedAndAnswered500()
}//end class
