<?php

/**
 * Wire-level tests for GitHubTemplateCatalogService.
 *
 * These pin what the store's read path does on the wire today, and in particular
 * the behaviours OpenRegister's FederatedConfigService does not carry yet: the
 * free-text term sent to GitHub's search, the broker app id and acting user with
 * the anonymous fallback when the broker refuses, auxiliary files fetched beside
 * a skill package, and agent definitions kept from a bundle archive (#341).
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

use OCA\Hermiq\Service\GitHubArchiveExtractor;
use OCA\Hermiq\Service\GitHubTemplateCatalogService;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for {@see GitHubTemplateCatalogService} against a fake GitHub.
 */
final class GitHubTemplateCatalogServiceWireTest extends TestCase {
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
	 * The free-text term goes to GitHub's search next to the kind's topic.
	 *
	 * FederatedConfigService::discover() takes a topic only, so a term could only
	 * filter the first page of topic hits after the fact.
	 *
	 * @return void
	 */
	public function testSearchSendsTheFreeTextTermToGitHub(): void {
		$service = $this->service(routes: ['/search/repositories' => ['status' => 200, 'body' => '{"items":[]}']]);

		$service->search(query: 'meeting notes', actingUserId: 'alice', kind: GitHubTemplateCatalogService::KIND_SKILL);

		self::assertCount(expectedCount: 1, haystack: $this->urls);
		self::assertStringContainsString(needle: rawurlencode('topic:hermiq-skill meeting notes'), haystack: $this->urls[0]);
	}//end testSearchSendsTheFreeTextTermToGitHub()

