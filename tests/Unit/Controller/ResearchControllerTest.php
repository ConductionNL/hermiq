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

use InvalidArgumentException;
use OCA\Hermiq\Controller\ResearchController;
use OCA\Hermiq\Service\Research\ResearchStarter;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Controller\ResearchController
 */
final class ResearchControllerTest extends TestCase {

	/**
	 * @var ResearchStarter&MockObject
	 */
	private ResearchStarter&MockObject $research;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	protected function setUp(): void {
		$this->research = $this->createMock(ResearchStarter::class);
		$this->session = $this->createMock(IUserSession::class);
	}

	public function testAStartAnswersTheRunWith201(): void {
		$this->signedIn('anne');
		$this->research->expects(self::once())->method('start')
			->with('agent-1', 'conv-1', 'anne', 'Q', 'quick')
			->willReturn(['id' => 'r-1', 'status' => 'waiting']);

		$response = $this->controller()->create('agent-1', 'conv-1', 'Q', 'quick');

		self::assertSame(201, $response->getStatus());
		self::assertSame('r-1', $response->getData()['id']);
	}

	public function testABadStartIs400AndAnUnreadableAgentOrConversation404(): void {
		$this->signedIn('anne');
		$this->research->method('start')->willReturnOnConsecutiveCalls(
			$this->throwException(new InvalidArgumentException('The depth is quick or thorough.')),
			null
		);

		self::assertSame(400, $this->controller()->create('agent-1', 'conv-1', 'Q', 'deep')->getStatus());
		self::assertSame(404, $this->controller()->create('agent-1', 'conv-1', 'Q', 'quick')->getStatus());
	}

	public function testTheOwnerStopsAndReadsTheirRunAndAnyoneElseGets404(): void {
		$this->signedIn('anne');
		$this->research->method('stop')->willReturnCallback(fn (string $id, string $uid): bool => $id === 'r-1' && $uid === 'anne');
		$this->research->method('show')->willReturnCallback(fn (string $id, string $uid): ?array => ($id === 'r-1') ? ['id' => 'r-1'] : null);

		$stopped = $this->controller()->stop('r-1');
		self::assertSame(200, $stopped->getStatus());
		self::assertTrue($stopped->getData()['stopRequested']);
		self::assertSame(404, $this->controller()->stop('r-2')->getStatus());
		self::assertSame(200, $this->controller()->show('r-1')->getStatus());
		self::assertSame(404, $this->controller()->show('r-2')->getStatus());
	}

	public function testEveryRouteNeedsASignedInPerson(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->research->expects(self::never())->method('start');
		$this->research->expects(self::never())->method('stop');
		$this->research->expects(self::never())->method('show');

		self::assertSame(401, $this->controller()->create('agent-1', 'conv-1', 'Q')->getStatus());
		self::assertSame(401, $this->controller()->stop('r-1')->getStatus());
		self::assertSame(401, $this->controller()->show('r-1')->getStatus());
	}

	/**
	 * Sign this person in.
	 *
	 * @param string $uid The person.
	 *
	 * @return void
	 */
	private function signedIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}

	/**
	 * The controller over the doubles.
	 *
	 * @return ResearchController
	 */
	private function controller(): ResearchController {
		return new ResearchController($this->createMock(IRequest::class), $this->research, $this->session);
	}
}
