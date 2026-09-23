<?php

/**
 * A credential broker that answers from a script and remembers every call.
 *
 * Hand written rather than `createMock()`, because what the GitHubBroker tests
 * check is mostly what was SENT: the method, the path and the decoded JSON body
 * of each round trip, in order. A mock records invocations well enough, but the
 * multi-step methods — branch-then-create, read-blob-then-put — need the answer
 * to depend on which call this is, and a scripted double states that plainly
 * where a chain of `willReturnOnConsecutiveCalls` would state it by position.
 *
 * It deliberately does NOT extend OpenRegister's `CredentialBrokerService`. The
 * class under test resolves that service by class-string out of a container and
 * calls `request()` on whatever comes back, so nothing here is type-checked; a
 * double that inherited the real class would inherit its stub body too, which is
 * the one thing it must not do.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Support;

use Closure;

/**
 * Answers brokered requests from a closure, recording each one.
 */
final class RecordingCredentialBroker {

	/**
	 * Every call made, in order.
	 *
	 * @var array<int,array{credentialId:string,appId:string,method:string,path:string,headers:array<string,string>,body:string|null,userId:string|null}>
	 */
	public array $calls = [];

	/**
	 * Constructor.
	 *
	 * @param Closure $responder Takes the method, path and body; answers `['status' => int, 'body' => string]`.
	 */
	public function __construct(private readonly Closure $responder) {
	}//end __construct()

	/**
	 * Proxy one request, as the real broker would.
	 *
	 * @param string $credentialId The credential UUID.
	 * @param string $appId The calling app.
	 * @param string $method The HTTP method.
	 * @param string $path The provider-relative path.
	 * @param array<string,string> $headers Request headers.
	 * @param string|null $body The raw request body.
	 * @param string|null $actingUserId The credential owner.
	 *
	 * @return array{status:int,body:string} The scripted response.
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
		$this->calls[] = [
			'credentialId' => $credentialId,
			'appId' => $appId,
			'method' => $method,
			'path' => $path,
			'headers' => $headers,
			'body' => $body,
			'userId' => $actingUserId,
		];

		return ($this->responder)($method, $path, $body);
	}//end request()

	/**
	 * The methods of every recorded call, in order.
	 *
	 * @return array<int,string> The HTTP methods.
	 */
	public function methods(): array {
		return array_column($this->calls, 'method');
	}//end methods()

	/**
	 * `METHOD path` for every recorded call, in order.
	 *
	 * @return array<int,string> The recorded routes.
	 */
	public function routes(): array {
		return array_map(
			static fn (array $call): string => $call['method'] . ' ' . $call['path'],
			$this->calls
		);
	}//end routes()

	/**
	 * The decoded JSON body of one recorded call.
	 *
	 * @param int $index Which call, zero based.
	 *
	 * @return array<string,mixed> The decoded body, or an empty array when there was none.
	 */
	public function decodedBody(int $index): array {
		$body = $this->calls[$index]['body'] ?? null;
		if (is_string($body) === false) {
			return [];
		}

		$decoded = json_decode($body, true);

		return is_array($decoded) ? $decoded : [];
	}//end decodedBody()
}//end class
