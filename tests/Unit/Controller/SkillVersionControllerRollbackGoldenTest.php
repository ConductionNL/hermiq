<?php

/**
 * Hermiq SkillVersionController::diff and ::rollback golden tests.
 *
 * Pins every answer of the version diff and rollback before they are split into
 * named steps: the owner guard, the 400 for missing version ids, the 404 for an
 * unknown version, the 500 for any other failure with its log line, and the
 * success answer with the rollback audit.
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
 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use Error;
use OCA\Hermiq\Controller\SkillVersionController;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\GitHubTemplatePushService;
use OCA\Hermiq\Service\SeedCustodyService;
use OCA\Hermiq\Service\SkillService;
use OCA\Hermiq\Service\SkillVersionService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden answers of SkillVersionController::diff and ::rollback.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The controller's own collaborator set.
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
 */
class SkillVersionControllerRollbackGoldenTest extends TestCase {

	/**
	 * @var SkillService&MockObject
	 */
	private SkillService&MockObject $skillService;

	/**
	 * @var SkillVersionService&MockObject
	 */
	private SkillVersionService&MockObject $versions;

	/**
	 * @var GitHubTemplatePushService&MockObject
	 */
	private GitHubTemplatePushService&MockObject $pushService;

	/**
	 * @var ActionAuthService&MockObject
	 */
	private ActionAuthService&MockObject $actionAuth;

