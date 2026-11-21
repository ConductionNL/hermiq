<?php

/**
 * Unit tests for ResearchController (tools-deep-research-report).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ResearchController;
use OCA\Hermiq\Service\Research\ResearchRequest;
use OCA\Hermiq\Service\Research\ResearchStarter;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Controller\ResearchController
 * @uses   \OCA\Hermiq\Service\Research\ResearchRequest
 */
final class ResearchControllerTest extends TestCase {

	/**
	 * The starter.
	 *
	 * @var ResearchStarter&MockObject
	 */
	private ResearchStarter&MockObject $research;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Doubles for the starter and the session.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->research = $this->createMock(originalClassName: ResearchStarter::class);
		$this->session  = $this->createMock(originalClassName: IUserSession::class);
	}//end setUp()

	/**
	 * A start answers the run with 201, started for the signed-in person.
	 *
	 * @return void
	 */
	public function testAStartAnswersTheRunWith201(): void {
		$this->signedIn(uid: 'anne');
		$this->research->expects(self::once())->method('start')
			->willReturnCallback(function (ResearchRequest $request, string $userId): array {
				$asked = [$request->agentId, $request->conversationId, $request->question, $request->depth, $userId];
				self::assertSame(expected: ['agent-1', 'conv-1', 'Q', 'quick', 'anne'], actual: $asked);
				return ['id' => 'r-1', 'status' => 'waiting'];
			});

		$response = $this->controller()->create('agent-1', 'conv-1', 'Q', 'quick');

		self::assertSame(expected: 201, actual: $response->getStatus());
		self::assertSame(expected: 'r-1', actual: $response->getData()['id']);
	}//end testAStartAnswersTheRunWith201()

	/**
	 * A bad request is 400 and an agent or conversation the person cannot read is 404.
	 *
	 * @return void
	 */
	public function testABadStartIs400AndAnUnreadableAgentOrConversation404(): void {
		$this->signedIn(uid: 'anne');
		$this->research->method('start')->willReturn(null);

		self::assertSame(expected: 400, actual: $this->controller()->create('agent-1', 'conv-1', 'Q', 'deep')->getStatus());
		self::assertSame(expected: 400, actual: $this->controller()->create('agent-1', '', 'Q')->getStatus());
		self::assertSame(expected: 404, actual: $this->controller()->create('agent-1', 'conv-1', 'Q', 'quick')->getStatus());
	}//end testABadStartIs400AndAnUnreadableAgentOrConversation404()

	/**
	 * The owner stops and reads their run; any other run is 404.
	 *
	 * @return void
	 */
	public function testTheOwnerStopsAndReadsTheirRunAndAnyOtherIs404(): void {
		$this->signedIn(uid: 'anne');
		$this->research->method('stop')->willReturnCallback(fn (string $id, string $uid): bool => $id === 'r-1' && $uid === 'anne');
		$this->research->method('show')->willReturnCallback(fn (string $id): ?array => ['r-1' => ['id' => 'r-1']][$id] ?? null);

		$stopped = $this->controller()->stop('r-1');
		self::assertSame(expected: 200, actual: $stopped->getStatus());
		self::assertTrue(condition: $stopped->getData()['stopRequested']);
		self::assertSame(expected: 404, actual: $this->controller()->stop('r-2')->getStatus());
		self::assertSame(expected: 200, actual: $this->controller()->show('r-1')->getStatus());
		self::assertSame(expected: 404, actual: $this->controller()->show('r-2')->getStatus());
	}//end testTheOwnerStopsAndReadsTheirRunAndAnyOtherIs404()

	/**
	 * Every route needs a signed-in person.
	 *
	 * @return void
	 */
	public function testEveryRouteNeedsASignedInPerson(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->research->expects(self::never())->method('start');
		$this->research->expects(self::never())->method('stop');
		$this->research->expects(self::never())->method('show');

		self::assertSame(expected: 401, actual: $this->controller()->create('agent-1', 'conv-1', 'Q')->getStatus());
		self::assertSame(expected: 401, actual: $this->controller()->stop('r-1')->getStatus());
		self::assertSame(expected: 401, actual: $this->controller()->show('r-1')->getStatus());
	}//end testEveryRouteNeedsASignedInPerson()

	/**
	 * Sign this person in.
	 *
	 * @param string $uid The person.
	 *
	 * @return void
	 */
	private function signedIn(string $uid): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}//end signedIn()

	/**
	 * The controller over the doubles.
	 *
	 * @return ResearchController
	 */
	private function controller(): ResearchController {
		$request = $this->createMock(originalClassName: IRequest::class);

		return new ResearchController(request: $request, research: $this->research, userSession: $this->session);
	}//end controller()
}//end class
