<?php

/**
 * Unit tests for BrowserClient: one browser action through AppAPI, with a fresh run token.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Browser
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/browser-use/spec.md#requirement-the-browser-reaches-only-what-the-egress-policy-allows-req-browse-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Browser;

use OCA\Hermiq\Service\Browser\BrowserClient;
use OCA\Hermiq\Service\Browser\BrowserRefusedException;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxClient;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxUnavailableException;
use OCA\Hermiq\Service\Llm\RunTokenService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for BrowserClient.
 */
class BrowserClientTest extends TestCase {

	/**
	 * An AppAPI double recording its calls.
	 *
	 * @param mixed $answer The exAppRequest() answer.
	 *
	 * @return object
	 */
	private function appApi(mixed $answer): object {
		return new class($answer) {
			/** @var list<array<int, mixed>> */
			public array $calls = [];

			public function __construct(private readonly mixed $answer) {
			}

			public function exAppRequest(mixed ...$args): mixed {
				$this->calls[] = $args;
				return $this->answer;
			}
		};
	}//end appApi()

	/**
	 * A response double.
	 *
	 * @param int    $status The status.
	 * @param string $body   The body.
	 *
	 * @return object
	 */
	private function response(int $status, string $body): object {
		return new class($status, $body) {
			public function __construct(private readonly int $status, private readonly string $body) {
			}

			public function getStatusCode(): int {
				return $this->status;
			}

			public function getBody(): string {
				return $this->body;
			}
		};
	}//end response()

	/**
	 * The client over an AppAPI double.
	 *
	 * @param object|null          $appApi The AppAPI double, or null when AppAPI is absent.
	 * @param RunTokenService|null $tokens The token service.
	 *
	 * @return BrowserClient
	 */
	private function client(?object $appApi, ?RunTokenService $tokens = null): BrowserClient {
		$container = $this->createMock(ContainerInterface::class);
		if ($appApi === null) {
			$container->method('get')->willThrowException(new RuntimeException('no AppAPI'));
		} else {
			$container->method('get')->with(CodeSandboxClient::APP_API_PUBLIC_FUNCTIONS)->willReturn($appApi);
		}

		return new BrowserClient(
			runTokens: ($tokens ?? $this->createMock(RunTokenService::class)),
			logger: $this->createMock(LoggerInterface::class),
			container: $container,
		);
	}//end client()

	/**
	 * Every call mints a short-lived token bound to the agent, user and conversation and sends it.
	 *
	 * @return void
	 */
	public function testEveryActionCarriesAFreshRunToken(): void {
		$tokens = $this->createMock(RunTokenService::class);
		$tokens->expects($this->once())->method('mint')->with(
			'browser:conv-1',
			'agent-1',
			'alice',
			'conv-1',
			BrowserClient::TOKEN_TTL_SECONDS
		)->willReturn('tok-1');
		$appApi = $this->appApi($this->response(200, '{"url":"https://example.org/","title":"Example","snapshot":"[e1] link \"More\"","truncated":false}'));

		$page = $this->client(appApi: $appApi, tokens: $tokens)->perform(
			action: 'open',
			uid: 'alice',
			agentId: 'agent-1',
			conversationId: 'conv-1',
			arguments: ['url' => 'https://example.org/']
		);

		$this->assertSame('Example', $page['title']);
		$this->assertCount(1, $appApi->calls);
		[$exApp, $route, $uid, $method, $payload] = $appApi->calls[0];
		$this->assertSame(CodeSandboxClient::EXAPP_ID, $exApp);
		$this->assertSame('/browser/open', $route);
		$this->assertSame('alice', $uid);
		$this->assertSame('POST', $method);
		$this->assertSame(['conversationId' => 'conv-1', 'runToken' => 'tok-1', 'url' => 'https://example.org/'], $payload);
	}//end testEveryActionCarriesAFreshRunToken()

	/**
	 * A refusal of the browser service becomes a BrowserRefusedException with its code.
	 *
	 * @return void
	 */
	public function testARefusalKeepsItsCode(): void {
		$appApi = $this->appApi($this->response(422, '{"error":"The agent does not type passwords.","code":"password_field"}'));

		try {
			$this->client(appApi: $appApi)->perform(action: 'fill', uid: 'alice', agentId: 'a', conversationId: 'c', arguments: ['ref' => 'e2', 'text' => 'x']);
			$this->fail('A refusal must throw.');
		} catch (BrowserRefusedException $e) {
			$this->assertSame('password_field', $e->refusal);
			$this->assertSame('The agent does not type passwords.', $e->getMessage());
		}
	}//end testARefusalKeepsItsCode()

	/**
	 * No AppAPI, an AppAPI failure array and a 503 all read as an unavailable sandbox, before a token is minted where possible.
	 *
	 * @return void
	 */
	public function testAnAbsentSandboxIsUnavailable(): void {
		$tokens = $this->createMock(RunTokenService::class);
		$tokens->expects($this->never())->method('mint');
		try {
			$this->client(appApi: null, tokens: $tokens)->perform(action: 'read', uid: 'alice', agentId: 'a', conversationId: 'c', arguments: []);
			$this->fail('No AppAPI must throw.');
		} catch (CodeSandboxUnavailableException $e) {
			$this->assertSame(CodeSandboxUnavailableException::NOT_INSTALLED, $e->getMessage());
		}

		$this->expectException(CodeSandboxUnavailableException::class);
		$this->client(appApi: $this->appApi(['error' => 'unreachable']))->perform(action: 'read', uid: 'alice', agentId: 'a', conversationId: 'c', arguments: []);
	}//end testAnAbsentSandboxIsUnavailable()

	/**
	 * The answer mapping: 503 unavailable, 5xx and non-JSON fail, an unknown action is refused.
	 *
	 * @return void
	 */
	public function testAnswerMapping(): void {
		$this->assertSame(['url' => 'u'], BrowserClient::answerOf(status: 200, body: '{"url":"u"}'));

		try {
			BrowserClient::answerOf(status: 503, body: '{"code":"browser_unavailable"}');
			$this->fail('503 must throw.');
		} catch (CodeSandboxUnavailableException $e) {
			$this->assertStringContainsString('not running', $e->getMessage());
		}

		try {
			BrowserClient::answerOf(status: 502, body: '{"error":"x","code":"browser_failed"}');
			$this->fail('502 must throw.');
		} catch (BrowserRefusedException) {
			$this->fail('A 5xx is not a refusal.');
		} catch (RuntimeException $e) {
			$this->assertSame('The browser could not complete the action (HTTP 502).', $e->getMessage());
		}

		$this->expectException(RuntimeException::class);
		$this->client(appApi: $this->appApi(null))->perform(action: 'drop', uid: 'alice', agentId: 'a', conversationId: 'c', arguments: []);
	}//end testAnswerMapping()
}//end class
