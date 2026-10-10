<?php

/**
 * Wire-level tests for GitHubTemplatePushService.
 *
 * The sibling GitHubTemplatePushServiceTest locks the no-token surface and says
 * the wire surface is only exercisable live. Since #826 the broker is resolved
 * through an injected container, so a recording broker can stand in for GitHub.
 *
 * These tests pin what the store's publish path does on the wire today, and in
 * particular the behaviours OpenRegister's FederatedConfigService does not carry
 * yet: the broker app id and acting user, the refusal to overwrite a repository,
 * visibility on create, the topic union from #108, the empty-blob guard from
 * #108, base_tree preservation and the returned commit sha. A later move of the
 * store onto the shared engine has to keep every one of them green.
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
 * @spec openspec/changes/store-through-federated-config/specs/agent-template-github-store/spec.md#requirement-the-store-must-keep-its-github-wire-behaviour-until-the-shared-engine-carries-it
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\GitHubTemplatePushService;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for {@see GitHubTemplatePushService} against a recording broker.
 */
final class GitHubTemplatePushServiceWireTest extends TestCase {
	/**
	 * Every broker call made in the current test, in order.
	 *
	 * @var array<int, array{appId: string, method: string, path: string, body: array|null, actingUserId: string|null}>
	 */
	private array $calls = [];

	/**
	 * Whether the target repository exists on the fake GitHub.
	 *
	 * @var bool
	 */
	private bool $repoExists = false;

	/**
	 * The topics the existing repository already carries.
	 *
	 * @var array<int, string>
	 */
	private array $existingTopics = [];

	/**
	 * Blob contents for which the fake GitHub answers without a sha.
	 *
	 * @var array<int, string>
	 */
	private array $shalessContents = [];

	/**
	 * Reset the fake GitHub between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calls = [];
		$this->repoExists = false;
		$this->existingTopics = [];
		$this->shalessContents = [];
	}//end setUp()

	/**
	 * Every broker call names hermiq as the calling app and passes the acting user.
	 *
	 * Hermiq only offers credentials whose allowedApps contain `hermiq`, and the
	 * broker compares the app id exactly. FederatedConfigService calls as
	 * `openregister` without an acting user, so those credentials would be refused.
	 *
	 * @return void
	 */
	public function testEveryBrokerCallNamesHermiqAndTheActingUser(): void {
		$this->service()->push(
			package: '{"name":"Demo"}',
			owner: 'acme',
			repo: 'demo',
			visibility: 'private',
			credentialId: 'cred-uuid',
			actingUserId: 'alice'
		);

		self::assertNotEmpty(actual: $this->calls);
		foreach ($this->calls as $call) {
			self::assertSame(expected: 'hermiq', actual: $call['appId'], message: $call['method'] . ' ' . $call['path'] . ' must call as hermiq');
			self::assertSame(expected: 'alice', actual: $call['actingUserId'], message: $call['method'] . ' ' . $call['path'] . ' must carry the acting user');
		}
	}//end testEveryBrokerCallNamesHermiqAndTheActingUser()

	/**
	 * A single publish refuses a repository that already exists and creates nothing.
	 *
	 * @return void
	 */
	public function testPushRefusesARepositoryThatAlreadyExists(): void {
		$this->repoExists = true;

		$refusal = null;
		try {
			$this->service()->push(
				package: '{"name":"Demo"}',
				owner: 'acme',
				repo: 'demo',
				visibility: 'private',
				credentialId: 'cred-uuid',
				actingUserId: 'alice'
			);
		} catch (RuntimeException $e) {
			$refusal = $e;
		}

		self::assertNotNull(actual: $refusal, message: 'Publishing into an existing repository must be refused.');
		self::assertStringContainsString(needle: 'already exists', haystack: $refusal->getMessage());

		self::assertSame(expected: [], actual: $this->callsTo(method: 'POST'), message: 'A refused publish must write nothing.');
	}//end testPushRefusesARepositoryThatAlreadyExists()

