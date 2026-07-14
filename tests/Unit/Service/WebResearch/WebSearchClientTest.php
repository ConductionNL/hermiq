<?php

/**
 * Unit tests for WebSearchClient (web-research-tool).
 *
 * Covers: `search_unavailable` with no backend configured (and no HTTP call
 * attempted), SearXNG native-JSON parsing, `generic-json` field-mapping parsing, the
 * egress guard rejecting BEFORE any request, and the search endpoint's exemption
 * from the private-address block.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\WebResearch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\WebResearch;

use OCA\Hermiq\Service\WebResearch\WebResearchEgressGuard;
use OCA\Hermiq\Service\WebResearch\WebResearchSettingsHandler;
use OCA\Hermiq\Service\WebResearch\WebSearchClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for WebSearchClient.
 *
 * @spec openspec/changes/web-research-tool/tasks.md#task-4-websearchclient-searxng--generic-json
 */
class WebSearchClientTest extends TestCase
{

    /**
     * A settings handler stubbed to a fixed config.
     *
     * @param array<string, mixed> $overrides Fields to override on top of the defaults.
     *
     * @return WebResearchSettingsHandler
     */
    private function settings(array $overrides=[]): WebResearchSettingsHandler
    {
        $config = array_merge(
            [
                'searchProvider'     => '',
                'searchEndpoint'     => '',
                'searchCredentialId' => '',
                'searchFieldMapping' => [
                    'resultsPath'  => 'results',
                    'titleField'   => 'title',
                    'urlField'     => 'url',
                    'snippetField' => 'content',
                ],
                'allowInsecureHttp'  => false,
                'timeoutSeconds'     => 10,
            ],
            $overrides
        );

        $handler = $this->createMock(WebResearchSettingsHandler::class);
        $handler->method('getWebResearchSettingsOnly')->willReturn($config);

        return $handler;

    }//end settings()

    /**
     * A guard mock that always allows.
     *
     * @return WebResearchEgressGuard
     */
    private function allowingGuard(): WebResearchEgressGuard
    {
        $guard = $this->createMock(WebResearchEgressGuard::class);
        $guard->method('assertSafe')->willReturn(['allowed' => true, 'code' => null, 'message' => null]);

        return $guard;

    }//end allowingGuard()

