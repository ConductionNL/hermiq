<?php

/**
 * Unit tests for AgentGitController (agents-export-import-and-git-sync, tasks 3 to 5).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agents-export-import-and-git-sync/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AgentGitController;
use OCA\Hermiq\Service\Agent\AgentGitService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The HTTP face of "Keep in git": statuses from the service, coordinates never from the request.
 */
final class AgentGitControllerTest extends TestCase {

	public function testUnauthenticatedIs401(): void {
		$git = $this->createMock(AgentGitService::class);
		$git->expects(self::never())->method('push');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(git: $git, params: [], user: false)->push('agent-1')->getStatus());
	}//end testUnauthenticatedIs401()

	public function testAPushIgnoresCoordinatesInTheRequest(): void {
		$git = $this->createMock(AgentGitService::class);
		$git->expects(self::once())->method('push')
			->with('agent-1', 'alice', 'gh-1')
			->willReturn(['repoUrl' => 'https://github.com/gemeente-x/complaint-router-agent', 'commitSha' => 'beef']);

		$response = $this->controller(git: $git, params: ['credentialId' => 'gh-1', 'owner' => 'attacker', 'repo' => 'elsewhere', 'githubOwner' => 'attacker'])->push('agent-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testAPushIgnoresCoordinatesInTheRequest()

	public function testThePublishPassesTheFormFields(): void {
		$git = $this->createMock(AgentGitService::class);
		$git->expects(self::once())->method('publish')
			->with('agent-1', 'alice', 'gemeente-x', 'complaint-router-agent', 'private', 'gh-1')
			->willReturn(['repoUrl' => 'https://github.com/gemeente-x/complaint-router-agent', 'commitSha' => 'c0ffee']);

		$response = $this->controller(git: $git, params: ['githubOwner' => 'gemeente-x', 'repo' => 'complaint-router-agent', 'credentialId' => 'gh-1'])->publish('agent-1');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testThePublishPassesTheFormFields()

	/**
	 * Each service refusal reaches the client with its own status and reason.
	 *
	 * @return array<string, array{0: int}>
	 */
	public static function refusals(): array {
		return ['not found' => [404], 'not the owner' => [403], 'not kept in git' => [409], 'dangerous prompt' => [422], 'GitHub refused' => [502]];
	}//end refusals()

	/**
	 * @dataProvider refusals
	 */
	public function testARefusalKeepsItsStatusAndReason(int $status): void {
		$git = $this->createMock(AgentGitService::class);
		$git->method('pullApply')->willThrowException(new RuntimeException('the reason', $status));

		$response = $this->controller(git: $git, params: ['credentialId' => 'gh-1'])->pullApply('agent-1');

		self::assertSame($status, $response->getStatus());
		self::assertSame('the reason', $response->getData()['error']);
	}//end testARefusalKeepsItsStatusAndReason()

	public function testAnUnexpectedFailureIs500WithoutDetail(): void {
		$git = $this->createMock(AgentGitService::class);
		$git->method('pullPreview')->willThrowException(new RuntimeException('/var/www secret', 0));

		$response = $this->controller(git: $git, params: ['credentialId' => 'gh-1'])->pullPreview('agent-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsString('/var/www', (string)json_encode($response->getData()));
	}//end testAnUnexpectedFailureIs500WithoutDetail()

	public function testAConfirmedPullAnswersWithTheAgent(): void {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject(['name' => 'Complaint router', 'gitOwner' => 'gemeente-x', 'gitRepo' => 'complaint-router-agent']);
		$git = $this->createMock(AgentGitService::class);
		$git->method('pullApply')->willReturn($agent);

		$response = $this->controller(git: $git, params: ['credentialId' => 'gh-1'])->pullApply('agent-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('Complaint router', $response->getData()['name']);
	}//end testAConfirmedPullAnswersWithTheAgent()

	/**
	 * The controller under test.
	 *
	 * @param AgentGitService $git The service.
	 * @param array<string, mixed> $params The request params.
	 * @param bool $user Whether alice is signed in.
	 *
	 * @return AgentGitController
	 */
	private function controller(AgentGitService $git, array $params, bool $user = true): AgentGitController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		$session = $this->createMock(IUserSession::class);
		if ($user === true) {
			$alice = $this->createMock(IUser::class);
			$alice->method('getUID')->willReturn('alice');
			$session->method('getUser')->willReturn($alice);
		} else {
			$session->method('getUser')->willReturn(null);
		}

		return new AgentGitController(request: $request, git: $git, userSession: $session, logger: new NullLogger());
	}//end controller()
}//end class