	/**
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper&MockObject $audit;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Build the controller for `alice`, or for nobody.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return SkillVersionController
	 */
	private function controller(bool $signedIn = true): SkillVersionController {
		$this->skillService = $this->createMock(SkillService::class);
		$this->versions = $this->createMock(SkillVersionService::class);
		$this->pushService = $this->createMock(GitHubTemplatePushService::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
		$this->audit = $this->createMock(AuditTrailMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->request = $this->createMock(IRequest::class);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$session->method('getUser')->willReturn($user);

		return new SkillVersionController(
			$this->request,
			$this->skillService,
			$this->versions,
			$this->pushService,
			$this->actionAuth,
			$this->audit,
			new SeedCustodyService(groupManager: $this->createMock(IGroupManager::class)),
			$session,
			$this->logger
		);
	}//end controller()

	/**
	 * A skill with the given data.
	 *
	 * @param array<string, mixed> $data The skill data.
	 *
	 * @return ObjectEntity
	 */
	private function skill(array $data = ['githubOwner' => 'acme', 'githubRepo' => 'hermiq-skill-x']): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('uuid-of-skill');
		$entity->setOwner('alice');
		$entity->setObject($data);
		return $entity;
	}//end skill()

	/**
	 * Assert status and body.
	 *
	 * @param \OCP\AppFramework\Http\JSONResponse $response The response.
	 * @param array<int|string, mixed> $expected `[status, data]`.
	 *
	 * @return void
	 */
	private function assertAnswer(\OCP\AppFramework\Http\JSONResponse $response, array $expected): void {
		$this->assertSame($expected[0], $response->getStatus());
		$this->assertSame($expected[1], $response->getData());
	}//end assertAnswer()

	/**
	 * Wire request params.
	 *
	 * @param array<string, string> $params The params.
	 *
	 * @return void
	 */
	private function params(array $params): void {
		$map = [];
		foreach ($params as $name => $value) {
			$map[] = [$name, '', $value];
		}

		$this->request->method('getParam')->willReturnMap($map);
	}//end params()

	/**
	 * Nobody signed in, a missing skill and someone else's skill: 401, 404, 404.
	 *
	 * @return void
	 */
	public function testOwnerGuard(): void {
		$controller = $this->controller(signedIn: false);
		$this->assertAnswer($controller->diff('skill-1'), [Http::STATUS_UNAUTHORIZED, ['error' => 'Unauthenticated']]);
		$this->assertAnswer($controller->rollback('skill-1'), [Http::STATUS_UNAUTHORIZED, ['error' => 'Unauthenticated']]);

		$controller = $this->controller();
		$this->skillService->method('getSkill')->willReturn(null);
		$this->assertAnswer($controller->diff('skill-1'), [Http::STATUS_NOT_FOUND, ['error' => 'Skill not found']]);
		$this->assertAnswer($controller->rollback('skill-1'), [Http::STATUS_NOT_FOUND, ['error' => 'Skill not found']]);

		$controller = $this->controller();
		$other = $this->skill();
		$other->setOwner('bob');
		$this->skillService->method('getSkill')->willReturn($other);
		$this->versions->expects($this->never())->method('diff');
		$this->versions->expects($this->never())->method('rollback');
		$this->assertAnswer($controller->diff('skill-1'), [Http::STATUS_NOT_FOUND, ['error' => 'Skill not found']]);
		$this->assertAnswer($controller->rollback('skill-1'), [Http::STATUS_NOT_FOUND, ['error' => 'Skill not found']]);
	}//end testOwnerGuard()

	/**
	 * Diff needs both ids; an unknown version is 404; anything else is 500 with a log line.
	 *
	 * @return void
	 */
	public function testDiffAnswers(): void {
		foreach ([['from' => 'v1', 'to' => ''], ['from' => '', 'to' => 'v2'], []] as $params) {
			$controller = $this->controller();
			$this->skillService->method('getSkill')->willReturn($this->skill());
			$this->params($params);
			$this->versions->expects($this->never())->method('diff');
			$this->assertAnswer($controller->diff('skill-1'), [Http::STATUS_BAD_REQUEST, ['error' => 'Both from and to version ids are required']]);
		}

		$cases = [
			'unknown' => [new RuntimeException('nope'), [Http::STATUS_NOT_FOUND, ['error' => 'Version not found']], null],
			'failed' => [new Error('boom'), [Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Diff failed']], 'Hermiq skill diff failed: boom'],
		];
		foreach ($cases as $name => [$throwable, $answer, $logged]) {
			$controller = $this->controller();
			$this->skillService->method('getSkill')->willReturn($this->skill());
			$this->params(['from' => 'v1', 'to' => 'v2']);
			$this->versions->method('diff')->willThrowException($throwable);
			$this->logger->expects($logged === null ? $this->never() : $this->once())->method('error')
				->with($logged ?? $this->anything(), $this->anything());
			$this->assertAnswer($controller->diff('skill-1'), $answer);
		}

		$controller = $this->controller();
		$this->skillService->method('getSkill')->willReturn($this->skill());
		$this->params(['from' => 'v1', 'to' => 'v2']);
		$this->versions->expects($this->once())->method('diff')->with('skill-1', 'v1', 'v2')->willReturn(['body' => ['a', 'b']]);
		$this->assertAnswer($controller->diff('skill-1'), [Http::STATUS_OK, ['diff' => ['body' => ['a', 'b']]]]);
	}//end testDiffAnswers()

	/**
	 * Rollback needs a version id; an unknown version is 404; anything else is 500 with a log line.
	 *
	 * @return void
	 */
	public function testRollbackRefusals(): void {
		$controller = $this->controller();
		$this->skillService->method('getSkill')->willReturn($this->skill());
		$this->params(['versionId' => '']);
		$this->versions->expects($this->never())->method('rollback');
		$this->assertAnswer($controller->rollback('skill-1'), [Http::STATUS_BAD_REQUEST, ['error' => 'A versionId is required']]);

		$cases = [
			'unknown' => [new RuntimeException('nope'), [Http::STATUS_NOT_FOUND, ['error' => 'Version not found']], null],
			'failed' => [new Error('boom'), [Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Rollback failed']], 'Hermiq skill rollback failed: boom'],
		];
		foreach ($cases as $name => [$throwable, $answer, $logged]) {
			$controller = $this->controller();
			$this->skillService->method('getSkill')->willReturn($this->skill());
			$this->params(['versionId' => 'v1']);
			$this->versions->method('rollback')->willThrowException($throwable);
			$this->versions->expects($this->never())->method('currentVersionId');
			$this->audit->expects($this->never())->method('recordObjectEvent');
			$this->logger->expects($logged === null ? $this->never() : $this->once())->method('error')
				->with($logged ?? $this->anything(), $this->anything());
			$this->assertAnswer($controller->rollback('skill-1'), $answer);
		}
	}//end testRollbackRefusals()

	/**
	 * A rollback answers the new version id and audits target and new version.
	 *
	 * @return void
	 */
	public function testRollbackSucceeds(): void {
		foreach (['v9' => 'v9', 'none' => null] as $case => $current) {
			$controller = $this->controller();
			$this->skillService->method('getSkill')->willReturn($this->skill());
			$this->params(['versionId' => 'v1']);
			$updated = $this->skill();
			$this->versions->expects($this->once())->method('rollback')->with('skill-1', 'v1')->willReturn($updated);
			$this->versions->method('currentVersionId')->with('skill-1')->willReturn($current);
			$captured = null;
			$this->audit->expects($this->once())->method('recordObjectEvent')->willReturnCallback(
				function ($event) use (&$captured) {
					$captured = $event;
					return null;
				}
			);

			$this->assertAnswer(
				$controller->rollback('skill-1'),
				[Http::STATUS_OK, ['status' => 'rolled-back', 'versionId' => (string)$current]]
			);
			$this->assertSame($updated, $captured->object, $case);
			$this->assertSame('skill-version', $captured->action, $case);
			$context = $captured->context;
			$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $context['at'], $case);
			unset($context['at']);
			$this->assertSame(['transition' => 'rollback', 'targetVersionId' => 'v1', 'newVersionId' => (string)$current], $context, $case);
		}
	}//end testRollbackSucceeds()
}//end class
