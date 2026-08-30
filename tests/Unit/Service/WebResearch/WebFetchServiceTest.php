<?php

/**
 * Unit tests for WebFetchService (web-research-tool).
 *
 * Covers: the egress guard rejecting BEFORE any request is issued, redirect
 * responses never followed and surfaced as a structured error naming the target,
 * non-text content types rejected before extraction, oversized responses truncated
 * with `truncated: true`, and the untrusted-content delimiter wrapping every
 * successful result.
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
 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-webfetch-extracts-readable-text-with-a-content-type-gate
 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-fetched-content-is-delimited-as-untrusted-before-reaching-the-llm
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\WebResearch;

use OCA\Hermiq\Service\WebResearch\ReadableTextExtractor;
use OCA\Hermiq\Service\WebResearch\WebFetchService;
use OCA\Hermiq\Service\WebResearch\WebResearchEgressGuard;
use OCA\Hermiq\Service\WebResearch\WebResearchSettingsHandler;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for WebFetchService.
 *
 * @spec openspec/changes/web-research-tool/tasks.md#task-5-webfetchservice--readabletextextractor
 */
class WebFetchServiceTest extends TestCase {

	/**
	 * A settings handler stubbed to a fixed, fully-populated config.
	 *
	 * @param array<string, mixed> $overrides Fields to override on top of the defaults.
	 *
	 * @return WebResearchSettingsHandler
	 */
	private function settings(array $overrides = []): WebResearchSettingsHandler {
		$config = array_merge(
			[
				'fetchAllowlist' => [],
				'fetchDenylist' => [],
				'allowInsecureHttp' => false,
				'maxResponseBytes' => 500000,
				'timeoutSeconds' => 10,
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
	private function allowingGuard(): WebResearchEgressGuard {
		$guard = $this->createMock(WebResearchEgressGuard::class);
		$guard->method('assertSafe')->willReturn(['allowed' => true, 'code' => null, 'message' => null]);

		return $guard;
	}//end allowingGuard()

	/**
	 * A response mock with the given status/headers/body.
	 *
	 * @param int $status The HTTP status code.
	 * @param array<string, string> $headers Header name => value.
	 * @param string $body The response body.
	 *
	 * @return IResponse
	 */
	private function response(int $status, array $headers, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		$response->method('getHeader')->willReturnCallback(
			static fn (string $key): string => $headers[$key] ?? ''
		);

		return $response;
	}//end response()

	/**
	 * A client service mock whose `get()` returns `$response`.
	 *
	 * @param IResponse $response The canned response.
	 *
	 * @return IClientService
	 */
	private function clientServiceReturning(IResponse $response): IClientService {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return $clientService;
	}//end clientServiceReturning()

	/**
	 * The egress guard's rejection is returned as the tool result and NO HTTP
	 * request is ever attempted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-an-agent-chosen-url-resolves-to-a-private-address
	 */
	public function testGuardRejectionPreventsAnyRequest(): void {
		$guard = $this->createMock(WebResearchEgressGuard::class);
		$guard->method('assertSafe')->willReturn(['allowed' => false, 'code' => 'private_address', 'message' => 'blocked']);

		$clientService = $this->createMock(IClientService::class);
		$clientService->expects($this->never())->method('newClient');

		$service = new WebFetchService(
			$clientService,
			$this->settings(),
			$guard,
			new ReadableTextExtractor(),
			new NullLogger()
		);

		$result = $service->fetch(url: 'https://10.0.0.5/');

		$this->assertSame('private_address', $result['error']['code']);

	}//end testGuardRejectionPreventsAnyRequest()

	/**
	 * A 3xx response is NOT followed; a structured error names the redirect target.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-a-fetch-target-returns-a-redirect
	 */
	public function testRedirectIsNotFollowedAndTargetIsSurfaced(): void {
		$response = $this->response(302, ['Location' => 'https://internal.example.test/admin'], '');
		$clientService = $this->clientServiceReturning($response);

		$service = new WebFetchService($clientService, $this->settings(), $this->allowingGuard(), new ReadableTextExtractor(), new NullLogger());

		$result = $service->fetch(url: 'https://example.test/redirecting');

		$this->assertSame('redirect_not_followed', $result['error']['code']);
		$this->assertSame('https://internal.example.test/admin', $result['location']);

	}//end testRedirectIsNotFollowedAndTargetIsSurfaced()

	/**
	 * A non-text content type is rejected before any extraction is attempted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-an-agent-fetches-a-non-text-resource
	 */
	public function testRejectsNonTextContentType(): void {
		$response = $this->response(200, ['Content-Type' => 'application/pdf'], '%PDF-1.4 binary junk');
		$clientService = $this->clientServiceReturning($response);

		$service = new WebFetchService($clientService, $this->settings(), $this->allowingGuard(), new ReadableTextExtractor(), new NullLogger());

		$result = $service->fetch(url: 'https://example.test/file.pdf');

		$this->assertSame('unsupported_content_type', $result['error']['code']);

	}//end testRejectsNonTextContentType()

	/**
	 * A response larger than `maxResponseBytes` is truncated with `truncated: true`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-a-response-exceeds-the-configured-size-cap
	 */
	public function testOversizedResponseIsTruncated(): void {
		$body = str_repeat('a', 1000);
		$response = $this->response(200, ['Content-Type' => 'text/plain'], $body);
		$clientService = $this->clientServiceReturning($response);

		$service = new WebFetchService(
			$clientService,
			$this->settings(['maxResponseBytes' => 100]),
			$this->allowingGuard(),
			new ReadableTextExtractor(),
			new NullLogger()
		);

		$result = $service->fetch(url: 'https://example.test/big');

		$this->assertTrue($result['truncated']);

	}//end testOversizedResponseIsTruncated()

	/**
	 * A successful HTML fetch wraps the extracted text in the untrusted-content
	 * markers and includes the source URL.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-an-agents-tool-call-result-is-passed-back-into-the-conversation
	 */
	public function testSuccessfulFetchIsDelimitedAsUntrusted(): void {
		$response = $this->response(200, ['Content-Type' => 'text/html; charset=utf-8'], '<html><body><p>Hello there.</p></body></html>');
		$clientService = $this->clientServiceReturning($response);

		$service = new WebFetchService($clientService, $this->settings(), $this->allowingGuard(), new ReadableTextExtractor(), new NullLogger());

		$result = $service->fetch(url: 'https://example.test/page');

		$this->assertFalse($result['truncated']);
		$this->assertSame('https://example.test/page', $result['url']);
		$this->assertStringContainsString('BEGIN UNTRUSTED WEB CONTENT', $result['content']);
		$this->assertStringContainsString('END UNTRUSTED WEB CONTENT', $result['content']);
		$this->assertStringContainsString('Hello there.', $result['content']);
		$this->assertStringContainsString('https://example.test/page', $result['content']);

	}//end testSuccessfulFetchIsDelimitedAsUntrusted()

	/**
	 * A plain-text response is returned as-is (no HTML extraction attempted).
	 *
	 * @return void
	 */
	public function testPlainTextIsNotHtmlExtracted(): void {
		$response = $this->response(200, ['Content-Type' => 'text/plain'], 'Line one.
Line two.');
		$clientService = $this->clientServiceReturning($response);

		$service = new WebFetchService($clientService, $this->settings(), $this->allowingGuard(), new ReadableTextExtractor(), new NullLogger());

		$result = $service->fetch(url: 'https://example.test/notes.txt');

		$this->assertStringContainsString('Line one.', $result['content']);
		$this->assertStringContainsString('Line two.', $result['content']);

	}//end testPlainTextIsNotHtmlExtracted()

	/**
	 * A 4xx/5xx status is reported as a structured error, never thrown.
	 *
	 * @return void
	 */
	public function testServerErrorStatusReturnsStructuredError(): void {
		$response = $this->response(500, ['Content-Type' => 'text/html'], 'oops');
		$clientService = $this->clientServiceReturning($response);

		$service = new WebFetchService($clientService, $this->settings(), $this->allowingGuard(), new ReadableTextExtractor(), new NullLogger());

		$result = $service->fetch(url: 'https://example.test/broken');

		$this->assertSame('fetch_failed', $result['error']['code']);

	}//end testServerErrorStatusReturnsStructuredError()

	/**
	 * A connection-level exception (timeout, DNS failure at connect time, etc.)
	 * never throws across the service boundary — it becomes a structured error.
	 *
	 * @return void
	 */
	public function testConnectionFailureNeverThrows(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new RuntimeException('Connection timed out'));

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$service = new WebFetchService($clientService, $this->settings(), $this->allowingGuard(), new ReadableTextExtractor(), new NullLogger());

		$result = $service->fetch(url: 'https://example.test/down');

		$this->assertSame('fetch_failed', $result['error']['code']);

	}//end testConnectionFailureNeverThrows()
}//end class
