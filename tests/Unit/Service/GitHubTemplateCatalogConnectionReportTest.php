<?php

/**
 * The store search and the throttled report it sends to integriq.
 *
 * A store search is the one moment hermiq meets GitHub. The report must say
 * what GitHub answered: a rate limit is Limited, no answer is Error. A cached
 * search met nothing, so it must report nothing, and the search itself must
 * answer exactly as it would without the report.
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
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-hermiq-reports-what-only-it-can-observe-req-hermiq-conn-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\Connection\ConnectionReporter;
use OCA\Hermiq\Service\GitHubArchiveExtractor;
use OCA\Hermiq\Service\GitHubTemplateCatalogService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Unit tests for the store search connection report.
 *
 * @covers \OCA\Hermiq\Service\GitHubTemplateCatalogService
 */
class GitHubTemplateCatalogConnectionReportTest extends TestCase {

	/**
	 * Every throttled report sent, as [key, status, message].
	 *
	 * @var array<int, array{0: string, 1: string, 2: string}>
	 */
	private array $reports = [];

	/**
	 * The service over a client whose search GET answers as given.
	 *
	 * @param IResponse|RuntimeException $answer The response, or an exception the GET throws.
	 * @param ICacheFactory|null         $cache  A cache factory, or null for none.
	 *
	 * @return GitHubTemplateCatalogService
	 */
	private function service(IResponse|RuntimeException $answer, ?ICacheFactory $cache = null): GitHubTemplateCatalogService {
		$client = $this->createMock(originalClassName: IClient::class);
		if ($answer instanceof RuntimeException) {
			$client->method('get')->willThrowException($answer);
		} else {
			$client->method('get')->willReturn($answer);
		}

		$clientService = $this->createMock(originalClassName: IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		if ($cache === null) {
			$cache = $this->createMock(originalClassName: ICacheFactory::class);
			$cache->method('isAvailable')->willReturn(false);
		}

		$reporter = $this->getMockBuilder(className: ConnectionReporter::class)
			->disableOriginalConstructor()
			->onlyMethods(['reportThrottled'])
			->getMock();
		$reporter->method('reportThrottled')->willReturnCallback(
			function (string $key, string $status, string $message = ''): bool {
				$this->reports[] = [$key, $status, $message];
				return true;
			}
		);

		return new GitHubTemplateCatalogService(
			clientService: $clientService,
			cacheFactory: $cache,
			logger: new NullLogger(),
			archiveExtractor: $this->createMock(originalClassName: GitHubArchiveExtractor::class),
			container: null,
			connectionReporter: $reporter,
		);
	}//end service()

	/**
	 * A response with the given status and body.
	 *
	 * @param int    $status The HTTP status.
	 * @param string $body   The body.
	 *
	 * @return IResponse
	 */
	private function response(int $status, string $body = '{"items":[]}'): IResponse {
		$response = $this->createMock(originalClassName: IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		return $response;
	}//end response()

	/**
	 * An anonymous answer reports configured and says no credential was used.
	 *
	 * @return void
	 */
	public function testAnAnsweredSearchReportsConfigured(): void {
		$result = $this->service(answer: $this->response(status: 200))->search(query: 'briefing', actingUserId: 'alice');

		$this->assertSame(expected: GitHubTemplateCatalogService::OUTCOME_OK, actual: $result['outcome']);
		$this->assertCount(expectedCount: 1, haystack: $this->reports);
		$this->assertSame(expected: ['github-templates', 'configured'], actual: array_slice($this->reports[0], 0, 2));
		$this->assertStringContainsString(needle: 'without a credential', haystack: $this->reports[0][2]);
	}//end testAnAnsweredSearchReportsConfigured()

	/**
	 * A rate limit reads Limited and points at the credentials section.
	 *
	 * @return void
	 */
	public function testARateLimitReportsLimited(): void {
		$result = $this->service(answer: $this->response(status: 403))->search(query: null, actingUserId: 'alice');

		$this->assertSame(expected: GitHubTemplateCatalogService::OUTCOME_RATE_LIMITED, actual: $result['outcome']);
		$this->assertSame(expected: ['github-templates', 'limited'], actual: array_slice($this->reports[0], 0, 2));
		$this->assertStringContainsString(needle: 'Organisation credentials', haystack: $this->reports[0][2]);
	}//end testARateLimitReportsLimited()

	/**
	 * A server error reads Error and names the status.
	 *
	 * @return void
	 */
	public function testAServerErrorReportsAnError(): void {
		$result = $this->service(answer: $this->response(status: 502, body: ''))->search(query: null, actingUserId: 'alice');

		$this->assertSame(expected: GitHubTemplateCatalogService::OUTCOME_UNREACHABLE, actual: $result['outcome']);
		$this->assertSame(expected: ['github-templates', 'error'], actual: array_slice($this->reports[0], 0, 2));
		$this->assertStringContainsString(needle: 'HTTP 502', haystack: $this->reports[0][2]);
	}//end testAServerErrorReportsAnError()

	/**
	 * No answer at all reads Error.
	 *
	 * @return void
	 */
	public function testNoAnswerReportsAnError(): void {
		$this->service(answer: new RuntimeException('cURL error 6: could not resolve host'))->search(query: null, actingUserId: 'alice');

		$this->assertSame(expected: ['github-templates', 'error'], actual: array_slice($this->reports[0], 0, 2));
		$this->assertStringContainsString(needle: 'could not be reached', haystack: $this->reports[0][2]);
	}//end testNoAnswerReportsAnError()

	/**
	 * A cached search met nothing, so it reports nothing.
	 *
	 * @return void
	 */
	public function testACachedSearchReportsNothing(): void {
		$cached = [
			'outcome' => GitHubTemplateCatalogService::OUTCOME_OK,
			'cards' => [],
			'brokerUsed' => false,
			'rateLimited' => false,
		];
		$cache = $this->createMock(originalClassName: ICache::class);
		$cache->method('get')->willReturn($cached);
		$factory = $this->createMock(originalClassName: ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);

		$result = $this->service(answer: new RuntimeException('must not be called'), cache: $factory)->search(query: null, actingUserId: 'alice');

		$this->assertSame(expected: $cached, actual: $result);
		$this->assertSame(expected: [], actual: $this->reports);
	}//end testACachedSearchReportsNothing()
}//end class
