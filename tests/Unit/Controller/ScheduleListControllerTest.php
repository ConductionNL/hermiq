<?php

/**
 * GET /api/schedules (scheduling-overview-and-start-choice, REQ-SCHED-001).
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
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-every-schedule-a-person-may-see-is-on-one-page-req-sched-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ScheduleListController;
use OCA\Hermiq\Service\Schedule\ScheduleListService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(ScheduleListController::class)]
final class ScheduleListControllerTest extends TestCase {

	private ScheduleListService&MockObject $schedules;

	private ?IUser $user = null;

	/**
	 * The controller for a caller in a language.
	 *
	 * @param bool   $signedIn Whether there is a session.
	 * @param string $language The caller's language code.
	 *
	 * @return ScheduleListController
	 */
	private function controller(bool $signedIn = true, string $language = 'en'): ScheduleListController {
		$session    = $this->createMock(IUserSession::class);
		$this->user = null;
		if ($signedIn === true) {
			$this->user = $this->createMock(IUser::class);
			$this->user->method('getUID')->willReturn('clerk');
		}

		$session->method('getUser')->willReturn($this->user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn($language);
		$this->schedules = $this->createMock(ScheduleListService::class);

		return new ScheduleListController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			schedules: $this->schedules,
			l10n: $l10n,
			logger: new NullLogger()
		);
	}//end controller()

	public function testTheFiltersReachTheListAndTheRowsAreReturnedWithTheirTotal(): void {
		$controller = $this->controller();
		$this->schedules->expects($this->once())->method('list')
			->with($this->user, ['agentId' => 'agent-1', 'enabled' => true, 'failed' => false], 'en')
			->willReturn([['id' => 's1'], ['id' => 's2']]);

		$response = $controller->index(agentId: ' agent-1 ', enabled: 'true', failed: '0');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['results' => [['id' => 's1'], ['id' => 's2']], 'total' => 2], $response->getData());
	}//end testTheFiltersReachTheListAndTheRowsAreReturnedWithTheirTotal()

	public function testUnsetOrUnknownFlagsDoNotFilterAndADutchCallerReadsDutch(): void {
		$controller = $this->controller(language: 'nl_NL');
		$this->schedules->expects($this->once())->method('list')
			->with($this->user, ['agentId' => '', 'enabled' => null, 'failed' => null], 'nl')
			->willReturn([]);

		self::assertSame(['results' => [], 'total' => 0], $controller->index(enabled: 'maybe')->getData());
	}//end testUnsetOrUnknownFlagsDoNotFilterAndADutchCallerReadsDutch()

	public function testWithoutASessionNothingIsListed(): void {
		$controller = $this->controller(signedIn: false);
		$this->schedules->expects($this->never())->method('list');

		self::assertSame(401, $controller->index()->getStatus());
	}//end testWithoutASessionNothingIsListed()

	public function testAnUnreadableListIs500WithoutItsCause(): void {
		$controller = $this->controller();
		$this->schedules->method('list')->willThrowException(new RuntimeException('database credentials wrong'));

		$response = $controller->index();

		self::assertSame([500, ['error' => 'Could not load schedules']], [$response->getStatus(), $response->getData()]);
	}//end testAnUnreadableListIs500WithoutItsCause()
}//end class
