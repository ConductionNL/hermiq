<?php

/**
 * Hermiq SkillMarketplaceController golden tests.
 *
 * Pins every answer of installFromSource(), approve(), publish() and
 * githubPublish() before they are split into named steps: each refusal with
 * its exact body, the order in which the guards run (and what each one stops
 * from being read or called), the arguments handed to the services, the
 * normalised `source` and `visibility`, and every failure with its log line.
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
use OCA\Hermiq\Controller\SkillMarketplaceController;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\GitHubTemplatePushService;
use OCA\Hermiq\Service\SkillMarketplaceService;
use OCA\Hermiq\Service\SkillService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden answers of SkillMarketplaceController.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The controller's own collaborator set.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   One test per answer.
 *
 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
 */
class SkillMarketplaceControllerGoldenTest extends TestCase {

	/**
	 * @var SkillMarketplaceService&MockObject
	 */
	private SkillMarketplaceService&MockObject $marketplace;

	/**
	 * @var ActionAuthService&MockObject
	 */
	private ActionAuthService&MockObject $actionAuth;

	/**
	 * @var SkillService&MockObject
	 */
	private SkillService&MockObject $skills;

	/**
	 * @var GitHubTemplatePushService&MockObject
	 */
	private GitHubTemplatePushService&MockObject $push;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Every request parameter read, in order.
	 *
	 * @var array<int, string>
	 */
	private array $reads = [];

