<?php

/**
 * Hermiq SkillVersionController::republish golden tests.
 *
 * Pins every answer of the one-click republish before it is split into named
 * steps: the 401, the visibility 404 before the action check, the 403 from the
 * publish action, the 400 for a skill with no provenance, the 422 for a missing
 * credential, the fail-closed 503, the export 404, the refused and failed push,
 * and the 201 with its restamp and audit. Each refusal is also pinned to stop
 * before the push.
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
 * Golden answers of SkillVersionController::republish.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The controller's own collaborator set.
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-an-accepted-version-behind-the-published-copy-raises-an-explicit-republish-signal
 */
class SkillVersionControllerRepublishGoldenTest extends TestCase {

	/**
	 * @var SkillService&MockObject
	 */
	private SkillService&MockObject $skillService;

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
			$this->createMock(SkillVersionService::class),
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
	 * Wire a republish that gets as far as the push.
	 *
	 * @param string|null $credentialId The credentialId request parameter.
	 *
	 * @return void
	 */
	private function readyToPush(?string $credentialId = 'cred-1'): void {
		$this->skillService->method('getSkill')->willReturn($this->skill());
		$this->request->method('getParam')->willReturnMap([['credentialId', null, $credentialId]]);
		$this->pushService->method('isBrokerAvailable')->willReturn(true);
		$this->skillService->method('exportSkill')->willReturn('PACKAGE');
	}//end readyToPush()

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
	 * Nobody signed in: 401 before anything is read.
	 *
	 * @return void
	 */
	public function testUnauthenticated(): void {
		$controller = $this->controller(signedIn: false);
		$this->skillService->expects($this->never())->method('getSkill');
		$this->assertAnswer($controller->republish('skill-1'), [Http::STATUS_UNAUTHORIZED, ['error' => 'Unauthenticated']]);
	}//end testUnauthenticated()

	/**
	 * An invisible or failing skill lookup is 404, before the action check.
	 *
	 * @return void
	 */
	public function testInvisibleSkillIs404BeforeTheActionCheck(): void {
		foreach (['null', 'throws'] as $case) {
			$controller = $this->controller();
			if ($case === 'null') {
				$this->skillService->method('getSkill')->willReturn(null);
			} else {
				$this->skillService->method('getSkill')->willThrowException(new RuntimeException('gone'));
			}

			$this->actionAuth->expects($this->never())->method('requireAction');
			$this->assertAnswer($controller->republish('skill-1'), [Http::STATUS_NOT_FOUND, ['error' => 'Not found']]);
		}
	}//end testInvisibleSkillIs404BeforeTheActionCheck()

	/**
	 * The publish action refuses: 403 with its message, nothing else read.
	 *
	 * @return void
	 */
	public function testActionRefusedIs403(): void {
		$controller = $this->controller();
		$this->skillService->method('getSkill')->willReturn($this->skill());
		$this->actionAuth->expects($this->once())->method('requireAction')
			->with($this->anything(), 'skill.publish-hub')
			->willThrowException(new OCSForbiddenException('No publish for you'));
		$this->request->expects($this->never())->method('getParam');
		$this->assertAnswer($controller->republish('skill-1'), [Http::STATUS_FORBIDDEN, ['error' => 'No publish for you']]);
	}//end testActionRefusedIs403()

	/**
	 * A skill without both provenance coordinates is 400, before the credential is read.
	 *
	 * @return void
	 */
	public function testUnpublishedSkillIs400(): void {
		foreach ([[], ['githubOwner' => 'acme'], ['githubRepo' => 'x'], ['githubOwner' => '', 'githubRepo' => 'x']] as $data) {
			$controller = $this->controller();
			$this->skillService->method('getSkill')->willReturn($this->skill($data));
			$this->request->expects($this->never())->method('getParam');
			$this->assertAnswer($controller->republish('skill-1'), [Http::STATUS_BAD_REQUEST, ['error' => 'not_published']]);
		}
	}//end testUnpublishedSkillIs400()

	/**
	 * No credentialId is 422, before the broker is asked.
	 *
	 * @return void
	 */
	public function testMissingCredentialIs422(): void {
		foreach ([null, ''] as $credentialId) {
			$controller = $this->controller();
			$this->skillService->method('getSkill')->willReturn($this->skill());
			$this->request->method('getParam')->willReturnMap([['credentialId', null, $credentialId]]);
			$this->pushService->expects($this->never())->method('isBrokerAvailable');
			$this->assertAnswer(
				$controller->republish('skill-1'),
				[Http::STATUS_UNPROCESSABLE_ENTITY, ['error' => 'A broker credentialId is required to republish']]
			);
		}
	}//end testMissingCredentialIs422()