	/**
	 * The caller's visibility reaches the create-repository call in both directions.
	 *
	 * @return void
	 */
	public function testPushCreatesTheRepositoryWithTheChosenVisibility(): void {
		$this->service()->push(package: '{}', owner: 'acme', repo: 'demo', visibility: 'private', credentialId: 'cred-uuid', actingUserId: 'alice');
		$this->service()->push(package: '{}', owner: 'acme', repo: 'demo', visibility: 'public', credentialId: 'cred-uuid', actingUserId: 'alice');

		$creates = $this->callsTo(method: 'POST', path: '/orgs/acme/repos');
		self::assertCount(expectedCount: 2, haystack: $creates);
		self::assertTrue(condition: $creates[0]['body']['private'], message: 'A private publish must create a private repository.');
		self::assertFalse(condition: $creates[1]['body']['private'], message: 'A public publish must create a public repository.');
	}//end testPushCreatesTheRepositoryWithTheChosenVisibility()

	/**
	 * A publish returns the sha of the commit the branch was moved to.
	 *
	 * @return void
	 */
	public function testPushReturnsTheCommitShaTheBranchWasMovedTo(): void {
		$result = $this->service()->push(
			package: '{}',
			owner: 'acme',
			repo: 'demo',
			visibility: 'private',
			credentialId: 'cred-uuid',
			actingUserId: 'alice'
		);

		self::assertSame(expected: 'new-commit-sha', actual: $result['commitSha']);
		$patches = $this->callsTo(method: 'PATCH', path: '/repos/acme/demo/git/refs/heads/main');
		self::assertCount(expectedCount: 1, haystack: $patches);
		self::assertSame(expected: ['sha' => 'new-commit-sha'], actual: $patches[0]['body'], message: 'The ref moves forward, never force-pushed.');
	}//end testPushReturnsTheCommitShaTheBranchWasMovedTo()

	/**
	 * A bundle published into an existing repository keeps that repository's topics (#108).
	 *
	 * GitHub's PUT /topics replaces the whole list. FederatedConfigService PUTs its
	 * one topic, which is the exact loss #108 fixed on buildiq-hydra.
	 *
	 * @return void
	 */
	public function testBundlePublishKeepsTheRepositorysOtherTopics(): void {
		$this->repoExists = true;
		$this->existingTopics = ['openbuild-app'];

		$result = $this->service()->publishBundle(
			files: ['hermiq-skills.json' => '{}', 'skills/a/SKILL.md' => 'a'],
			owner: 'acme',
			repo: 'demo',
			visibility: 'private',
			credentialId: 'cred-uuid',
			actingUserId: 'alice'
		);

		self::assertFalse(condition: $result['created']);
		$puts = $this->callsTo(method: 'PUT', path: '/repos/acme/demo/topics');
		self::assertCount(expectedCount: 1, haystack: $puts);
		self::assertSame(expected: ['hermiq-skill-bundle', 'openbuild-app'], actual: $puts[0]['body']['names']);
	}//end testBundlePublishKeepsTheRepositorysOtherTopics()

	/**
	 * A bundle commit rides the base tree, so paths outside the bundle survive.
	 *
	 * @return void
	 */
	public function testBundlePublishRidesTheBaseTree(): void {
		$this->repoExists = true;

		$this->service()->publishBundle(
			files: ['hermiq-skills.json' => '{}', 'skills/a/SKILL.md' => 'a', 'agents/triage.json' => '{}'],
			owner: 'acme',
			repo: 'demo',
			visibility: 'private',
			credentialId: 'cred-uuid',
			actingUserId: 'alice'
		);

		$trees = $this->callsTo(method: 'POST', path: '/repos/acme/demo/git/trees');
		self::assertCount(expectedCount: 1, haystack: $trees);
		self::assertSame(expected: 'base-tree-sha', actual: $trees[0]['body']['base_tree'] ?? null);
		self::assertSame(
			expected: ['hermiq-skills.json', 'skills/a/SKILL.md', 'agents/triage.json'],
			actual: array_column($trees[0]['body']['tree'], 'path'),
			message: 'Every bundle path lands in one tree, agents included (#341).'
		);
	}//end testBundlePublishRidesTheBaseTree()

