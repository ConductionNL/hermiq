<?php

/**
 * The store's install-time reads through OpenRegister's federated config engine.
 *
 * When the instance has the engine, a bundle is read through
 * `FederatedConfigService::fetchPackage()` with its manifest and its `skills/`
 * and `agents/` prefixes, and a skill's auxiliary files are every engine-read
 * file except the package itself, within the store's own file bound. Without
 * the engine, or when it finds nothing, the store reads GitHub itself.
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

use OCA\Hermiq\Service\GitHubArchiveExtractor;
use OCA\Hermiq\Service\GitHubTemplateCatalogService;
use OCA\OpenRegister\Service\Config\BrokerIdentity;
use OCA\OpenRegister\Service\Config\FederatedConfigService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for the engine route of the store's bundle and auxiliary-file reads.
 */
final class GitHubTemplateCatalogEngineReadTest extends TestCase {
	/**
	 * Every anonymous URL requested in the current test, in order.
	 *
	 * @var array<int, string>
	 */
	private array $urls = [];

	/**
	 * Reset the request log between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->urls = [];
	}//end setUp()

	/**
	 * A bundle is read through the engine with its manifest and folders, as hermiq.
	 *
	 * @return void
	 */
	public function testABundleIsReadThroughTheEngine(): void {
		$files = ['hermiq-skills.json' => '{}', 'skills/a/SKILL.md' => '# a', 'agents/triage.json' => '{}'];
		$engine = $this->engine(files: $files);

		$bundle = $this->service(engine: $engine)->fetchBundle(
			owner: 'acme',
			repo: 'bundle',
			ref: 'main',
			actingUserId: 'alice',
			credentialId: 'cred-1'
		);

		self::assertSame(expected: ['files' => $files, 'truncated' => false], actual: $bundle);
		self::assertCount(expectedCount: 1, haystack: $engine->calls);
		[$repo, $ref, $paths, $credentialId, $identity] = $engine->calls[0];
		self::assertSame(expected: 'acme/bundle', actual: $repo);
		self::assertSame(expected: 'main', actual: $ref);
		self::assertSame(expected: ['hermiq-skills.json', 'skills/', 'agents/'], actual: $paths);
		self::assertSame(expected: 'cred-1', actual: $credentialId);
		self::assertInstanceOf(expected: BrokerIdentity::class, actual: $identity);
		self::assertSame(expected: 'hermiq', actual: $identity->appId);
		self::assertSame(expected: 'alice', actual: $identity->actingUserId);
		self::assertSame(expected: [], actual: $this->urls, message: 'No read of its own from GitHub.');
	}//end testABundleIsReadThroughTheEngine()

	/**
	 * An engine read without the manifest leaves the bundle to the store's own read.
	 *
	 * @return void
	 */
	public function testABundleWithoutAManifestFallsBackToGitHub(): void {
		$engine = $this->engine(files: ['skills/a/SKILL.md' => '# a']);

		$this->service(engine: $engine)->fetchBundle(owner: 'acme', repo: 'bundle', ref: null, actingUserId: null);

		self::assertNotSame(expected: [], actual: $this->urls, message: 'The store read GitHub itself.');
	}//end testABundleWithoutAManifestFallsBackToGitHub()

	/**
	 * Auxiliary files are every engine-read file except the package and the engine envelope.
	 *
	 * @return void
	 */
	public function testAuxiliaryFilesComeFromTheEngine(): void {
		$engine = $this->engine(files: [
			'hermiq-skill.md' => 'package',
			'hermiq-skill.json' => '{}',
			'references/a.md' => 'A',
			'learnings.md' => 'L',
		]);

		$aux = $this->service(engine: $engine)->fetchAuxFiles(
			kind: GitHubTemplateCatalogService::KIND_SKILL,
			owner: 'acme',
			repo: 'skill',
			ref: null,
			actingUserId: 'alice'
		);

		self::assertSame(
			expected: [['name' => 'references/a.md', 'content' => 'A'], ['name' => 'learnings.md', 'content' => 'L']],
			actual: $aux
		);
		self::assertSame(expected: [], actual: $engine->calls[0][2], message: 'Every file is asked for.');
		self::assertSame(expected: [], actual: $this->urls);
	}//end testAuxiliaryFilesComeFromTheEngine()

