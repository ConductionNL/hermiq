<?php

/**
 * Unit tests for GitHubTemplateCatalogService (agent-template-github-store).
 *
 * Covers: anonymous search building normalised cards, rate-limit/unreachable
 * degradation (never throws, never a raw GitHub body), owner/repo/ref pattern
 * validation before any outbound call, and the 60s search-result cache.
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
 * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\GitHubTemplateCatalogService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for {@see GitHubTemplateCatalogService}.
 *
 * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
 */
class GitHubTemplateCatalogServiceTest extends TestCase
{
    /**
     * A cache factory reporting no cache backend available (simplest default —
     * every call is a live "miss", no caching behaviour to account for).
     *
     * @return ICacheFactory
     */
    private function noCacheFactory(): ICacheFactory
    {
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('isAvailable')->willReturn(false);
        return $factory;

    }//end noCacheFactory()

    /**
     * An IResponse mock with the given status + body.
     *
     * @param int    $status The HTTP status code.
     * @param string $body   The raw response body.
     *
     * @return IResponse
     */
    private function response(int $status, string $body): IResponse
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($body);
        return $response;

    }//end response()

    /**
     * A client service whose newClient()->get() calls return the given responses in order.
     *
     * @param array<int, IResponse> $responses The responses, one per successive get() call.
     *
     * @return IClientService
     */
    private function clientService(array $responses): IClientService
    {
        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturnOnConsecutiveCalls(...$responses);

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);
        return $clientService;

    }//end clientService()

    /**
     * search() with no credential queries GitHub anonymously and builds a card per
     * hit whose package file resolves.
     *
     * @return void
     */
    public function testSearchAnonymousReturnsCards(): void
    {
        $searchBody = json_encode([
            'items' => [
                [
                    'full_name'        => 'acme/demo',
                    'name'             => 'demo',
                    'owner'            => ['login' => 'acme'],
                    'stargazers_count' => 5,
                ],
            ],
        ]);

        $packageJson = json_encode([
            'name'        => 'Morning briefing',
            'description' => 'Summarises overnight tickets.',
            'category'    => 'productivity',
            'version'     => '0.1.0',
        ]);
        $contentsBody = json_encode([
            'content'  => base64_encode((string) $packageJson),
            'encoding' => 'base64',
        ]);

        $service = new GitHubTemplateCatalogService(
            $this->clientService([$this->response(200, (string) $searchBody), $this->response(200, (string) $contentsBody)]),
            $this->noCacheFactory(),
            new NullLogger()
        );

        $result = $service->search(query: null, actingUserId: 'alice');

        $this->assertSame(GitHubTemplateCatalogService::OUTCOME_OK, $result['outcome']);
        $this->assertCount(1, $result['cards']);
        $this->assertSame('acme', $result['cards'][0]['owner']);
        $this->assertSame('demo', $result['cards'][0]['repo']);
        $this->assertTrue($result['cards'][0]['installable']);
        $this->assertSame('Morning briefing', $result['cards'][0]['name']);
        $this->assertFalse($result['brokerUsed']);
        $this->assertFalse($result['rateLimited']);

    }//end testSearchAnonymousReturnsCards()

    /**
     * A search hit whose package file cannot be fetched is still surfaced, marked
     * non-installable/unparseable — never dropped.
     *
     * @return void
     */
    public function testSearchHitWithoutPackageFileIsUnparseable(): void
    {
        $searchBody = json_encode([
            'items' => [
                ['full_name' => 'acme/broken', 'name' => 'broken', 'owner' => ['login' => 'acme'], 'stargazers_count' => 0],
            ],
        ]);

        $service = new GitHubTemplateCatalogService(
            $this->clientService([$this->response(200, (string) $searchBody), $this->response(404, '')]),
            $this->noCacheFactory(),
            new NullLogger()
        );

        $result = $service->search(query: null, actingUserId: 'alice');

        $this->assertCount(1, $result['cards']);
        $this->assertFalse($result['cards'][0]['installable']);
        $this->assertTrue($result['cards'][0]['unparseable']);

    }//end testSearchHitWithoutPackageFileIsUnparseable()

    /**
     * A rate-limited/unreachable GitHub response degrades to an explicit outcome —
     * never throws, never leaves the caller with a raw GitHub body.
     *
     * @return void
     */
    public function testSearchRateLimitedDegradesGracefully(): void
    {
        $service = new GitHubTemplateCatalogService(
            $this->clientService([$this->response(403, 'rate limit exceeded')]),
            $this->noCacheFactory(),
            new NullLogger()
        );

        $result = $service->search(query: null, actingUserId: 'alice');

        $this->assertSame(GitHubTemplateCatalogService::OUTCOME_RATE_LIMITED, $result['outcome']);
        $this->assertSame([], $result['cards']);
        $this->assertTrue($result['rateLimited']);

    }//end testSearchRateLimitedDegradesGracefully()

    /**
     * fetchTemplateFile() rejects an invalid owner/repo BEFORE any outbound call.
     *
     * @return void
     */
    public function testFetchTemplateFileRejectsInvalidRepoBeforeAnyCall(): void
    {
        $client = $this->createMock(IClient::class);
        $client->expects($this->never())->method('get');

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        $service = new GitHubTemplateCatalogService($clientService, $this->noCacheFactory(), new NullLogger());

        $this->assertNull($service->fetchTemplateFile(owner: '../evil', repo: 'demo', ref: null, actingUserId: 'alice'));
        $this->assertNull($service->fetchTemplateFile(owner: 'acme', repo: 'demo', ref: 'bad ref!', actingUserId: 'alice'));

    }//end testFetchTemplateFileRejectsInvalidRepoBeforeAnyCall()

    /**
     * fetchTemplateFile() decodes a base64-encoded package file into the raw JSON string.
     *
     * @return void
     */
    public function testFetchTemplateFileDecodesBase64Content(): void
    {
        $packageJson  = '{"name":"Demo"}';
        $contentsBody = json_encode(['content' => base64_encode($packageJson), 'encoding' => 'base64']);

        $service = new GitHubTemplateCatalogService(
            $this->clientService([$this->response(200, (string) $contentsBody)]),
            $this->noCacheFactory(),
            new NullLogger()
        );

        $result = $service->fetchTemplateFile(owner: 'acme', repo: 'demo', ref: null, actingUserId: 'alice');

        $this->assertSame($packageJson, $result);

    }//end testFetchTemplateFileDecodesBase64Content()

    /**
     * validRepo() enforces the safe owner/repo/ref patterns used before any path
     * interpolation (search-result installs and publish targets alike).
     *
     * @return void
     */
    public function testValidRepoEnforcesSafePatterns(): void
    {
        $service = new GitHubTemplateCatalogService($this->clientService([]), $this->noCacheFactory(), new NullLogger());

        $this->assertTrue($service->validRepo(owner: 'acme-council', repo: 'demo_template.v2', ref: 'main'));
        $this->assertFalse($service->validRepo(owner: '../etc/passwd', repo: 'demo', ref: null));
        $this->assertFalse($service->validRepo(owner: 'acme', repo: 'demo; rm -rf', ref: null));
        $this->assertFalse($service->validRepo(owner: 'acme', repo: 'demo', ref: '$(reboot)'));

    }//end testValidRepoEnforcesSafePatterns()

    /**
     * search() caches a fresh result for SEARCH_TTL — a repeat call within the
     * window never re-hits the (tight, anonymous-rate-limited) GitHub client.
     *
     * @return void
     */
    public function testSearchCachesResultsForRepeatCalls(): void
    {
        $searchBody = json_encode(['items' => []]);

        $cache = $this->createMock(ICache::class);
        $cache->expects($this->once())->method('get')->willReturn(null);
        $cache->expects($this->once())->method('set');

        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('isAvailable')->willReturn(true);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        $client = $this->createMock(IClient::class);
        $client->expects($this->once())->method('get')->willReturn($this->response(200, (string) $searchBody));

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        $service = new GitHubTemplateCatalogService($clientService, $cacheFactory, new NullLogger());
        $result  = $service->search(query: null, actingUserId: 'alice');

        $this->assertSame(GitHubTemplateCatalogService::OUTCOME_OK, $result['outcome']);

    }//end testSearchCachesResultsForRepeatCalls()

    /**
     * isBrokerAvailable() resolves against OpenRegister's real broker FQCN — the
     * test stub is on the standalone-CI autoload path (`tests/Stubs/`).
     *
     * @return void
     */
    public function testBrokerAvailabilityIsCheckedAgainstOpenRegister(): void
    {
        $service = new GitHubTemplateCatalogService($this->clientService([]), $this->noCacheFactory(), new NullLogger());

        $this->assertTrue($service->isBrokerAvailable());

    }//end testBrokerAvailabilityIsCheckedAgainstOpenRegister()
}//end class