    /**
     * A client service mock whose `get()` returns a 200 with `$body`.
     *
     * @param string $body The JSON response body.
     *
     * @return IClientService
     */
    private function clientServiceReturningBody(string $body): IClientService
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn($body);

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($response);

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        return $clientService;

    }//end clientServiceReturningBody()

    /**
     * No backend configured: a structured `search_unavailable` error, and no HTTP
     * call is ever made.
     *
     * @return void
     *
     * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-no-search-backend-is-configured
     */
    public function testReportsUnavailableWithNoBackendConfigured(): void
    {
        $clientService = $this->createMock(IClientService::class);
        $clientService->expects($this->never())->method('newClient');

        $client = new WebSearchClient($clientService, $this->settings(), $this->allowingGuard(), new NullLogger());

        $result = $client->search(query: 'anything');

        $this->assertSame('search_unavailable', $result['error']['code']);

    }//end testReportsUnavailableWithNoBackendConfigured()

    /**
     * A SearXNG-shaped response is parsed into `{title, url, snippet}` results.
     *
     * @return void
     *
     * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-an-admin-configures-a-self-hosted-searxng-instance
     */
    public function testParsesSearxngNativeShape(): void
    {
        $body = json_encode(
            [
                'results' => [
                    ['title' => 'Nextcloud', 'url' => 'https://nextcloud.com', 'content' => 'A file sync platform.'],
                ],
            ]
        );

        $clientService = $this->clientServiceReturningBody((string) $body);

        $client = new WebSearchClient(
            $clientService,
            $this->settings(['searchProvider' => 'searxng', 'searchEndpoint' => 'https://searxng.internal']),
            $this->allowingGuard(),
            new NullLogger()
        );

        $result = $client->search(query: 'nextcloud');

        $this->assertSame('nextcloud', $result['query']);
        $this->assertCount(1, $result['results']);
        $this->assertSame('Nextcloud', $result['results'][0]['title']);
        $this->assertSame('https://nextcloud.com', $result['results'][0]['url']);
        $this->assertSame('A file sync platform.', $result['results'][0]['snippet']);

    }//end testParsesSearxngNativeShape()

    /**
     * A `generic-json` response is parsed using the admin-supplied field mapping.
     *
     * @return void
     *
     * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-an-admin-wires-a-non-searxng-json-search-api
     */
    public function testParsesGenericJsonUsingFieldMapping(): void
    {
        $body = json_encode(
            [
                'data' => [
                    'items' => [
                        ['headline' => 'Some Article', 'link' => 'https://example.test/a', 'summary' => 'A short summary.'],
                    ],
                ],
            ]
        );

        $clientService = $this->clientServiceReturningBody((string) $body);

        $client = new WebSearchClient(
            $clientService,
            $this->settings(
                [
                    'searchProvider'     => 'generic-json',
                    'searchEndpoint'     => 'https://api.example.test/search',
                    'searchFieldMapping' => [
                        'resultsPath'  => 'data.items',
                        'titleField'   => 'headline',
                        'urlField'     => 'link',
                        'snippetField' => 'summary',
                    ],
                ]
            ),
            $this->allowingGuard(),
            new NullLogger()
        );

        $result = $client->search(query: 'anything');

        $this->assertCount(1, $result['results']);
        $this->assertSame('Some Article', $result['results'][0]['title']);
        $this->assertSame('https://example.test/a', $result['results'][0]['url']);
        $this->assertSame('A short summary.', $result['results'][0]['snippet']);

    }//end testParsesGenericJsonUsingFieldMapping()

    /**
     * The egress guard's rejection prevents any HTTP call.
     *
     * @return void
     */
    public function testGuardRejectionPreventsAnyRequest(): void
    {
        $guard = $this->createMock(WebResearchEgressGuard::class);
        $guard->method('assertSafe')->willReturn(['allowed' => false, 'code' => 'metadata_address', 'message' => 'blocked']);

        $clientService = $this->createMock(IClientService::class);
        $clientService->expects($this->never())->method('newClient');

        $client = new WebSearchClient(
            $clientService,
            $this->settings(['searchProvider' => 'searxng', 'searchEndpoint' => 'https://searxng.internal']),
            $guard,
            new NullLogger()
        );

        $result = $client->search(query: 'anything');

        $this->assertSame('metadata_address', $result['error']['code']);

    }//end testGuardRejectionPreventsAnyRequest()

    /**
     * The search endpoint is validated with `isAdminConfiguredEndpoint: true` — the
     * guard's private-address exemption applies (proven end-to-end via
     * WebResearchEgressGuardTest; here we assert the guard is invoked with the
     * correct flag).
     *
     * @return void
     *
     * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-a-self-hosted-searxng-instance-is-on-an-internal-docker-network-address
     */
    public function testValidatesSearchEndpointAsAdminConfigured(): void
    {
        $guard = $this->createMock(WebResearchEgressGuard::class);
        $guard->expects($this->once())
            ->method('assertSafe')
            ->with(
                $this->anything(),
                true,
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn(['allowed' => true, 'code' => null, 'message' => null]);

        $clientService = $this->clientServiceReturningBody('{"results": []}');

        $client = new WebSearchClient(
            $clientService,
            $this->settings(['searchProvider' => 'searxng', 'searchEndpoint' => 'http://searxng:8080', 'allowInsecureHttp' => true]),
            $guard,
            new NullLogger()
        );

        $client->search(query: 'anything');

    }//end testValidatesSearchEndpointAsAdminConfigured()

    /**
     * An unparsable (non-JSON) response is a structured `search_failed` error.
     *
     * @return void
     */
    public function testUnparsableResponseReturnsStructuredError(): void
    {
        $clientService = $this->clientServiceReturningBody('not json at all');

        $client = new WebSearchClient(
            $clientService,
            $this->settings(['searchProvider' => 'searxng', 'searchEndpoint' => 'https://searxng.internal']),
            $this->allowingGuard(),
            new NullLogger()
        );

        $result = $client->search(query: 'anything');

        $this->assertSame('search_failed', $result['error']['code']);

    }//end testUnparsableResponseReturnsStructuredError()
}//end class
