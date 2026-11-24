<?php

/**
 * The store's search through OpenRegister's federated config engine.
 *
 * When the instance has the engine, a search goes through
 * `FederatedConfigService::discoverOutcome()` with the bare topic, the
 * free-text term, the credential and hermiq's broker identity, and its hits
 * become the same cards as before (each hit's package is still read to build
 * the card). Without the engine, or when it throws, the store searches GitHub
 * itself, as the wire tests pin.
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
 * Tests for the engine route of {@see GitHubTemplateCatalogService::search()}.
 */
final class GitHubTemplateCatalogEngineSearchTest extends TestCase {
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
	 * The search goes through the engine with the bare topic, the term and hermiq's identity.
	 *
	 * @return void
	 */
	public function testASearchGoesThroughTheEngineAsHermiq(): void {
		$engine = $this->engine(answer: ['outcome' => 'ok', 'cards' => [], 'brokerUsed' => true, 'rateLimited' => false, 'status' => 200]);

		$answer = $this->service(engine: $engine)->search(query: ' meeting notes ', actingUserId: 'alice', credentialId: 'cred-1', kind: GitHubTemplateCatalogService::KIND_SKILL);

		self::assertCount(expectedCount: 1, haystack: $engine->calls);
		[$topic, $term, $credentialId, $identity] = $engine->calls[0];
		self::assertSame(expected: 'hermiq-skill', actual: $topic);
		self::assertSame(expected: 'meeting notes', actual: $term);
		self::assertSame(expected: 'cred-1', actual: $credentialId);
		self::assertInstanceOf(expected: BrokerIdentity::class, actual: $identity);
		self::assertSame(expected: 'hermiq', actual: $identity->appId);
		self::assertSame(expected: 'alice', actual: $identity->actingUserId);
		self::assertSame(expected: [], actual: $this->urls, message: 'No search request of its own to GitHub.');
		self::assertSame(expected: 'ok', actual: $answer['outcome']);
		self::assertTrue(condition: $answer['brokerUsed']);
	}//end testASearchGoesThroughTheEngineAsHermiq()

	/**
	 * An engine hit becomes the same card as a GitHub hit: its package is read for it.
	 *
	 * @return void
	 */
	public function testAnEngineHitBecomesACard(): void {
		$engine = $this->engine(answer: [
			'outcome' => 'ok',
			'cards' => [['repo' => 'acme/triage', 'name' => 'triage', 'description' => 'x', 'url' => 'https://github.com/acme/triage', 'stars' => 4, 'updated' => '', 'topics' => []]],
			'brokerUsed' => false,
			'rateLimited' => false,
			'status' => 200,
		]);

		$answer = $this->service(engine: $engine)->search(query: '', actingUserId: null);

		self::assertCount(expectedCount: 1, haystack: $answer['cards']);
		$card = $answer['cards'][0];
		self::assertSame(expected: 'acme', actual: $card['owner']);
		self::assertSame(expected: 'triage', actual: $card['repo']);
		self::assertSame(expected: 4, actual: $card['stars']);
		self::assertSame(expected: GitHubTemplateCatalogService::KIND_AGENT_TEMPLATE, actual: $card['kind']);
		self::assertFalse(condition: $card['installable'], message: 'The fake GitHub has no package file, so the hit is surfaced as not installable.');
		self::assertStringContainsString(needle: '/repos/acme/triage/', haystack: (string)($this->urls[0] ?? ''));
	}//end testAnEngineHitBecomesACard()

	/**
	 * A rate-limited engine search surfaces the store's rate-limited outcome.
	 *
	 * @return void
	 */
	public function testARateLimitedEngineSearchSaysSo(): void {
		$engine = $this->engine(answer: ['outcome' => 'rate_limited', 'cards' => [], 'brokerUsed' => false, 'rateLimited' => true, 'status' => 403]);

		$answer = $this->service(engine: $engine)->search(query: '', actingUserId: null);

		self::assertSame(expected: GitHubTemplateCatalogService::OUTCOME_RATE_LIMITED, actual: $answer['outcome']);
		self::assertTrue(condition: $answer['rateLimited']);
		self::assertSame(expected: [], actual: $answer['cards']);
	}//end testARateLimitedEngineSearchSaysSo()

	/**
	 * When the engine throws, the store searches GitHub itself.
	 *
	 * @return void
	 */
	public function testAFailingEngineFallsBackToGitHub(): void {
		$engine = $this->engine(answer: null);

		$this->service(engine: $engine)->search(query: 'notes', actingUserId: null, kind: GitHubTemplateCatalogService::KIND_SKILL);

		self::assertCount(expectedCount: 1, haystack: $this->urls);
		self::assertStringContainsString(needle: rawurlencode('topic:hermiq-skill notes'), haystack: $this->urls[0]);
	}//end testAFailingEngineFallsBackToGitHub()

	/**
	 * A container without the engine keeps the direct GitHub search.
	 *
	 * @return void
	 */
	public function testWithoutTheEngineTheStoreSearchesGitHub(): void {
		$this->service(engine: null)->search(query: '', actingUserId: null);

		self::assertCount(expectedCount: 1, haystack: $this->urls);
		self::assertStringContainsString(needle: '/search/repositories', haystack: $this->urls[0]);
	}//end testWithoutTheEngineTheStoreSearchesGitHub()

	/**
	 * An engine that records its calls and gives one answer, or throws on null.
	 *
	 * @param array<string, mixed>|null $answer The answer, or null to throw.
	 *
	 * @return FederatedConfigService The engine.
	 */
	private function engine(?array $answer): FederatedConfigService {
		return new class($answer) extends FederatedConfigService {
			/**
			 * Every call's topic, term, credential and identity.
			 *
			 * @var array<int, array{0: string, 1: string, 2: string|null, 3: BrokerIdentity|null}>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed>|null $answer The answer, or null to throw.
			 */
			public function __construct(private readonly ?array $answer) {
			}//end __construct()

			/**
			 * Record the call and answer it.
			 *
			 * @param string              $topic        The topic.
			 * @param string              $term         The term.
			 * @param string|null         $credentialId The credential.
			 * @param BrokerIdentity|null $identity     The identity.
			 *
			 * @return array<string, mixed>
			 */
			public function discoverOutcome(string $topic, string $term = '', ?string $credentialId = null, ?BrokerIdentity $identity = null): array {
				$this->calls[] = [$topic, $term, $credentialId, $identity];
				if ($this->answer === null) {
					throw new RuntimeException('engine down');
				}

				return $this->answer;
			}//end discoverOutcome()
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