	/**
	 * Build the controller for `alice` (or nobody) over the given request params.
	 *
	 * @param array<string, mixed> $params The request params.
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return SkillMarketplaceController
	 */
	private function controller(array $params = [], bool $signedIn = true): SkillMarketplaceController {
		$this->marketplace = $this->createMock(SkillMarketplaceService::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
		$this->skills = $this->createMock(SkillService::class);
		$this->push = $this->createMock(GitHubTemplatePushService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->reads = [];

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, $default = null) use ($params) {
				$this->reads[] = $key;
				return array_key_exists($key, $params) === true ? $params[$key] : $default;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$session->method('getUser')->willReturn($user);

		return new SkillMarketplaceController(
			$request,
			$this->marketplace,
			$this->actionAuth,
			$session,
			$this->logger,
			$this->skills,
			$this->push
		);
	}//end controller()

	/**
	 * A skill entity.
	 *
	 * @param array<string, mixed> $data The skill data.
	 *
	 * @return ObjectEntity
	 */
	private function skill(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('uuid-1');
		$entity->setObject($data);
		return $entity;
	}//end skill()

	/**
	 * Assert status and body.
	 *
	 * @param JSONResponse $response The response.
	 * @param int $status The expected status.
	 * @param mixed $data The expected body.
	 *
	 * @return void
	 */
	private function answer(JSONResponse $response, int $status, mixed $data): void {
		$this->assertSame($status, $response->getStatus());
		$this->assertSame($data, $response->getData());
	}//end answer()

	/**
	 * Every action answers 401 to nobody, before reading anything.
	 *
	 * @return void
	 */
	public function testEveryActionIs401WithoutAUser(): void {
		foreach (['installFromSource', 'approve', 'publish', 'githubPublish'] as $action) {
			$controller = $this->controller(['package' => 'p'], signedIn: false);
			$this->actionAuth->expects($this->never())->method('requireAction');
			$response = $action === 'installFromSource' ? $controller->installFromSource() : $controller->{$action}('skill-1');
			$this->answer($response, Http::STATUS_UNAUTHORIZED, ['error' => 'Unauthenticated']);
			$this->assertSame([], $this->reads, $action);
		}
	}//end testEveryActionIs401WithoutAUser()

	/**
	 * installFromSource refuses a blank package, then a non-array files list.
	 *
	 * @return void
	 */
	public function testInstallRefusals(): void {
		foreach (['', '   '] as $package) {
			$controller = $this->controller(['package' => $package, 'files' => 'x']);
			$this->marketplace->expects($this->never())->method('installFromSource');
			$this->answer($controller->installFromSource(), Http::STATUS_BAD_REQUEST, ['error' => 'A non-empty package is required']);
			$this->assertSame(['package', 'source'], $this->reads);
		}

		$controller = $this->controller(['package' => 'p', 'files' => 'x']);
		$this->marketplace->expects($this->never())->method('installFromSource');
		$this->answer($controller->installFromSource(), Http::STATUS_BAD_REQUEST, ['error' => 'files must be an array']);
	}//end testInstallRefusals()

	/**
	 * installFromSource normalises the source, passes the caller and files, and shapes the result.
	 *
	 * @return void
	 */
	public function testInstallSucceeds(): void {
		$sources = ['local' => 'local', 'org' => 'org', 'hub' => 'hub', 'elsewhere' => 'hub', null => 'hub'];
		foreach ($sources as $given => $expected) {
			$params = ['package' => 'PKG', 'files' => [['name' => 'a.md']]];
			if ($given !== '') {
				$params['source'] = $given;
			}

			$controller = $this->controller($params);
			$this->marketplace->expects($this->once())->method('installFromSource')
				->with('PKG', $expected, 'alice', [['name' => 'a.md']])
				->willReturn($this->skill(['name' => 'x', 'state' => 'quarantined']));
			$this->answer($controller->installFromSource(), Http::STATUS_OK, ['id' => 'uuid-1', 'name' => 'x', 'state' => 'quarantined', 'uuid' => 'uuid-1']);
		}

		$controller = $this->controller(['package' => 'PKG']);
		$this->marketplace->expects($this->once())->method('installFromSource')->with('PKG', 'hub', 'alice', [])
			->willThrowException(new Error('boom'));
		$this->logger->expects($this->once())->method('error')->with('Hermiq install-from-source failed: boom', $this->anything());
		$this->answer($controller->installFromSource(), Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Install failed']);
	}//end testInstallSucceeds()

	/**
	 * approve checks the approve action, and the override action only for a forced approve.
	 *
	 * @return void
	 */
	public function testApproveActionChecks(): void {
		$controller = $this->controller(['force' => '1']);
		$checked = [];
		$this->actionAuth->method('requireAction')->willReturnCallback(
			function ($user, string $action) use (&$checked): void {
				$checked[] = $action;
				if ($action === 'skill.override-scan-verdict') {
					throw new OCSForbiddenException('No override');
				}
			}
		);
		$this->marketplace->expects($this->never())->method('approveQuarantined');
		$this->answer($controller->approve('skill-1'), Http::STATUS_FORBIDDEN, ['error' => 'No override']);
		$this->assertSame(['skill.approve-quarantined', 'skill.override-scan-verdict'], $checked);

		$controller = $this->controller();
		$checked = [];
		$this->actionAuth->method('requireAction')->willReturnCallback(
			function ($user, string $action) use (&$checked): void {
				$checked[] = $action;
				throw new OCSForbiddenException('No approve');
			}
		);
		$this->answer($controller->approve('skill-1'), Http::STATUS_FORBIDDEN, ['error' => 'No approve']);
		$this->assertSame(['skill.approve-quarantined'], $checked);
	}//end testApproveActionChecks()

	/**
	 * approve answers 404, 409 for a blocked non-forced approve, 200, and 500 with a log line.
	 *
	 * @return void
	 */
	public function testApproveAnswers(): void {
		$controller = $this->controller();
		$this->marketplace->expects($this->once())->method('approveQuarantined')->with('skill-1', false)->willReturn(null);
		$this->answer($controller->approve('skill-1'), Http::STATUS_NOT_FOUND, ['error' => 'Not found']);

		$controller = $this->controller();
		$this->marketplace->method('approveQuarantined')->willReturn($this->skill(['state' => 'quarantined', 'scanReport' => ['x' => 1], 'quarantineReason' => 'why']));
		$this->answer(
			$controller->approve('skill-1'),
			Http::STATUS_CONFLICT,
			[
				'error' => 'Approval blocked: content scan flagged dangerous patterns.',
				'scanReport' => ['x' => 1],
				'quarantineReason' => 'why',
				'skill' => ['id' => 'uuid-1', 'state' => 'quarantined', 'scanReport' => ['x' => 1], 'quarantineReason' => 'why', 'uuid' => 'uuid-1'],
			]
		);

		$controller = $this->controller();
		$this->marketplace->method('approveQuarantined')->willReturn($this->skill(['state' => 'quarantined']));
		$this->answer(
			$controller->approve('skill-1'),
			Http::STATUS_CONFLICT,
			[
				'error' => 'Approval blocked: content scan flagged dangerous patterns.',
				'scanReport' => [],
				'quarantineReason' => '',
				'skill' => ['id' => 'uuid-1', 'state' => 'quarantined', 'uuid' => 'uuid-1'],
			]
		);

		$controller = $this->controller(['force' => true]);
		$this->marketplace->expects($this->once())->method('approveQuarantined')->with('skill-1', true)
			->willReturn($this->skill(['state' => 'quarantined']));
		$this->answer($controller->approve('skill-1'), Http::STATUS_OK, ['id' => 'uuid-1', 'state' => 'quarantined', 'uuid' => 'uuid-1']);

		$controller = $this->controller();
		$this->marketplace->method('approveQuarantined')->willReturn($this->skill(['state' => 'active']));
		$this->answer($controller->approve('skill-1'), Http::STATUS_OK, ['id' => 'uuid-1', 'state' => 'active', 'uuid' => 'uuid-1']);

		$controller = $this->controller();
		$this->marketplace->method('approveQuarantined')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with('Hermiq skill approve failed: db down', $this->anything());
		$this->answer($controller->approve('skill-1'), Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Approve failed']);
	}//end testApproveAnswers()

	/**
	 * publish checks the publish action, then publishes to the named hub (default `default`).
	 *
	 * @return void
	 */
	public function testPublishAnswers(): void {
		$controller = $this->controller(['hubId' => 'h1']);
		$this->actionAuth->expects($this->once())->method('requireAction')->with($this->anything(), 'skill.publish-hub')
			->willThrowException(new OCSForbiddenException('No hub'));
		$this->answer($controller->publish('skill-1'), Http::STATUS_FORBIDDEN, ['error' => 'No hub']);
		$this->assertSame([], $this->reads);

		foreach (['h1' => 'h1', '' => 'default'] as $given => $expected) {
			$controller = $this->controller($given === '' ? [] : ['hubId' => $given]);
			$this->marketplace->expects($this->once())->method('publishToHub')->with('skill-1', $expected)->willReturn(['ok' => true]);
			$this->answer($controller->publish('skill-1'), Http::STATUS_OK, ['ok' => true]);
		}

		$controller = $this->controller();
		$this->marketplace->method('publishToHub')->willThrowException(new Error('hub gone'));
		$this->logger->expects($this->once())->method('error')->with('Hermiq skill publish failed: hub gone', $this->anything());
		$this->answer($controller->publish('skill-1'), Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Publish failed']);
	}//end testPublishAnswers()

	/**
	 * githubPublish refuses in order: action, repo pattern, credential, visibility of the skill, broker.
	 *
	 * @return void
	 */
	public function testGithubPublishRefusals(): void {
		$controller = $this->controller(['owner' => 'acme', 'repo' => 'r', 'credentialId' => 'c']);
		$this->actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('No publish'));
		$this->answer($controller->githubPublish('skill-1'), Http::STATUS_FORBIDDEN, ['error' => 'No publish']);
		$this->assertSame([], $this->reads);

		foreach ([['owner' => 'a/b', 'repo' => 'r'], ['owner' => 'acme', 'repo' => ''], ['repo' => 'r'], ['owner' => 'acme', 'repo' => 'r r']] as $params) {
			$controller = $this->controller($params + ['credentialId' => '']);
			$this->skills->expects($this->never())->method('exportSkill');
			$this->answer($controller->githubPublish('skill-1'), Http::STATUS_BAD_REQUEST, ['error' => 'invalid_repo']);
			$this->assertSame(['owner', 'repo', 'visibility', 'credentialId'], $this->reads);
		}

		$controller = $this->controller(['owner' => 'acme', 'repo' => 'r']);
		$this->skills->expects($this->never())->method('exportSkill');
		$this->answer(
			$controller->githubPublish('skill-1'),
			Http::STATUS_UNPROCESSABLE_ENTITY,
			['error' => 'A broker credentialId is required to publish']
		);

		$controller = $this->controller(['owner' => 'acme', 'repo' => 'r', 'credentialId' => 'c']);
		$this->skills->expects($this->once())->method('exportSkill')->with('skill-1')->willReturn(null);
		$this->push->expects($this->never())->method('isBrokerAvailable');
		$this->answer($controller->githubPublish('skill-1'), Http::STATUS_NOT_FOUND, ['error' => 'Not found']);

		$controller = $this->controller(['owner' => 'acme', 'repo' => 'r', 'credentialId' => 'c']);
		$this->skills->method('exportSkill')->willReturn('PKG');
		$this->push->method('isBrokerAvailable')->willReturn(false);
		$this->push->expects($this->never())->method('push');
		$this->answer(
			$controller->githubPublish('skill-1'),
			Http::STATUS_SERVICE_UNAVAILABLE,
			['error' => 'The GitHub credential broker is not available']
		);
	}//end testGithubPublishRefusals()

	/**
	 * githubPublish pushes with the normalised visibility and the file selection, then stamps.
	 *
	 * @return void
	 */
	public function testGithubPublishSucceeds(): void {
		$visibilities = ['public' => 'public', 'private' => 'private', 'internal' => 'private', null => 'private'];
		foreach ($visibilities as $given => $expected) {
			$params = ['owner' => 'acme.co', 'repo' => 'demo_skill-1', 'credentialId' => 'cred-1'];
			if ($given !== '') {
				$params['visibility'] = $given;
			}

			$controller = $this->controller($params);
			$this->skills->method('exportSkill')->willReturn('PKG');
			$this->skills->expects($this->once())->method('publishFileSelection')->with('skill-1')->willReturn([['name' => 'learnings.md']]);
			$this->push->method('isBrokerAvailable')->willReturn(true);
			$this->push->expects($this->once())->method('push')
				->with('PKG', 'acme.co', 'demo_skill-1', $expected, 'cred-1', 'alice', GitHubTemplatePushService::KIND_SKILL, [['name' => 'learnings.md']])
				->willReturn(['repoUrl' => 'u', 'commitSha' => 's']);
			$this->skills->expects($this->once())->method('stampGithubPublish')
				->with('skill-1', 'acme.co', 'demo_skill-1', $this->matchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/'));
			$this->answer($controller->githubPublish('skill-1'), Http::STATUS_CREATED, ['repoUrl' => 'u', 'commitSha' => 's']);
		}
	}//end testGithubPublishSucceeds()

	/**
	 * A refused push is 422 with a warning; any other failure, also of the file selection, is 500.
	 *
	 * @return void
	 */
	public function testGithubPublishFailures(): void {
		$params = ['owner' => 'acme', 'repo' => 'r', 'credentialId' => 'c'];

		$controller = $this->controller($params);
		$this->skills->method('exportSkill')->willReturn('PKG');
		$this->push->method('isBrokerAvailable')->willReturn(true);
		$this->push->method('push')->willThrowException(new RuntimeException('exists'));
		$this->logger->expects($this->once())->method('warning')->with('Hermiq skill github publish refused: exists');
		$this->skills->expects($this->never())->method('stampGithubPublish');
		$this->answer($controller->githubPublish('skill-1'), Http::STATUS_UNPROCESSABLE_ENTITY, ['error' => 'exists']);

		foreach (['push', 'selection'] as $case) {
			$controller = $this->controller($params);
			$this->skills->method('exportSkill')->willReturn('PKG');
			$this->push->method('isBrokerAvailable')->willReturn(true);
			if ($case === 'push') {
				$this->push->method('push')->willThrowException(new Error('boom'));
			} else {
				$this->skills->method('publishFileSelection')->willThrowException(new Error('boom'));
				$this->push->expects($this->never())->method('push');
			}

			$this->logger->expects($this->once())->method('error')->with('Hermiq skill github publish failed: boom', $this->anything());
			$this->skills->expects($this->never())->method('stampGithubPublish');
			$this->answer($controller->githubPublish('skill-1'), Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Publish failed']);
		}
	}//end testGithubPublishFailures()
}//end class