	/**
	 * A blob that comes back without a sha fails on its own path before any tree is built (#108).
	 *
	 * @return void
	 */
	public function testABlobWithoutAShaFailsNamingThePathBeforeAnyTreeIsBuilt(): void {
		$this->repoExists = true;
		$this->shalessContents = ['poisoned'];

		$refusal = null;
		try {
			$this->service()->publishBundle(
				files: ['hermiq-skills.json' => '{}', 'skills/b/SKILL.md' => 'poisoned', 'skills/c/SKILL.md' => 'c'],
				owner: 'acme',
				repo: 'demo',
				visibility: 'private',
				credentialId: 'cred-uuid',
				actingUserId: 'alice'
			);
		} catch (RuntimeException $e) {
			$refusal = $e;
		}

		self::assertNotNull(actual: $refusal, message: 'A sha-less blob must stop the publish.');
		self::assertStringContainsString(needle: 'skills/b/SKILL.md', haystack: $refusal->getMessage());

		self::assertSame(
			expected: [],
			actual: $this->callsTo(method: 'POST', path: '/repos/acme/demo/git/trees'),
			message: 'No tree may be built from a broken blob set.'
		);
	}//end testABlobWithoutAShaFailsNamingThePathBeforeAnyTreeIsBuilt()

	/**
	 * A bundle into an absent repository creates it with the chosen visibility.
	 *
	 * @return void
	 */
	public function testBundlePublishCreatesAnAbsentRepositoryWithTheChosenVisibility(): void {
		$result = $this->service()->publishBundle(
			files: ['hermiq-skills.json' => '{}'],
			owner: 'acme',
			repo: 'demo',
			visibility: 'private',
			credentialId: 'cred-uuid',
			actingUserId: 'alice'
		);

		self::assertTrue(condition: $result['created']);
		$creates = $this->callsTo(method: 'POST', path: '/orgs/acme/repos');
		self::assertCount(expectedCount: 1, haystack: $creates);
		self::assertTrue(condition: $creates[0]['body']['private']);
	}//end testBundlePublishCreatesAnAbsentRepositoryWithTheChosenVisibility()

	/**
	 * A republish refuses a repository that no longer exists, and never re-creates it.
	 *
	 * @return void
	 */
	public function testRepublishRefusesARepositoryThatVanished(): void {
		$refusal = null;
		try {
			$this->service()->pushUpdate(
				package: '---\nname: demo\n---\n',
				owner: 'acme',
				repo: 'demo',
				credentialId: 'cred-uuid',
				actingUserId: 'alice'
			);
		} catch (RuntimeException $e) {
			$refusal = $e;
		}

		self::assertNotNull(actual: $refusal, message: 'Republishing to a vanished repository must be refused.');
		self::assertStringContainsString(needle: 'does not exist', haystack: $refusal->getMessage());

		self::assertSame(expected: [], actual: $this->callsTo(method: 'POST'), message: 'A refused republish must write nothing.');
	}//end testRepublishRefusesARepositoryThatVanished()

