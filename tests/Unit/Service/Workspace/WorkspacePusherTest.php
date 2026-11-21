<?php

/**
 * WorkspacePusher: a workspace branch is pushed as the run owner, with the
 * forge credential in the environment, never in argv, and a refused push
 * carries no transport output.
 *
 * The git process is a double (it would reach the network); the forge
 * locator and credential resolver are real, over doubles for the egress
 * verdict, the credential store and the broker.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Workspace
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
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-a-failed-push-does-not-leak-the-credential
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Workspace;

use OCA\Hermiq\Service\Credential\CredentialScopeResolver;
use OCA\Hermiq\Service\WebResearch\WebResearchEgressGuard;
use OCA\Hermiq\Service\WebResearch\WebResearchSettingsHandler;
use OCA\Hermiq\Service\Workspace\ForgeCredentialResolver;
use OCA\Hermiq\Service\Workspace\ForgeLocator;
use OCA\Hermiq\Service\Workspace\GitRunner;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspacePusher;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Push arguments, credential handling, refusals and the agent lookup.
 *
 * @covers \OCA\Hermiq\Service\Workspace\WorkspacePusher
 * @uses   \OCA\Hermiq\Service\Workspace\ForgeCredentialResolver
 * @uses   \OCA\Hermiq\Service\Workspace\ForgeLocator
 * @uses   \OCA\Hermiq\Service\Workspace\PushCredentialRun
 * @uses   \OCA\Hermiq\Service\Workspace\CredentialTarget
 * @uses   \OCA\Hermiq\Service\Workspace\WorkspaceException
 */
final class WorkspacePusherTest extends TestCase {

	private const RUN = ['runId' => 'run-1', 'agentId' => 'agent-1', 'userId' => 'alice'];

	/**
	 * The git calls, in order: arguments, working dir, timeout, extra env.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * The exit code the push itself returns.
	 *
	 * @var int
	 */
	private int $pushExit = 0;

	/**
	 * The exit code rev-parse returns.
	 *
	 * @var int
	 */
	private int $revParseExit = 0;

	/**
	 * Whether the egress policy allows the forge.
	 *
	 * @var bool
	 */
	private bool $egressAllowed = true;

	/**
	 * The agents the store was asked for.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $agentLookups = [];

	/**
	 * A push sends the local branch to the same branch, hooks skipped, with the
	 * credential as a header in the environment only.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-the-forge-credential-is-absent-from-the-models-container
	 */
	public function testAPushSendsTheBranchWithTheCredentialInTheEnvironment(): void {
		$result = $this->pusher(agent: $this->agent())->push(
			run: self::RUN,
			root: '/ws/run-1',
			repository: 'example-org/example-app',
			branch: 'feature-a',
			arguments: ['repository' => 'example-org/example-app', 'branch' => 'feature-a']
		);

		self::assertSame(
			['repository' => 'example-org/example-app', 'branch' => 'feature-a', 'sha' => 'abc123', 'credentialId' => 'forge-alice'],
			$result
		);
		self::assertCount(2, $this->calls);
		self::assertSame(['rev-parse', '--verify', '--quiet', 'refs/heads/feature-a'], $this->calls[0]['arguments']);
		self::assertSame('/ws/run-1', $this->calls[0]['workingDir']);
		self::assertSame([], $this->calls[0]['extraEnv']);

		self::assertSame(
			['push', '--porcelain', '--no-verify', 'https://git.example.org/example-org/example-app.git', 'refs/heads/feature-a:refs/heads/feature-a'],
			$this->calls[1]['arguments']
		);
		self::assertSame('/ws/run-1', $this->calls[1]['workingDir']);
		self::assertSame(60, $this->calls[1]['timeoutSeconds']);
		self::assertSame(
			[
				'GIT_CONFIG_COUNT' => '1',
				'GIT_CONFIG_KEY_0' => 'http.extraHeader',
				'GIT_CONFIG_VALUE_0' => 'Authorization: Basic ' . base64_encode('x-access-token:secret-of-forge-alice'),
			],
			$this->calls[1]['extraEnv']
		);
		self::assertStringNotContainsString('secret-of-forge-alice', implode(' ', $this->calls[1]['arguments']));
		self::assertSame([['id' => 'agent-1', 'register' => 'hermiq', 'schema' => 'agent', '_rbac' => false, '_multitenancy' => false]], $this->agentLookups);
	}//end testAPushSendsTheBranchWithTheCredentialInTheEnvironment()

	/**
	 * A push that names another repository, or none, is refused before git runs.
	 *
	 * @return void
	 */
	public function testAPushOfAnotherRepositoryIsRefusedBeforeGitRuns(): void {
		foreach ([['repository' => 'example-org/other'], []] as $arguments) {
			$this->assertRefused(
				code: WorkspaceException::INVALID_ARGUMENT,
				message: 'Push the repository this run opened (example-org/example-app), and name it.',
				arguments: $arguments
			);
		}

		self::assertSame([], $this->calls);
	}//end testAPushOfAnotherRepositoryIsRefusedBeforeGitRuns()

	/**
	 * A branch the workspace does not have is refused, and nothing is pushed.
	 *
	 * @return void
	 */
	public function testAMissingBranchIsRefused(): void {
		$this->revParseExit = 1;

		$this->assertRefused(code: WorkspaceException::INVALID_ARGUMENT, message: 'That branch does not exist in the workspace.');

		self::assertCount(1, $this->calls);
	}//end testAMissingBranchIsRefused()