	/**
	 * The store's own bound of 128 auxiliary files holds on the engine route too.
	 *
	 * @return void
	 */
	public function testTheAuxiliaryFileBoundHolds(): void {
		$files = ['hermiq-skill.md' => 'package'];
		for ($i = 0; $i < 140; $i++) {
			$files['references/' . $i . '.md'] = 'x';
		}

		$aux = $this->service(engine: $this->engine(files: $files))->fetchAuxFiles(
			kind: GitHubTemplateCatalogService::KIND_SKILL,
			owner: 'acme',
			repo: 'skill',
			ref: null,
			actingUserId: null
		);

		self::assertCount(expectedCount: 128, haystack: $aux);
	}//end testTheAuxiliaryFileBoundHolds()

	/**
	 * Without the engine the store reads the tree itself.
	 *
	 * @return void
	 */
	public function testWithoutTheEngineTheStoreReadsGitHub(): void {
		$this->service(engine: null)->fetchAuxFiles(
			kind: GitHubTemplateCatalogService::KIND_SKILL,
			owner: 'acme',
			repo: 'skill',
			ref: null,
			actingUserId: null
		);

		self::assertNotSame(expected: [], actual: $this->urls);
	}//end testWithoutTheEngineTheStoreReadsGitHub()

	/**
	 * An engine that records its package reads and answers with fixed files.
	 *
	 * @param array<string, string> $files The files every read answers.
	 *
	 * @return FederatedConfigService The engine.
	 */
	private function engine(array $files): FederatedConfigService {
		return new class($files) extends FederatedConfigService {
			/**
			 * Every read's repository, ref, paths, credential and identity.
			 *
			 * @var array<int, array{0: string, 1: string|null, 2: array<int, string>, 3: string|null, 4: BrokerIdentity|null}>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<string, string> $files The files every read answers.
			 */
			public function __construct(private readonly array $files) {
			}//end __construct()

			/**
			 * Record the read and answer it.
			 *
			 * @param string              $repo         The repository.
			 * @param string|null         $ref          The ref.
			 * @param array<int, string>  $paths        The paths.
			 * @param string|null         $credentialId The credential.
			 * @param BrokerIdentity|null $identity     The identity.
			 *
			 * @return array{files: array<string, string>, truncated: bool, source: string}
			 */
			public function fetchPackage(
				string $repo,
				?string $ref = null,
				array $paths = [],
				?string $credentialId = null,
				?BrokerIdentity $identity = null
			): array {
				$this->calls[] = [$repo, $ref, $paths, $credentialId, $identity];

				return ['files' => $this->files, 'truncated' => false, 'source' => 'archive'];
			}//end fetchPackage()
		};
	}//end engine()

	/**
	 * Build the service over a fake anonymous GitHub and a container that has the engine or not.
	 *
	 * @param FederatedConfigService|null $engine The engine, or null for none.
	 *
	 * @return GitHubTemplateCatalogService The service under test.
	 */
	private function service(?FederatedConfigService $engine): GitHubTemplateCatalogService {
		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url): IResponse {
				$this->urls[] = $url;
				$status = 404;
				$body = '{"message":"Not Found"}';
				if (str_contains($url, '/search/repositories') === true) {
					$status = 200;
					$body = '{"items":[]}';
				}

				$response = $this->createMock(originalClassName: IResponse::class);
				$response->method('getStatusCode')->willReturn($status);
				$response->method('getBody')->willReturn($body);
				return $response;
			}
		);

		$clientService = $this->createMock(originalClassName: IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('has')->willReturnCallback(
			static fn (string $id): bool => ($engine !== null && $id === FederatedConfigService::class)
		);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($engine): object {
				if ($engine !== null && $id === FederatedConfigService::class) {
					return $engine;
				}

				throw new RuntimeException('not in this container: ' . $id);
			}
		);

		return new GitHubTemplateCatalogService(
			clientService: $clientService,
			cacheFactory: $cacheFactory,
			logger: new NullLogger(),
			archiveExtractor: $this->createMock(originalClassName: GitHubArchiveExtractor::class),
			container: $container,
		);
	}//end service()
}//end class
