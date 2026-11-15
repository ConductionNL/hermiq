<?php

/**
 * Unit tests for SkillDraftController's list and edit-content paths.
 *
 * SkillDraftControllerTest covers propose, accept and reject. This file pins
 * the draft list (`index()`: 401, 404 on an invisible skill, the shaped
 * drafts), the edit-then-accept content endpoint (`content()`: the replacement
 * handed to the pipeline with the editor, 409 on a refused edit, 500 logged on
 * any other failure), the 404 for a draft that does not exist, and the null
 * draft in a post-decision response when the draft is gone on re-read.
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
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Hermiq\Controller\SkillDraftController;
use OCA\Hermiq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ApprovalServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SeedCustodyServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillConsolidationServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillVersionServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * List, edit-content and missing-draft answers of SkillDraftController.
 *
 * @covers \OCA\Hermiq\Controller\SkillDraftController
 * @uses   \OCA\Hermiq\Controller\DraftRejection
 * @uses   \OCA\Hermiq\Controller\SkillOwnership
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
 */
final class SkillDraftControllerUncoveredPathsTest extends TestCase {

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * @var SkillServiceInterface&MockObject
	 */
	private SkillServiceInterface $skillService;

	/**
	 * @var SkillConsolidationServiceInterface&MockObject
	 */
	private SkillConsolidationServiceInterface $consolidation;

	/**
	 * @var ApprovalServiceInterface&MockObject
	 */
	private ApprovalServiceInterface $approvalService;

	/**
	 * @var SkillVersionServiceInterface&MockObject
	 */
	private SkillVersionServiceInterface $versionService;

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
		$this->request = $this->createMock(IRequest::class);
		$this->skillService = $this->createMock(SkillServiceInterface::class);
		$this->consolidation = $this->createMock(SkillConsolidationServiceInterface::class);
		$this->approvalService = $this->createMock(ApprovalServiceInterface::class);
		$this->versionService = $this->createMock(SkillVersionServiceInterface::class);
		$this->actionAuth = $this->createMock(ActionAuthServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller with the session resolving to $uid (null = unauthenticated).
	 *
	 * @param string|null $uid The session UID.
	 *
	 * @return SkillDraftController
	 */
	private function controller(?string $uid = 'alice'): SkillDraftController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new SkillDraftController(
			$this->request,
			$this->skillService,
			$this->consolidation,
			$this->approvalService,
			$this->versionService,
			$this->actionAuth,
			$this->createMock(SeedCustodyServiceInterface::class),
			$session,
			$this->logger
		);
	}//end controller()

	/**
	 * An entity with the given uuid and payload.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * A guarded draft for skill-1, visible and reviewable by the caller.
	 *
	 * @return ObjectEntity
	 */
	private function guardedDraft(): ObjectEntity {
		$draft = $this->entity('draft-1', ['skillId' => 'skill-1', 'approvalId' => 'appr-1', 'body' => 'old']);
		$this->skillService->method('getSkill')->with('skill-1')->willReturn($this->entity('skill-1', []));
		return $draft;
	}//end guardedDraft()

