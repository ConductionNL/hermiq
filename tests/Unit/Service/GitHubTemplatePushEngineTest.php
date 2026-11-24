<?php

/**
 * The store's publish commits through OpenRegister's federated config engine.
 *
 * Hermiq still creates the repository, refuses an existing one for a first
 * publish, checks that a republished one exists and sets its topics; the
 * commit itself goes through `FederatedConfigService::publishFiles()` on the
 * branch the repository reports, with hermiq's identity. When the engine
 * throws, hermiq commits through its own Git Data writer.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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
 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-3
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\GitHubTemplatePushService;
use OCA\OpenRegister\Service\Config\FederatedConfigService;
use OCA\OpenRegister\Service\Config\RepoFilesPublish;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for the engine route of {@see GitHubTemplatePushService}'s commits.
 */
final class GitHubTemplatePushEngineTest extends TestCase {
	/**
	 * Every broker call as `METHOD path`, in order.
	 *
	 * @var array<int, string>
	 */
	public array $brokerCalls = [];

	/**
	 * Whether the repository exists on the fake GitHub.
	 *
	 * @var bool
	 */
	public bool $repoExists = false;

	/**
	 * Reset the fake GitHub between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->brokerCalls = [];
		$this->repoExists = false;
	}//end setUp()

	/**
	 * A first publish: hermiq creates and tags the repository, the engine commits on its reported branch.
	 *
	 * @return void
	 */
	public function testAFirstPublishCommitsThroughTheEngine(): void {
		$engine = $this->engine(commit: 'engine-sha');

		$result = $this->service(engine: $engine)->push(
			package: '{"name":"Demo"}',
			owner: 'acme',
			repo: 'demo',
			visibility: 'private',
			credentialId: 'cred-uuid',
			actingUserId: 'alice'
		);

		self::assertSame(expected: 'engine-sha', actual: $result['commitSha']);
		self::assertContains(needle: 'POST /orgs/acme/repos', haystack: $this->brokerCalls, message: 'Hermiq creates the repository.');
		self::assertContains(needle: 'PUT /repos/acme/demo/topics', haystack: $this->brokerCalls, message: 'Hermiq sets the topics.');
		self::assertNotContains(needle: 'POST /repos/acme/demo/git/trees', haystack: $this->brokerCalls, message: 'No commit of its own.');

		self::assertCount(expectedCount: 1, haystack: $engine->requests);
		$request = $engine->requests[0];
		self::assertSame(expected: 'acme/demo', actual: $request->target->repo);
		self::assertSame(expected: 'trunk', actual: $request->target->branch, message: 'The branch the created repository reports.');
		self::assertTrue(condition: $request->target->private);
		self::assertFalse(condition: $request->target->allowsUserNamespaceFallback());
		self::assertSame(expected: ['hermiq-agent-template.json' => '{"name":"Demo"}'], actual: $request->files);
		self::assertSame(expected: [], actual: $request->topics, message: 'Topics were set by hermiq.');
		self::assertSame(expected: 'cred-uuid', actual: $request->credentialId);
		self::assertSame(expected: 'hermiq', actual: $request->identity->appId);
		self::assertSame(expected: 'alice', actual: $request->identity->actingUserId);
	}//end testAFirstPublishCommitsThroughTheEngine()

	/**
	 * A first publish into an existing repository is still refused, and the engine is never asked.
	 *
	 * @return void
	 */
	public function testAFirstPublishStillRefusesAnExistingRepository(): void {
		$this->repoExists = true;
		$engine = $this->engine(commit: 'engine-sha');

		$this->expectException(exception: RuntimeException::class);
		try {
			$this->service(engine: $engine)->push(package: '{}', owner: 'acme', repo: 'demo', visibility: 'public', credentialId: 'cred-uuid');
		} finally {
			self::assertSame(expected: [], actual: $engine->requests);
		}
	}//end testAFirstPublishStillRefusesAnExistingRepository()