	/**
	 * No broker is 503, before the export.
	 *
	 * @return void
	 */
	public function testNoBrokerIs503(): void {
		$controller = $this->controller();
		$this->skillService->method('getSkill')->willReturn($this->skill());
		$this->request->method('getParam')->willReturnMap([['credentialId', null, 'cred-1']]);
		$this->pushService->method('isBrokerAvailable')->willReturn(false);
		$this->skillService->expects($this->never())->method('exportSkill');
		$this->assertAnswer(
			$controller->republish('skill-1'),
			[Http::STATUS_SERVICE_UNAVAILABLE, ['error' => 'The GitHub credential broker is not available']]
		);
	}//end testNoBrokerIs503()

	/**
	 * An export that yields nothing is 404, before the push.
	 *
	 * @return void
	 */
	public function testNoExportIs404(): void {
		$controller = $this->controller();
		$this->skillService->method('getSkill')->willReturn($this->skill());
		$this->request->method('getParam')->willReturnMap([['credentialId', null, 'cred-1']]);
		$this->pushService->method('isBrokerAvailable')->willReturn(true);
		$this->skillService->method('exportSkill')->willReturn(null);
		$this->pushService->expects($this->never())->method('pushUpdate');
		$this->assertAnswer($controller->republish('skill-1'), [Http::STATUS_NOT_FOUND, ['error' => 'Not found']]);
	}//end testNoExportIs404()

	/**
	 * A refused push is 422 with its message and a warning; nothing is stamped.
	 *
	 * @return void
	 */
	public function testRefusedPushIs422(): void {
		$controller = $this->controller();
		$this->readyToPush();
		$this->pushService->method('pushUpdate')->willThrowException(new RuntimeException('Repo moved'));
		$this->logger->expects($this->once())->method('warning')->with('Hermiq skill republish refused: Repo moved');
		$this->skillService->expects($this->never())->method('stampGithubPublish');
		$this->audit->expects($this->never())->method('recordObjectEvent');
		$this->assertAnswer($controller->republish('skill-1'), [Http::STATUS_UNPROCESSABLE_ENTITY, ['error' => 'Repo moved']]);
	}//end testRefusedPushIs422()

	/**
	 * A push that fails otherwise, or a file selection that fails, is 500 with an error log.
	 *
	 * @return void
	 */
	public function testFailedPushIs500(): void {
		foreach (['push', 'selection'] as $case) {
			$controller = $this->controller();
			$this->readyToPush();
			if ($case === 'push') {
				$this->pushService->method('pushUpdate')->willThrowException(new Error('boom'));
			} else {
				$this->skillService->method('publishFileSelection')->willThrowException(new Error('boom'));
				$this->pushService->expects($this->never())->method('pushUpdate');
			}

			$this->logger->expects($this->once())->method('error')->with('Hermiq skill republish failed: boom', $this->anything());
			$this->skillService->expects($this->never())->method('stampGithubPublish');
			$this->assertAnswer($controller->republish('skill-1'), [Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Republish failed']]);
		}
	}//end testFailedPushIs500()

	/**
	 * A successful republish pushes to the stamped repo with the route id, restamps and audits.
	 *
	 * @return void
	 */
	public function testSuccessfulRepublish(): void {
		$controller = $this->controller();
		$this->readyToPush();
		$this->skillService->expects($this->once())->method('exportSkill')->with('skill-1');
		$this->skillService->expects($this->once())->method('publishFileSelection')->with('skill-1')->willReturn(null);
		$this->pushService->expects($this->once())->method('pushUpdate')
			->with('PACKAGE', 'acme', 'hermiq-skill-x', 'cred-1', 'alice', GitHubTemplatePushService::KIND_SKILL, [])
			->willReturn(['repoUrl' => 'u', 'commitSha' => 'sha-1']);
		$this->skillService->expects($this->once())->method('stampGithubPublish')
			->with('skill-1', 'acme', 'hermiq-skill-x', $this->matchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/'));
		$this->audit->expects($this->once())->method('recordObjectEvent');
		$this->request->expects($this->once())->method('getParam');

		$this->assertAnswer($controller->republish('skill-1'), [Http::STATUS_CREATED, ['repoUrl' => 'u', 'commitSha' => 'sha-1']]);
	}//end testSuccessfulRepublish()
}//end class
