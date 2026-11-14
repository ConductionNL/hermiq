<?php

/**
 * Golden test for GitHubTemplateCatalogService::fetchBundle().
 *
 * Runs the bundle fetch over generated repositories (valid and invalid
 * coordinates and refs), callers with and without a credential, a broker that
 * answers, refuses or is absent, archive downloads that succeed, fail or miss
 * the manifest, and per-file reads that succeed, fail, are rate-limited or
 * carry broken content, plus one tree past the file bound and one manifest
 * that leaves only a few bytes of the byte bound. It compares the
 * result, every outbound call and the log lines with a recorded fixture. Set
 * GOLDEN_RECORD=1 to (re)write the fixture; a run in record mode compares
 * nothing.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
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
use Psr\Log\AbstractLogger;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Stringable;

/**
 * Golden comparison of the bundle fetch.
 *
 * @covers \OCA\Hermiq\Service\GitHubTemplateCatalogService
 * @uses   \OCA\Hermiq\Service\GitHubCatalogPayload
 * @uses   \OCA\Hermiq\Service\SkillSerializer
 * @uses \OCA\Hermiq\Service\CatalogFetch
 * @uses   \OCA\Hermiq\Service\CatalogCaller
 * @uses   \OCA\Hermiq\Service\CatalogSearch
 *
 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
 */
class GitHubBundleFetchGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/Golden/github-bundle-fetch.json';

	private const CASES = 200;

	private const OWNERS = ['acme', 'acme', 'acme', 'acme', 'acme', 'acme', 'acme', 'bad owner', ''];

	private const REFS = [null, null, null, '', 'main', 'main', 'v1.2', 'bad ref!'];

	private const PATHS = [
		'hermiq-skills.json', 'skills/a/SKILL.md', 'skills/b/SKILL.md', 'agents/triage.json', 'README.md', 'docs/skills/x.md',
	];

	/**
	 * The seeded generator of the current case.
	 *
	 * @var Randomizer
	 */
	private Randomizer $random;

	/**
	 * Every outbound call of the current case.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * Routes answered before the generated ones (search hits and package files).
	 *
	 * @var array<string, mixed>
	 */
	private array $extraRoutes = [];

	/**
	 * Search, package-file and auxiliary-file reads give the recorded result, calls and log.
	 *
	 * @return void
	 */
	public function testSearchAndFileReadsMatchTheRecordedFixture(): void {
		$actual = [];
		for ($case = 0; $case < 3 * self::CASES; $case++) {
			$this->random = new Randomizer(new Mt19937($case + 7001));
			$this->calls = [];
			$this->extraRoutes = [
				'/search/repositories' => $this->searchAnswer(),
				'/contents/hermiq-agent-template.json' => $this->pick(pool: $this->templateAnswers()),
				'/contents/hermiq-skill.md' => $this->pick(pool: $this->skillAnswers()),
			];
			$logger = $this->logger();
			$service = $this->service(logger: $logger, huge: false, bigManifest: false);
			$kind = $this->pick(pool: [GitHubTemplateCatalogService::KIND_AGENT_TEMPLATE, GitHubTemplateCatalogService::KIND_SKILL, 'other']);
			$actingUserId = $this->pick(pool: ['alice', null]);
			$credentialId = $this->pick(pool: [null, '', 'cred-1']);
			$operation = $this->pick(pool: ['search', 'search', 'search', 'package', 'aux']);
			$args = ['operation' => $operation, 'kind' => $kind, 'actingUserId' => $actingUserId, 'credentialId' => $credentialId];
			if ($operation === 'search') {
				$args['query'] = $this->pick(pool: [null, '', '  Brief  ', 'x y']);
				$result = $service->search(query: $args['query'], actingUserId: $actingUserId, credentialId: $credentialId, kind: $kind);
			} else {
				$args['owner'] = $this->pick(pool: self::OWNERS);
				$args['ref'] = $this->pick(pool: self::REFS);
				$coordinates = [
					'kind' => $kind,
					'owner' => $args['owner'],
					'repo' => 'demo',
					'ref' => $args['ref'],
					'actingUserId' => $actingUserId,
					'credentialId' => $credentialId,
				];
				$result = $service->fetchPackageFile(...$coordinates);
				if ($operation === 'aux') {
					$result = $service->fetchAuxFiles(...$coordinates);
				}
			}

			$actual[] = ['args' => $args, 'result' => $result, 'calls' => $this->calls, 'log' => $logger->lines];
		}//end for

		$this->extraRoutes = [];
		$this->assertMatchesFixture(fixture: __DIR__ . '/../../Fixtures/Golden/github-search-reads.json', actual: $actual);

	}//end testSearchAndFileReadsMatchTheRecordedFixture()

	/**
	 * Compare the cases with a recorded fixture, or record it when GOLDEN_RECORD=1.
	 *
	 * @param string $fixture The fixture path.
	 * @param array<int, mixed> $actual The cases.
	 *
	 * @return void
	 */
	private function assertMatchesFixture(string $fixture, array $actual): void {
		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
			$this->markTestSkipped(message: 'Fixture recorded.');
		}

		$expected = json_decode((string)file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
		$this->assertCount(expectedCount: count($expected), haystack: $actual);
		foreach ($expected as $index => $one) {
			$this->assertSame(
				expected: json_encode($one, JSON_THROW_ON_ERROR),
				actual: json_encode($actual[$index], JSON_THROW_ON_ERROR),
				message: 'case ' . $index
			);
		}

	}//end assertMatchesFixture()

	/**
	 * One search answer: hits of every shape, an unusable body, or a failure.
	 *
	 * @return mixed
	 */
	private function searchAnswer(): mixed {
		$shapes = [
			['full_name' => 'acme/demo', 'name' => 'demo', 'owner' => ['login' => 'acme'], 'stargazers_count' => 5],
			['full_name' => 'beta/tool', 'stargazers_count' => '7'],
			['full_name' => 'gamma/', 'owner' => ['login' => 'gamma']],
			['full_name' => '/lone', 'name' => 'lone'],
			['name' => 'nameless'],
			[],
			'loose',
			['full_name' => 'acme/demo', 'name' => 'demo', 'owner' => ['login' => '']],
		];
		$items = [];
		$count = $this->random->getInt(0, 5);
		for ($i = 0; $i < $count; $i++) {
			$items[] = $this->pick(pool: $shapes);
		}

		if ($this->random->getInt(0, 9) === 0) {
			$items = [];
			for ($i = 0; $i < 33; $i++) {
				$items[] = ['full_name' => 'acme/repo' . $i, 'stargazers_count' => $i];
			}
		}

		return $this->pick(
			pool: [
				['status' => 200, 'body' => (string)json_encode(['items' => $items])],
				['status' => 200, 'body' => (string)json_encode(['items' => $items])],
				['status' => 200, 'body' => (string)json_encode(['items' => $items])],
				['status' => 200, 'body' => '{"items":"none"}'],
				['status' => 200, 'body' => 'not json'],
				['status' => 403, 'body' => ''],
				['status' => 429, 'body' => ''],
				['status' => 500, 'body' => ''],
				'throws',
			]
		);

	}//end searchAnswer()

	/**
	 * Template package answers: valid, partial, unparseable, absent.
	 *
	 * @return array<int, mixed>
	 */
	private function templateAnswers(): array {
		$full = ['name' => 'Morning briefing', 'description' => 'Overnight tickets.', 'category' => 'productivity', 'version' => '0.1.0'];
		return [
			['status' => 200, 'body' => (string)json_encode(['content' => base64_encode((string)json_encode($full)), 'encoding' => 'base64'])],
			['status' => 200, 'body' => (string)json_encode(['content' => (string)json_encode(['version' => 3]), 'encoding' => 'utf-8'])],
			['status' => 200, 'body' => (string)json_encode(['content' => 'not json', 'encoding' => 'utf-8'])],
			['status' => 200, 'body' => '{"content":"","encoding":"base64"}'],
			['status' => 200, 'body' => '"just a string"'],
			['status' => 200, 'body' => (string)json_encode(['content' => '3', 'encoding' => 'utf-8'])],
			['status' => 200, 'body' => (string)json_encode(['content' => chunk_split(base64_encode((string)json_encode($full)), 12, "\n"), 'encoding' => 'base64'])],
			['status' => 404, 'body' => ''],
			['status' => 429, 'body' => ''],
			'throws',
		];

	}//end templateAnswers()

	/**
	 * Skill package answers: named, unnamed, absent.
	 *
	 * @return array<int, mixed>
	 */
	private function skillAnswers(): array {
		$named = "---\nname: triage\ndescription: Sort the inbox.\n---\n# Triage\n";
		return [
			['status' => 200, 'body' => (string)json_encode(['content' => base64_encode($named), 'encoding' => 'base64'])],
			['status' => 200, 'body' => (string)json_encode(['content' => "# No fence\n", 'encoding' => 'utf-8'])],
			['status' => 200, 'body' => '{"content":"","encoding":"utf-8"}'],
			['status' => 404, 'body' => ''],
			['status' => 403, 'body' => ''],
		];

	}//end skillAnswers()

	/**
	 * Every generated case gives the recorded result, calls and log.
	 *
	 * @return void
	 */
	public function testFetchBundleMatchesTheRecordedFixture(): void {
		$actual = [];
		for ($case = 0; $case <= self::CASES + 1; $case++) {
			$this->random = new Randomizer(new Mt19937($case + 1));
			$this->calls = [];
			$logger = $this->logger();
			$huge = ($case >= self::CASES);
			$service = $this->service(logger: $logger, huge: $huge, bigManifest: ($case > self::CASES));
			$args = [
				'owner' => $this->pick(pool: self::OWNERS),
				'repo' => $this->pick(pool: ['demo', 'demo', 'demo', 'demo', 'demo', 'x/y']),
				'ref' => $this->pick(pool: self::REFS),
				'actingUserId' => $this->pick(pool: ['alice', null]),
				'credentialId' => $this->pick(pool: [null, '', 'cred-1', 'cred-1']),
			];
			if ($huge === true) {
				$args = ['owner' => 'acme', 'repo' => 'demo', 'ref' => null, 'actingUserId' => 'alice', 'credentialId' => null];
			}

			$result = $service->fetchBundle(...$args);
			$calls = $this->calls;
			if ($huge === true) {
				$calls = [count($calls), array_slice($calls, 0, 3), array_slice($calls, -2)];
				$result = ['fileCount' => count($result['files'] ?? []), 'truncated' => $result['truncated'] ?? null];
			}

			$actual[] = ['args' => $args, 'result' => $result, 'calls' => $calls, 'log' => $logger->lines];
		}//end for

		if (getenv('GOLDEN_RECORD') === '1') {
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE), 0777, true);
			}

			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
			$this->markTestSkipped(message: 'Fixture recorded.');
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
		$this->assertCount(expectedCount: count($expected), haystack: $actual);
		foreach ($expected as $index => $one) {
			$this->assertSame(
				expected: json_encode($one, JSON_THROW_ON_ERROR),
				actual: json_encode($actual[$index], JSON_THROW_ON_ERROR),
				message: 'case ' . $index
			);
		}

	}//end testFetchBundleMatchesTheRecordedFixture()

	/**
	 * The service over a generated GitHub, broker and archive.
	 *
	 * @param AbstractLogger $logger The recording logger.
	 * @param bool $huge Whether the tree holds more blobs than the file bound.
	 * @param bool $bigManifest Whether the manifest leaves only a few bytes of the byte bound.
	 *
	 * @return GitHubTemplateCatalogService
	 */
	private function service(AbstractLogger $logger, bool $huge, bool $bigManifest): GitHubTemplateCatalogService {
		$routes = $this->extraRoutes + $this->routes(huge: $huge);
		if ($bigManifest === true) {
			$routes['/contents/hermiq-skills.json'] = ['status' => 200, 'body' => (string)json_encode(['content' => str_repeat('m', 16777216 - 10), 'encoding' => 'utf-8'])];
		}

		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use ($routes): IResponse {
				$this->calls[] = ['anonymousGet', $url, $options];
				return $this->respond(answer: $this->route(routes: $routes, path: $url));
			}
		);
		$clientService = $this->createMock(originalClassName: IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);

		$container = null;
		$brokerMode = $this->pick(pool: ['absent', 'answers', 'answers', 'refuses', 'forbidden']);
		if ($brokerMode !== 'absent') {
			$container = $this->createMock(originalClassName: ContainerInterface::class);
			$container->method('get')->willReturnCallback(
				function (string $id) use ($brokerMode, $routes): CredentialBrokerService {
					$this->calls[] = ['container', $id];
					return $this->broker(mode: $brokerMode, routes: $routes);
				}
			);
		}

		$archive = $this->pick(pool: ['with-manifest', 'with-manifest', 'without-manifest', 'unreadable']);
		$extractor = $this->createMock(originalClassName: GitHubArchiveExtractor::class);
		$extractor->method('extract')->willReturnCallback(
			function (string $body, callable $accept, int $maxBytes) use ($archive): ?array {
				$listing = array_fill_keys(self::PATHS, 'archived');
				if ($archive === 'without-manifest') {
					unset($listing['hermiq-skills.json']);
				}

				$kept = array_filter($listing, static fn (string $path): bool => $accept($path), ARRAY_FILTER_USE_KEY);
				$this->calls[] = ['extract', $body, $maxBytes, array_keys($kept)];
				if ($archive === 'unreadable') {
					return null;
				}

				return $kept;
			}
		);

		return new GitHubTemplateCatalogService(
			clientService: $clientService,
			cacheFactory: $cacheFactory,
			logger: $logger,
			archiveExtractor: $extractor,
			container: $container,
		);

	}//end service()

	/**
	 * A broker double that answers from the routes, refuses, or answers 403.
	 *
	 * @param string $mode `answers`, `refuses` or `forbidden`.
	 * @param array<string, mixed> $routes Path fragment to answer.
	 *
	 * @return CredentialBrokerService
	 */
	private function broker(string $mode, array $routes): CredentialBrokerService {
		$record = function (array $call): void {
			$this->calls[] = $call;
		};
		$answer = fn (string $path): array => $this->route(routes: $routes, path: $path);

		return new class($mode, $record, $answer) extends CredentialBrokerService {
			/**
			 * Keep the mode and the two callbacks; the parent's collaborators are never used.
			 *
			 * @param string $mode The broker behaviour.
			 * @param callable $record Records one call.
			 * @param callable $answer Answers one path.
			 */
			public function __construct(
				private readonly string $mode,
				private readonly mixed $record,
				private readonly mixed $answer,
			) {
			}//end __construct()

			/**
			 * Record the call and answer it per the mode.
			 *
			 * @param string $credentialId The credential.
			 * @param string $appId The calling app.
			 * @param string $method The HTTP method.
			 * @param string $path The GitHub path.
			 * @param array $headers The headers.
			 * @param string|null $body The body.
			 * @param string|null $actingUserId The acting user.
			 *
			 * @return array The broker response.
			 *
			 * @throws RuntimeException When the broker refuses.
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
				($this->record)(['brokerRequest', $credentialId, $appId, $method, $path, $headers, $body, $actingUserId]);
				if ($this->mode === 'refuses') {
					throw new RuntimeException('app not in allowedApps');
				}

				if ($this->mode === 'forbidden') {
					return ['status' => 403, 'body' => ''];
				}

				$answer = ($this->answer)($path);
				if ($answer === 'throws') {
					return ['status' => 502, 'body' => ''];
				}

				return $answer;
			}//end request()
		};

	}//end broker()

	/**
	 * Generated answers per path fragment.
	 *
	 * @param bool $huge Whether the tree holds more blobs than the file bound.
	 *
	 * @return array<string, mixed>
	 */
	private function routes(bool $huge): array {
		$tree = [];
		foreach (self::PATHS as $path) {
			if ($this->random->getInt(0, 4) !== 0) {
				$tree[] = ['type' => 'blob', 'path' => $path];
			}
		}

		$tree[] = ['type' => 'tree', 'path' => 'skills/a'];
		$tree[] = ['type' => 'blob', 'path' => ''];
		$tree[] = 'loose';
		if ($huge === true) {
			$tree = [];
			for ($i = 0; $i < 4100; $i++) {
				$tree[] = ['type' => 'blob', 'path' => 'skills/s' . $i . '/SKILL.md'];
			}
		}

		$routes = [
			'/tarball/' => $this->pick(pool: [['status' => 200, 'body' => 'tar-bytes'], ['status' => 404, 'body' => ''], 'throws', ['status' => 200, 'body' => '']]),
			'/git/trees/' => $this->pick(
				pool: [
					['status' => 200, 'body' => (string)json_encode(['tree' => $tree])],
					['status' => 200, 'body' => (string)json_encode(['tree' => $tree])],
					['status' => 200, 'body' => '{"tree":"none"}'],
					['status' => 404, 'body' => ''],
				]
			),
		];
		foreach (self::PATHS as $path) {
			$routes['/contents/' . $path] = $this->contents(path: $path);
		}

		if ($huge === true) {
			$routes['/tarball/'] = ['status' => 404, 'body' => ''];
			$routes['/git/trees/'] = ['status' => 200, 'body' => (string)json_encode(['tree' => $tree])];
			$routes['/contents/hermiq-skills.json'] = ['status' => 200, 'body' => '{"content":"manifest","encoding":"utf-8"}'];
			$routes['/contents/skills/'] = ['status' => 200, 'body' => '{"content":"x","encoding":"utf-8"}'];
		}

		return $routes;

	}//end routes()

	/**
	 * One contents-API answer in one of its shapes.
	 *
	 * @param string $path The file path.
	 *
	 * @return mixed
	 */
	private function contents(string $path): mixed {
		$kind = $this->random->getInt(0, 7);
		return match ($kind) {
			0 => ['status' => 404, 'body' => '{"message":"Not Found"}'],
			1 => ['status' => 403, 'body' => ''],
			2 => ['status' => 200, 'body' => '{"content":"!!not base64","encoding":"base64"}'],
			3 => ['status' => 200, 'body' => '{"content":"","encoding":"utf-8"}'],
			4 => ['status' => 200, 'body' => '{"content":"plain ' . $path . '","encoding":"utf-8"}'],
			5 => 'throws',
			default => ['status' => 200, 'body' => (string)json_encode(['content' => chunk_split(base64_encode('text of ' . $path), 8, "\n"), 'encoding' => 'base64'])],
		};

	}//end contents()

	/**
	 * The answer for a URL or path: the first route whose fragment it contains, else 404.
	 *
	 * @param array<string, mixed> $routes Path fragment to answer.
	 * @param string $path The URL or path.
	 *
	 * @return mixed
	 */
	private function route(array $routes, string $path): mixed {
		foreach ($routes as $fragment => $answer) {
			if (str_contains($path, $fragment) === true) {
				return $answer;
			}
		}

		return ['status' => 404, 'body' => ''];

	}//end route()

	/**
	 * A client response for an answer, or a thrown transport error.
	 *
	 * @param mixed $answer The route's answer.
	 *
	 * @return IResponse
	 */
	private function respond(mixed $answer): IResponse {
		if ($answer === 'throws') {
			throw new RuntimeException('Client error: 403 rate limit exceeded');
		}

		$response = $this->createMock(originalClassName: IResponse::class);
		$response->method('getStatusCode')->willReturn($answer['status']);
		$response->method('getBody')->willReturn($answer['body']);
		return $response;

	}//end respond()

	/**
	 * A logger that keeps its lines.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		return new class extends AbstractLogger {
			/**
			 * Recorded log lines.
			 *
			 * @var array<int, mixed>
			 */
			public array $lines = [];

			/**
			 * Record one line.
			 *
			 * @param mixed $level The level.
			 * @param string|Stringable $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->lines[] = [$level, (string)$message, $context];
			}//end log()
		};

	}//end logger()

	/**
	 * One value from a pool.
	 *
	 * @param array<int, mixed> $pool The pool.
	 *
	 * @return mixed
	 */
	private function pick(array $pool): mixed {
		return $pool[$this->random->getInt(0, count($pool) - 1)];

	}//end pick()
}//end class