	/**
	 * Listing drafts without a session user is 401 and reads nothing.
	 *
	 * @return void
	 */
	public function testIndexUnauthenticatedIs401(): void {
		$this->skillService->expects(self::never())->method('getSkill');
		$this->consolidation->expects(self::never())->method('draftsForSkill');

		$response = $this->controller(null)->index('skill-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Unauthenticated'], $response->getData());
	}//end testIndexUnauthenticatedIs401()

	/**
	 * A skill the caller cannot see (the read throws) is 404 and lists no drafts.
	 *
	 * @return void
	 */
	public function testIndexInvisibleSkillIs404(): void {
		$this->skillService->expects(self::once())->method('getSkill')->with('skill-x')
			->willThrowException(new RuntimeException('not in scope'));
		$this->consolidation->expects(self::never())->method('draftsForSkill');

		$response = $this->controller()->index('skill-x');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Skill not found'], $response->getData());
	}//end testIndexInvisibleSkillIs404()

	/**
	 * A visible skill lists its drafts, each shaped as payload plus uuid.
	 *
	 * @return void
	 */
	public function testIndexListsShapedDrafts(): void {
		$this->skillService->method('getSkill')->with('skill-1')->willReturn($this->entity('skill-1', []));
		$this->consolidation->expects(self::once())->method('draftsForSkill')->with('skill-1')
			->willReturn(
				[
					$this->entity('d-2', ['status' => 'proposed']),
					$this->entity('d-1', ['status' => 'rejected']),
				]
			);

		$response = $this->controller()->index('skill-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			[
				'results' => [
					['id' => 'd-2', 'status' => 'proposed', 'uuid' => 'd-2'],
					['id' => 'd-1', 'status' => 'rejected', 'uuid' => 'd-1'],
				],
			],
			$response->getData()
		);
	}//end testIndexListsShapedDrafts()

	/**
	 * A draft id that resolves to nothing is 404 before any skill read or action check.
	 *
	 * @return void
	 */
	public function testContentMissingDraftIs404(): void {
		$this->consolidation->expects(self::once())->method('getDraft')->with('nope')->willReturn(null);
		$this->skillService->expects(self::never())->method('getSkill');
		$this->actionAuth->expects(self::never())->method('requireAction');
		$this->consolidation->expects(self::never())->method('editDraftContent');

		$response = $this->controller()->content('nope');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Draft not found'], $response->getData());
	}//end testContentMissingDraftIs404()

	/**
	 * The edit hands the request's frontmatter/body/files and the editor to the
	 * pipeline and answers with the re-qualified draft.
	 *
	 * @return void
	 */
	public function testContentPassesReplacementAndEditor(): void {
		$draft = $this->guardedDraft();
		$this->consolidation->method('getDraft')->with('draft-1')->willReturn($draft);
		$this->actionAuth->expects(self::once())->method('requireAction')
			->with(self::isInstanceOf(IUser::class), 'skill.review-draft');
		$this->request->method('getParam')->willReturnMap(
			[
				['frontmatter', null, 'name: x'],
				['body', null, 'new body'],
				['files', null, ['a.md']],
			]
		);
		$updated = $this->entity('draft-1', ['body' => 'new body', 'editedBeforeAccept' => true]);
		$this->consolidation->expects(self::once())->method('editDraftContent')
			->with($draft, ['frontmatter' => 'name: x', 'body' => 'new body', 'files' => ['a.md']], 'bob')
			->willReturn($updated);

		$response = $this->controller('bob')->content('draft-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			['id' => 'draft-1', 'body' => 'new body', 'editedBeforeAccept' => true, 'uuid' => 'draft-1'],
			$response->getData()
		);
	}//end testContentPassesReplacementAndEditor()

	/**
	 * A refused edit (InvalidArgumentException, e.g. draft already decided) is a
	 * 409 carrying the reason, and is not logged as a failure.
	 *
	 * @return void
	 */
	public function testContentRefusedEditIs409(): void {
		$draft = $this->guardedDraft();
		$this->consolidation->method('getDraft')->willReturn($draft);
		$this->consolidation->expects(self::once())->method('editDraftContent')
			->willThrowException(new InvalidArgumentException('Draft is no longer editable'));
		$this->logger->expects(self::never())->method('error');

		$response = $this->controller()->content('draft-1');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame(['error' => 'Draft is no longer editable'], $response->getData());
	}//end testContentRefusedEditIs409()

	/**
	 * Any other edit failure is logged and answered with a generic 500.
	 *
	 * @return void
	 */
	public function testContentFailureIsLogged500(): void {
		$draft = $this->guardedDraft();
		$this->consolidation->method('getDraft')->willReturn($draft);
		$this->consolidation->method('editDraftContent')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects(self::once())->method('error')
			->with('Hermiq draft content edit failed: db down', self::arrayHasKey('exception'));

		$response = $this->controller()->content('draft-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Edit failed'], $response->getData());
	}//end testContentFailureIsLogged500()

	/**
	 * A reject whose draft is gone on the post-decision re-read answers with a
	 * null draft rather than the stale pre-decision snapshot.
	 *
	 * @return void
	 */
	public function testRejectWithDraftGoneOnReReadAnswersNullDraft(): void {
		$draft = $this->guardedDraft();
		$this->consolidation->expects(self::exactly(2))->method('getDraft')->with('draft-1')
			->willReturnOnConsecutiveCalls($draft, null);
		$this->request->method('getParam')->willReturnMap(
			[
				['note', '', ''],
				['rejectedLearningRefs', [], []],
			]
		);
		$approval = $this->entity('appr-1', []);
		$this->approvalService->method('loadApproval')->with('appr-1')->willReturn($approval);
		$this->approvalService->expects(self::once())->method('deny')->with($approval, 'alice', '');

		$response = $this->controller()->reject('draft-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['status' => 'rejected', 'draft' => null], $response->getData());
	}//end testRejectWithDraftGoneOnReReadAnswersNullDraft()
}//end class
