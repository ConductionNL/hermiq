<?php

/**
 * Unit tests for EgressAuthorizeController — the governed egress PDP
 * (cli-runner-governed-mcp-and-egress).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\EgressAuthorizeController;
use OCA\Hermiq\Service\Llm\RunTokenService;
use OCA\Hermiq\Service\WebResearch\WebResearchEgressGuard;
use OCA\Hermiq\Service\WebResearch\WebResearchSettingsHandler;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Token-gated allow/deny straight from the shared egress guard.
 *
 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-agent-internet-access-is-governed-at-two-layers-by-one-allowed-url-policy
 */
final class EgressAuthorizeControllerTest extends TestCase {

	/**
	 * A guard double whose DNS resolution is deterministic (a public address), so allow/deny
	 * turns purely on the allowlist/denylist without a real network.
	 *
	 * @return WebResearchEgressGuard
	 */
	/**
	 * A throttler that records nothing — these tests assert HTTP outcomes, not
	 * brute-force bookkeeping.
	 *
	 * @return IThrottler
	 */
	private function throttlerStub(): IThrottler {
		return $this->createMock(IThrottler::class);
	}//end throttlerStub()

	private function guard(): WebResearchEgressGuard {
		return new class extends WebResearchEgressGuard {
			protected function resolveAddresses(string $host): array {
				return ['203.0.113.10'];
			}
		};

	}//end guard()

	/**
	 * A settings handler returning a fixed allowlist/denylist config.
	 *
	 * @param array<string, mixed> $config The web-research config.
	 *
	 * @return WebResearchSettingsHandler
	 */
	private function settings(array $config): WebResearchSettingsHandler {
		$handler = $this->createMock(WebResearchSettingsHandler::class);
		$handler->method('getWebResearchSettingsOnly')->willReturn($config);
		return $handler;
	}//end settings()

	/**
	 * Build a controller with an overridable raw body + a header double.
	 *
	 * @param RunTokenService $tokens The token service.
	 * @param WebResearchSettingsHandler $settings The settings handler.
	 * @param string $auth The Authorization header value.
	 * @param string $body The raw request body.
	 *
	 * @return EgressAuthorizeController
	 */
	private function controller(RunTokenService $tokens, WebResearchSettingsHandler $settings, string $auth, string $body): EgressAuthorizeController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static function (string $name) use ($auth): string {
				return ($name === 'Authorization') ? $auth : '';
			}
		);

		// NOTE the 5th argument. It used to be a NullLogger that the 4-parameter
		// parent constructor silently DISCARDED — PHP ignores extra positional
		// arguments to user-defined functions. Adding $throttler as a real 5th
		// parameter made that stray argument bind, so it now has to be the
		// thing the parent actually expects.
		return new class($request, $tokens, $this->guard(), $settings, $this->throttlerStub(), $body) extends EgressAuthorizeController {
			public function __construct(
				$request,
				$tokens,
				$guard,
				$settings,
				$throttler,
				private string $rawBody,
			) {
				parent::__construct($request, $tokens, $guard, $settings, $throttler);
			}
			protected function readRawBody(): string {
				return $this->rawBody;
			}
		};

	}//end controller()

	/**
	 * A token service that recognises exactly one token.
	 *
	 * @param string $valid The one valid token.
	 *
	 * @return RunTokenService
	 */
	private function tokens(string $valid): RunTokenService {
		$tokens = $this->createMock(RunTokenService::class);
		$tokens->method('verify')->willReturnCallback(
			static function (string $token) use ($valid): ?array {
				if ($token === $valid) {
					return ['runId' => 'r', 'agentId' => 'a', 'userId' => 'alice', 'conversationId' => ''];
				}
				return null;
			}
		);
		return $tokens;
	}//end tokens()

	/**
	 * No/invalid token → 401 before any policy evaluation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-the-proxy-fails-closed-when-the-policy-endpoint-is-unavailable
	 */
	public function testMissingTokenIsRejected(): void {
		$controller = $this->controller(
			$this->tokens('good'),
			$this->settings(['fetchAllowlist' => [], 'fetchDenylist' => [], 'allowInsecureHttp' => false]),
			'',
			'{"host":"api.anthropic.com","port":443}'
		);

		$response = $controller->authorize();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testMissingTokenIsRejected()

	/**
	 * A missing host → 400.
	 *
	 * @return void
	 */
	public function testMissingHostIsBadRequest(): void {
		$controller = $this->controller(
			$this->tokens('good'),
			$this->settings(['fetchAllowlist' => [], 'fetchDenylist' => [], 'allowInsecureHttp' => false]),
			'Bearer good',
			'{"port":443}'
		);

		$response = $controller->authorize();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testMissingHostIsBadRequest()

	/**
	 * A non-allowlisted host is denied with the guard's own `not_allowlisted` code.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-the-proxy-denies-a-non-allowlisted-host-at-the-network-layer
	 */
	public function testNonAllowlistedHostIsDenied(): void {
		$controller = $this->controller(
			$this->tokens('good'),
			$this->settings(['fetchAllowlist' => ['api.anthropic.com'], 'fetchDenylist' => [], 'allowInsecureHttp' => false]),
			'Bearer good',
			'{"host":"attacker.example","port":443}'
		);

		$data = $controller->authorize()->getData();
		$this->assertFalse($data['allowed']);
		$this->assertSame('not_allowlisted', $data['code']);

	}//end testNonAllowlistedHostIsDenied()

	/**
	 * An allowlisted host that resolves public is permitted — the SAME policy `webFetch` uses.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-one-policy-source-governs-both-layers
	 */
	public function testAllowlistedHostIsAllowed(): void {
		$controller = $this->controller(
			$this->tokens('good'),
			$this->settings(['fetchAllowlist' => ['api.anthropic.com'], 'fetchDenylist' => [], 'allowInsecureHttp' => false]),
			'Bearer good',
			'{"host":"api.anthropic.com","port":443}'
		);

		$data = $controller->authorize()->getData();
		$this->assertTrue($data['allowed']);
		$this->assertNull($data['code']);

	}//end testAllowlistedHostIsAllowed()

	/**
	 * A denylisted host is refused, proving the deny path returns the guard's verdict verbatim.
	 *
	 * @return void
	 */
	public function testDenylistedHostIsDenied(): void {
		$controller = $this->controller(
			$this->tokens('good'),
			$this->settings(['fetchAllowlist' => [], 'fetchDenylist' => ['blocked.example'], 'allowInsecureHttp' => false]),
			'Bearer good',
			'{"host":"blocked.example","port":443}'
		);

		$data = $controller->authorize()->getData();
		$this->assertFalse($data['allowed']);
		$this->assertSame('denylisted_host', $data['code']);

	}//end testDenylistedHostIsDenied()
}//end class