	/**
	 * A republish commits the package and its safe auxiliary files on the repository's own branch.
	 *
	 * @return void
	 */
	public function testARepublishCommitsThroughTheEngine(): void {
		$this->repoExists = true;
		$engine = $this->engine(commit: 'engine-sha');

		$result = $this->service(engine: $engine)->pushUpdate(
			package: 'skill',
			owner: 'acme',
			repo: 'demo',
			credentialId: 'cred-uuid',
			actingUserId: 'alice',
			kind: GitHubTemplatePushService::KIND_SKILL,
			auxFiles: [
				['name' => 'references/steps.md', 'content' => 'steps'],
				['name' => '../escape.md', 'content' => 'nope'],
			]
		);

		self::assertSame(expected: 'engine-sha', actual: $result['commitSha']);
		$request = $engine->requests[0];
		self::assertSame(expected: 'trunk', actual: $request->target->branch);
		self::assertSame(expected: ['hermiq-skill.md' => 'skill', 'references/steps.md' => 'steps'], actual: $request->files);
	}//end testARepublishCommitsThroughTheEngine()

	/**
	 * A republish to a vanished repository is refused before the engine is asked.
	 *
	 * @return void
	 */
	public function testARepublishToAVanishedRepositoryIsRefused(): void {
		$engine = $this->engine(commit: 'engine-sha');

		$this->expectExceptionMessage(message: 'does not exist');
		try {
			$this->service(engine: $engine)->pushUpdate(package: 'skill', owner: 'acme', repo: 'demo', credentialId: 'cred-uuid');
		} finally {
			self::assertSame(expected: [], actual: $engine->requests, message: 'The engine would have created it.');
		}
	}//end testARepublishToAVanishedRepositoryIsRefused()

	/**
	 * A bundle publish hands the engine its safe files.
	 *
	 * @return void
	 */
	public function testABundlePublishCommitsThroughTheEngine(): void {
		$engine = $this->engine(commit: 'engine-sha');

		$this->service(engine: $engine)->publishBundle(
			files: ['hermiq-skills.json' => '{}', 'skills/a/SKILL.md' => '# a', '/etc/passwd' => 'x'],
			owner: 'acme',
			repo: 'demo',
			visibility: 'public',
			credentialId: 'cred-uuid'
		);

		self::assertSame(expected: ['hermiq-skills.json' => '{}', 'skills/a/SKILL.md' => '# a'], actual: $engine->requests[0]->files);
		self::assertFalse(condition: $engine->requests[0]->target->private);
	}//end testABundlePublishCommitsThroughTheEngine()

	/**
	 * When the engine throws, hermiq commits through its own Git Data writer.
	 *
	 * @return void
	 */
	public function testAFailingEngineFallsBackToTheOwnCommit(): void {
		$result = $this->service(engine: $this->engine(commit: null))->push(
			package: '{}',
			owner: 'acme',
			repo: 'demo',
			visibility: 'private',
			credentialId: 'cred-uuid'
		);

		self::assertSame(expected: 'own-commit-sha', actual: $result['commitSha']);
		self::assertContains(needle: 'POST /repos/acme/demo/git/trees', haystack: $this->brokerCalls);
	}//end testAFailingEngineFallsBackToTheOwnCommit()

	/**
	 * An engine that records its publishes and answers with a commit, or throws on null.
	 *
	 * @param string|null $commit The commit sha, or null to throw.
	 *
	 * @return FederatedConfigService The engine.
	 */
	private function engine(?string $commit): FederatedConfigService {
		return new class($commit) extends FederatedConfigService {
			/**
			 * Every publish request.
			 *
			 * @var array<int, RepoFilesPublish>
			 */
			public array $requests = [];

			/**
			 * Constructor.
			 *
			 * @param string|null $commit The commit sha, or null to throw.
			 */
			public function __construct(private readonly ?string $commit) {
			}//end __construct()

			/**
			 * Record the publish and answer it.
			 *
			 * @param RepoFilesPublish $request The request.
			 *
			 * @return array{published: true, repo: string, branch: string, commit: string, files: int}
			 */
			public function publishFiles(RepoFilesPublish $request): array {
				$this->requests[] = $request;
				if ($this->commit === null) {
					throw new RuntimeException('engine down');
				}

				return [
					'published' => true,
					'repo' => $request->target->repo,
					'branch' => $request->target->branch,
					'commit' => $this->commit,
					'files' => count($request->files),
				];
			}//end publishFiles()
		};
	}//end engine()