	/**
	 * A brokered read names hermiq and the acting user, and falls back to an
	 * anonymous read when the broker refuses the credential.
	 *
	 * @return void
	 */
	public function testABrokeredReadNamesHermiqAndFallsBackToAnonymousWhenRefused(): void {
		$broker = new class() extends CredentialBrokerService {
			/**
			 * Every call's app id and acting user.
			 *
			 * @var array<int, array{0: string, 1: string|null}>
			 */
			public array $recorded = [];

			/**
			 * Record the call, then refuse it the way the broker's app guard does.
			 *
			 * @param string $credentialId The credential.
			 * @param string $appId The calling app.
			 * @param string $method The HTTP method.
			 * @param string $path The GitHub path.
			 * @param array $headers The headers.
			 * @param string|null $body The body.
			 * @param string|null $actingUserId The acting user.
			 *
			 * @return array Never returns.
			 *
			 * @throws RuntimeException Always.
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
				$this->recorded[] = [$appId, $actingUserId];
				throw new RuntimeException('app not in allowedApps');
			}//end request()
		};

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($broker);

		$service = $this->service(
			routes: ['/contents/hermiq-agent-template.json' => $this->contents(text: '{"name":"Demo"}')],
			container: $container
		);

		$package = $service->fetchTemplateFile(owner: 'acme', repo: 'demo', ref: null, actingUserId: 'alice', credentialId: 'cred-uuid');

		self::assertSame(expected: [['hermiq', 'alice']], actual: $broker->recorded);
		self::assertSame(expected: '{"name":"Demo"}', actual: $package, message: 'A refused broker call falls back to the anonymous read.');
		self::assertCount(expectedCount: 1, haystack: $this->urls);
	}//end testABrokeredReadNamesHermiqAndFallsBackToAnonymousWhenRefused()

	/**
	 * A skill install fetches every blob beside the package file, and never the package twice.
	 *
	 * @return void
	 */
	public function testAuxiliaryFilesAreEveryBlobExceptThePackage(): void {
		$tree = [
			'tree' => [
				['type' => 'blob', 'path' => 'hermiq-skill.md'],
				['type' => 'tree', 'path' => 'references'],
				['type' => 'blob', 'path' => 'references/steps.md'],
			],
		];
		$service = $this->service(
			routes: [
				'/git/trees/HEAD' => ['status' => 200, 'body' => (string)json_encode($tree)],
				'/contents/references/steps.md' => $this->contents(text: 'steps'),
				'/contents/hermiq-skill.md' => $this->contents(text: 'the package itself'),
			]
		);

		$files = $service->fetchAuxFiles(
			kind: GitHubTemplateCatalogService::KIND_SKILL,
			owner: 'acme',
			repo: 'demo',
			ref: null,
			actingUserId: 'alice'
		);

		self::assertSame(expected: [['name' => 'references/steps.md', 'content' => 'steps']], actual: $files);
	}//end testAuxiliaryFilesAreEveryBlobExceptThePackage()

	/**
	 * A bundle fetched as one archive keeps its manifest, skills and agent definitions (#341).
	 *
	 * @return void
	 */
	public function testABundleArchiveKeepsItsAgentDefinitions(): void {
		$archive = [
			'hermiq-skills.json' => '{"format":"1.1"}',
			'skills/a/SKILL.md' => 'a',
			'agents/triage.json' => '{"name":"Triage"}',
			'README.md' => 'readme',
		];

		$extractor = $this->createMock(originalClassName: GitHubArchiveExtractor::class);
		$extractor->method('extract')->willReturnCallback(
			static function (string $body, callable $accept, int $maxBytes) use ($archive): array {
				return array_filter($archive, static fn (string $path): bool => $accept($path), ARRAY_FILTER_USE_KEY);
			}
		);

		$service = $this->service(
			routes: ['/tarball/HEAD' => ['status' => 200, 'body' => 'tarball-bytes']],
			extractor: $extractor
		);

		$bundle = $service->fetchBundle(owner: 'acme', repo: 'demo', ref: null, actingUserId: 'alice');

		self::assertNotNull(actual: $bundle);
		self::assertSame(expected: ['hermiq-skills.json', 'skills/a/SKILL.md', 'agents/triage.json'], actual: array_keys($bundle['files']));
		self::assertCount(expectedCount: 1, haystack: $this->urls, message: 'One archive download, not one call per file.');
	}//end testABundleArchiveKeepsItsAgentDefinitions()

	/**
	 * Build the service over a fake anonymous GitHub.
	 *
	 * @param array<string, array{status: int, body: string}> $routes URL substring to response.
	 * @param ContainerInterface|null $container The container the broker comes from.
	 * @param GitHubArchiveExtractor|null $extractor The archive extractor.
	 *
	 * @return GitHubTemplateCatalogService The service under test.
	 */
	private function service(
		array $routes,
		?ContainerInterface $container = null,
		?GitHubArchiveExtractor $extractor = null,
	): GitHubTemplateCatalogService {
		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url) use ($routes): IResponse {
				$this->urls[] = $url;
				$answer = ['status' => 404, 'body' => '{"message":"Not Found"}'];
				foreach ($routes as $needle => $response) {
					if (str_contains($url, $needle) === true) {
						$answer = $response;
						break;
					}
				}

				$response = $this->createMock(originalClassName: IResponse::class);
				$response->method('getStatusCode')->willReturn($answer['status']);
				$response->method('getBody')->willReturn($answer['body']);
				return $response;
			}
		);

		$clientService = $this->createMock(originalClassName: IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);

		return new GitHubTemplateCatalogService(
			clientService: $clientService,
			cacheFactory: $cacheFactory,
			logger: new NullLogger(),
			archiveExtractor: $extractor ?? $this->createMock(originalClassName: GitHubArchiveExtractor::class),
			container: $container,
		);
	}//end service()

	/**
	 * A contents-API response carrying base64 text.
	 *
	 * @param string $text The file text.
	 *
	 * @return array{status: int, body: string} The response.
	 */
	private function contents(string $text): array {
		return ['status' => 200, 'body' => (string)json_encode(['content' => base64_encode($text), 'encoding' => 'base64'])];
	}//end contents()
}//end class