	/**
	 * A push the forge refuses gives a fixed sentence without git's output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-a-failed-push-does-not-leak-the-credential
	 */
	public function testARefusedPushCarriesNoTransportOutput(): void {
		$this->pushExit = 128;

		$this->assertRefused(
			code: WorkspaceException::PUSH_REJECTED,
			message: 'The forge refused the push. The branch may be protected, behind the remote, or outside what the credential may write.'
		);

		self::assertCount(2, $this->calls);
	}//end testARefusedPushCarriesNoTransportOutput()

	/**
	 * An egress denial stops the push before git reaches the forge.
	 *
	 * @return void
	 */
	public function testAnEgressDenialStopsThePush(): void {
		$this->egressAllowed = false;

		$this->assertRefused(code: WorkspaceException::EGRESS_DENIED, message: 'The egress policy does not allow this run to reach the forge.');

		self::assertCount(1, $this->calls, 'Only the local rev-parse ran.');
	}//end testAnEgressDenialStopsThePush()

	/**
	 * An agent the store cannot read, or does not return, still pushes with the
	 * owner's personal credential; its pins then count as none.
	 *
	 * @return void
	 */
	public function testAnUnreadableAgentPushesWithoutItsPins(): void {
		foreach ([new RuntimeException('gone'), null] as $agent) {
			$this->calls = [];
			$result = $this->pusher(agent: $agent, expectedPins: null)->push(
				run: self::RUN,
				root: '/ws/run-1',
				repository: 'example-org/example-app',
				branch: 'feature-a',
				arguments: ['repository' => 'example-org/example-app']
			);

			self::assertSame('forge-alice', $result['credentialId']);
			self::assertCount(2, $this->calls);
		}
	}//end testAnUnreadableAgentPushesWithoutItsPins()

	/**
	 * Assert the default push is refused with a code and message, and holds no secret.
	 *
	 * @param string               $code      The expected error code.
	 * @param string               $message   The expected message.
	 * @param array<string, mixed> $arguments The call's arguments.
	 *
	 * @return void
	 */
	private function assertRefused(string $code, string $message, array $arguments = ['repository' => 'example-org/example-app']): void {
		try {
			$this->pusher(agent: $this->agent())->push(
				run: self::RUN,
				root: '/ws/run-1',
				repository: 'example-org/example-app',
				branch: 'feature-a',
				arguments: $arguments
			);
			self::fail('Expected ' . $code);
		} catch (WorkspaceException $e) {
			self::assertSame($code, $e->getErrorCode());
			self::assertSame($message, $e->getMessage());
			self::assertStringNotContainsString('secret-of', $e->getMessage());
		}
	}//end assertRefused()

	/**
	 * The pusher under test.
	 *
	 * @param ObjectEntity|RuntimeException|null $agent        What the store answers for the agent.
	 * @param array<string, string>|null         $expectedPins The pins the credential lookup must carry.
	 *
	 * @return WorkspacePusher
	 */
	private function pusher(ObjectEntity|RuntimeException|null $agent, ?array $expectedPins = ['github' => 'forge-alice']): WorkspacePusher {
		$git = $this->createMock(GitRunner::class);
		$git->method('run')->willReturnCallback(
			function (array $arguments, ?string $workingDir, int $timeoutSeconds, array $extraEnv = [], ?string $stdin = null): array {
				$this->calls[] = ['arguments' => $arguments, 'workingDir' => $workingDir, 'timeoutSeconds' => $timeoutSeconds, 'extraEnv' => $extraEnv];
				if ($arguments[0] === 'rev-parse') {
					return ['exit' => $this->revParseExit, 'stdout' => "abc123\n", 'stderr' => ''];
				}

				return ['exit' => $this->pushExit, 'stdout' => '', 'stderr' => 'remote: denied for x-access-token:secret-of-forge-alice'];
			}
		);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true) use ($agent): ?ObjectEntity {
				$this->agentLookups[] = ['id' => $id, 'register' => $options?->register, 'schema' => $options?->schema, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				if ($agent instanceof RuntimeException) {
					throw $agent;
				}

				return $agent;
			}
		);

		$scopes = $this->createMock(CredentialScopeResolver::class);
		$scopes->method('resolve')->willReturnCallback(
			static function (string $provider, string $actingUserId, ?string $organisation, ?array $pinned) use ($expectedPins): ?string {
				self::assertSame('github', $provider);
				self::assertSame('alice', $actingUserId);
				self::assertSame($expectedPins, $pinned);
				return 'forge-alice';
			}
		);
		$scopes->method('scopeOfCredential')->willReturn('personal');
		$scopes->method('ownerOfCredential')->willReturn('alice');

		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('resolveInjectable')->willReturnCallback(static fn (string $credentialId): string => 'secret-of-' . $credentialId);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($broker);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'workspace_forge_base_url' ? 'https://git.example.org' : $default)
		);

		$guard = $this->createMock(WebResearchEgressGuard::class);
		$guard->method('assertSafe')->willReturnCallback(fn (): array => ['allowed' => $this->egressAllowed, 'code' => null, 'message' => null]);
		$settings = $this->createMock(WebResearchSettingsHandler::class);
		$settings->method('getWebResearchSettingsOnly')->willReturn(['fetchAllowlist' => [], 'fetchDenylist' => []]);

		return new WorkspacePusher(
			forge: new ForgeLocator(appConfig: $appConfig, guard: $guard, settings: $settings),
			credentials: new ForgeCredentialResolver(scopes: $scopes, appConfig: $appConfig, container: $container),
			git: $git,
			objects: $objects
		);
	}//end pusher()

	/**
	 * The run's agent, pinning the owner's forge credential.
	 *
	 * @return ObjectEntity
	 */
	private function agent(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('agent-1');
		$entity->setObject(['credentialIds' => ['github' => 'forge-alice']]);
		return $entity;
	}//end agent()
}//end class