	/**
	 * A republish commits the package and every auxiliary file in one tree, at their own paths.
	 *
	 * @return void
	 */
	public function testRepublishCommitsAuxiliaryFilesAtTheirOwnPaths(): void {
		$this->repoExists = true;

		$this->service()->pushUpdate(
			package: '---\nname: demo\n---\n',
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

		$trees = $this->callsTo(method: 'POST', path: '/repos/acme/demo/git/trees');
		self::assertCount(expectedCount: 1, haystack: $trees);
		self::assertSame(
			expected: ['hermiq-skill.md', 'references/steps.md'],
			actual: array_column($trees[0]['body']['tree'], 'path'),
			message: 'Auxiliary files ride the same commit; an unsafe path is dropped.'
		);
	}//end testRepublishCommitsAuxiliaryFilesAtTheirOwnPaths()

	/**
	 * Build the service over a container that hands out the recording broker.
	 *
	 * @return GitHubTemplatePushService The service under test.
	 */
	private function service(): GitHubTemplatePushService {
		$test = $this;
		$broker = new class($test) extends CredentialBrokerService {
			/**
			 * Constructor.
			 *
			 * @param GitHubTemplatePushServiceWireTest $test The test that owns the fake GitHub.
			 */
			public function __construct(
				private readonly GitHubTemplatePushServiceWireTest $test,
			) {
			}//end __construct()

			/**
			 * Record the call and answer it from the fake GitHub.
			 *
			 * @param string $credentialId The credential.
			 * @param string $appId The calling app.
			 * @param string $method The HTTP method.
			 * @param string $path The GitHub path.
			 * @param array $headers The headers.
			 * @param string|null $body The JSON body.
			 * @param string|null $actingUserId The acting user.
			 *
			 * @return array The broker response.
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
				return $this->test->answer(appId: $appId, method: $method, path: $path, body: $body, actingUserId: $actingUserId);
			}//end request()
		};

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($broker);

		return new GitHubTemplatePushService(logger: new NullLogger(), container: $container);
	}//end service()

	/**
	 * Record one broker call and answer it the way GitHub would.
	 *
	 * @param string $appId The calling app.
	 * @param string $method The HTTP method.
	 * @param string $path The GitHub path.
	 * @param string|null $body The JSON body.
	 * @param string|null $actingUserId The acting user.
	 *
	 * @return array{status: int, body: string} The broker response.
	 */
	public function answer(string $appId, string $method, string $path, ?string $body, ?string $actingUserId): array {
		$decoded = null;
		if ($body !== null) {
			$decoded = json_decode($body, true);
		}

		$this->calls[] = ['appId' => $appId, 'method' => $method, 'path' => $path, 'body' => $decoded, 'actingUserId' => $actingUserId];

		$route = $method . ' ' . $path;
		switch ($route) {
			case 'GET /repos/acme/demo':
				if ($this->repoExists === false) {
					return ['status' => 404, 'body' => '{"message":"Not Found"}'];
				}
				return $this->json(payload: ['default_branch' => 'main', 'html_url' => 'https://github.com/acme/demo']);
			case 'POST /orgs/acme/repos':
				return $this->json(payload: ['default_branch' => 'main', 'html_url' => 'https://github.com/acme/demo'], status: 201);
			case 'GET /repos/acme/demo/topics':
				return $this->json(payload: ['names' => $this->existingTopics]);
			case 'GET /repos/acme/demo/git/refs/heads/main':
				return $this->json(payload: ['object' => ['sha' => 'base-commit-sha']]);
			case 'GET /repos/acme/demo/git/commits/base-commit-sha':
				return $this->json(payload: ['tree' => ['sha' => 'base-tree-sha']]);
			case 'POST /repos/acme/demo/git/blobs':
				if (in_array(base64_decode((string)($decoded['content'] ?? '')), $this->shalessContents, true) === true) {
					return $this->json(payload: [], status: 201);
				}
				return $this->json(payload: ['sha' => 'blob-' . md5((string)($decoded['content'] ?? ''))], status: 201);
			case 'POST /repos/acme/demo/git/trees':
				return $this->json(payload: ['sha' => 'new-tree-sha'], status: 201);
			case 'POST /repos/acme/demo/git/commits':
				return $this->json(payload: ['sha' => 'new-commit-sha'], status: 201);
			default:
				return $this->json(payload: []);
		}//end switch
	}//end answer()

	/**
	 * A JSON broker response.
	 *
	 * @param array $payload The body.
	 * @param int $status The HTTP status.
	 *
	 * @return array{status: int, body: string} The response.
	 */
	private function json(array $payload, int $status = 200): array {
		return ['status' => $status, 'body' => (string)json_encode($payload)];
	}//end json()

	/**
	 * The recorded calls matching a method and, optionally, a path.
	 *
	 * @param string $method The HTTP method.
	 * @param string|null $path The exact path, or null for any.
	 *
	 * @return array<int, array> The matching calls.
	 */
	private function callsTo(string $method, ?string $path = null): array {
		return array_values(
			array_filter(
				$this->calls,
				static fn (array $call): bool => $call['method'] === $method && ($path === null || $call['path'] === $path)
			)
		);
	}//end callsTo()
}//end class
