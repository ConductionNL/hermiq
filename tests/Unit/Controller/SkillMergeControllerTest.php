<?php

/**
 * The skill merge endpoints: the action gate first, then the service's answer.
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
 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#requirement-the-curator-shall-propose-merging-duplicate-skills
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SkillMergeController;
use OCA\Hermiq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillMergeServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * List, approve and reject merge proposals behind skill.merge-duplicates.
 *
 * @covers \OCA\Hermiq\Controller\SkillMergeController
 */
class SkillMergeControllerTest extends TestCase {

	/**
	 * The merge service.
	 *
	 * @var SkillMergeServiceInterface&MockObject
	 */
	private SkillMergeServiceInterface $merges;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->merges = $this->createMock(SkillMergeServiceInterface::class);
	}//end setUp()

	/**
	 * The controller for a signed-in user who holds the action, or not, or for nobody.
	 *
	 * @param bool|null $allowed True when the user holds the action, false when not, null for no user.
	 *
	 * @return SkillMergeController
	 */
	private function controller(?bool $allowed): SkillMergeController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($allowed !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('olga');
		}

		$session->method('getUser')->willReturn($user);

		$auth = $this->createMock(ActionAuthServiceInterface::class);
		if ($allowed === false) {
			$auth->expects($this->once())->method('requireAction')
				->with($user, 'skill.merge-duplicates')
				->willThrowException(new OCSForbiddenException('Not allowed'));
		} elseif ($allowed === true) {
			$auth->expects($this->once())->method('requireAction')->with($user, 'skill.merge-duplicates');
		} else {
			$auth->expects($this->never())->method('requireAction');
		}

		return new SkillMergeController(
			request: $this->createMock(IRequest::class),
			mergeService: $this->merges,
			actionAuth: $auth,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * Nobody signed in gets 401 and the service is never asked.
	 *
	 * @return void
	 */
	public function testAnUnauthenticatedCallerIsRefusedBeforeAnything(): void {
		$this->merges->expects($this->never())->method('pendingMerges');
		$this->merges->expects($this->never())->method('approveMerge');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(allowed: null)->index()->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(allowed: null)->approve(id: 'go-1')->getStatus());
	}//end testAnUnauthenticatedCallerIsRefusedBeforeAnything()

	/**
	 * A user without the action gets 403 on every route, and nothing is read or decided.
	 *
	 * @return void
	 */
	public function testACallerWithoutTheActionIsRefusedAndNothingIsDecided(): void {
		$this->merges->expects($this->never())->method('pendingMerges');
		$this->merges->expects($this->never())->method('approveMerge');
		$this->merges->expects($this->never())->method('rejectMerge');

		$index = $this->controller(allowed: false)->index();
		self::assertSame(Http::STATUS_FORBIDDEN, $index->getStatus());
		self::assertSame(['error' => 'Not allowed'], $index->getData());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(allowed: false)->approve(id: 'go-1')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(allowed: false)->reject(id: 'go-1')->getStatus());
	}//end testACallerWithoutTheActionIsRefusedAndNothingIsDecided()

	/**
	 * The pending proposals come back as `proposals`.
	 *
	 * @return void
	 */
	public function testTheProposalsAreListed(): void {
		$proposal = ['duplicate' => ['id' => 'go-1'], 'survivor' => ['id' => 'keep-1'], 'reason' => 'same-name', 'proposedAt' => '2026-11-01T10:00:00+00:00'];
		$this->merges->expects($this->once())->method('pendingMerges')->willReturn([$proposal]);

		$response = $this->controller(allowed: true)->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['proposals' => [$proposal]], $response->getData());
	}//end testTheProposalsAreListed()

	/**
	 * An approved merge answers with the survivor and the agents moved.
	 *
	 * @return void
	 */
	public function testAnApprovedMergeAnswersWithTheSurvivor(): void {
		$this->merges->expects($this->once())->method('approveMerge')->with('go-1')
			->willReturn(['merged' => true, 'survivor' => 'keep-1', 'agentsMoved' => 2]);

		$response = $this->controller(allowed: true)->approve(id: 'go-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['merged' => true, 'survivor' => 'keep-1', 'agentsMoved' => 2], $response->getData());
	}//end testAnApprovedMergeAnswersWithTheSurvivor()

	/**
	 * A missing skill is 404; no pending proposal or a gone survivor is 409.
	 *
	 * @return void
	 */
	public function testARefusedDecisionMapsToItsStatus(): void {
		$this->merges->method('approveMerge')->willReturnOnConsecutiveCalls(
			['merged' => false, 'error' => 'not_found'],
			['merged' => false, 'error' => 'survivor_gone']
		);
		$this->merges->method('rejectMerge')->willReturn(['rejected' => false, 'error' => 'no_proposal']);

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller(allowed: true)->approve(id: 'x')->getStatus());
		$gone = $this->controller(allowed: true)->approve(id: 'go-1');
		self::assertSame(Http::STATUS_CONFLICT, $gone->getStatus());
		self::assertSame(['error' => 'survivor_gone'], $gone->getData());
		self::assertSame(Http::STATUS_CONFLICT, $this->controller(allowed: true)->reject(id: 'go-1')->getStatus());
	}//end testARefusedDecisionMapsToItsStatus()

	/**
	 * A rejected merge answers `rejected: true`; a failing service is a 500 without its message.
	 *
	 * @return void
	 */
	public function testARejectionAndAFailure(): void {
		$this->merges->expects($this->once())->method('rejectMerge')->with('go-1')->willReturn(['rejected' => true]);
		$this->merges->method('pendingMerges')->willThrowException(new RuntimeException('store down'));

		self::assertSame(['rejected' => true], $this->controller(allowed: true)->reject(id: 'go-1')->getData());
		$failed = $this->controller(allowed: true)->index();
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		self::assertSame(['error' => 'Could not read the merge proposals'], $failed->getData());
	}//end testARejectionAndAFailure()
}//end class