	/**
	 * Build the service over a fake broker and a container that has the engine.
	 *
	 * @param FederatedConfigService $engine The engine.
	 *
	 * @return GitHubTemplatePushService The service under test.
	 */
	private function service(FederatedConfigService $engine): GitHubTemplatePushService {
		$test = $this;
		$broker = new class($test) extends CredentialBrokerService {
			/**
			 * Constructor.
			 *
			 * @param GitHubTemplatePushEngineTest $test The test that owns the fake GitHub.
			 */
			public function __construct(private readonly GitHubTemplatePushEngineTest $test) {
			}//end __construct()

			/**
			 * Answer one call from the fake GitHub.
			 *
			 * @param string      $credentialId The credential.
			 * @param string      $appId        The calling app.
			 * @param string      $method       The HTTP method.
			 * @param string      $path         The GitHub path.
			 * @param array       $headers      The headers.
			 * @param string|null $body         The JSON body.
			 * @param string|null $actingUserId The acting user.
			 *
			 * @return array{status: int, body: string}
			 */
			public function request(
				string $credentialId,
				string $appId,
				string $method,
				string $path,
				array $headers = [],
				?string $body = null,
				?string $actingUserId = null,
			): array {
				return $this->test->answer(method: $method, path: $path);
			}//end request()
		};

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => $id === FederatedConfigService::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($engine, $broker): object {
				if ($id === FederatedConfigService::class) {
					return $engine;
				}

				return $broker;
			}
		);

		return new GitHubTemplatePushService(logger: new NullLogger(), container: $container);
	}//end service()

	/**
	 * Record one broker call and answer it the way GitHub would.
	 *
	 * @param string $method The HTTP method.
	 * @param string $path   The GitHub path.
	 *
	 * @return array{status: int, body: string}
	 */
	public function answer(string $method, string $path): array {
		$this->brokerCalls[] = $method . ' ' . $path;
		$repo = ['default_branch' => 'trunk', 'html_url' => 'https://github.com/acme/demo'];
		$answers = [
			'POST /orgs/acme/repos' => [201, $repo],
			'GET /repos/acme/demo/topics' => [200, ['names' => []]],
			'GET /repos/acme/demo/git/refs/heads/trunk' => [200, ['object' => ['sha' => 'base-commit-sha']]],
			'GET /repos/acme/demo/git/commits/base-commit-sha' => [200, ['tree' => ['sha' => 'base-tree-sha']]],
			'POST /repos/acme/demo/git/blobs' => [201, ['sha' => 'blob-sha']],
			'POST /repos/acme/demo/git/trees' => [201, ['sha' => 'own-tree-sha']],
			'POST /repos/acme/demo/git/commits' => [201, ['sha' => 'own-commit-sha']],
		];
		if ($method . ' ' . $path === 'GET /repos/acme/demo' && $this->repoExists === false) {
			return ['status' => 404, 'body' => '{"message":"Not Found"}'];
		}

		if ($method . ' ' . $path === 'GET /repos/acme/demo') {
			return ['status' => 200, 'body' => (string)json_encode($repo)];
		}

		[$status, $payload] = ($answers[$method . ' ' . $path] ?? [200, []]);

		return ['status' => $status, 'body' => (string)json_encode($payload)];
	}//end answer()
}//end class
