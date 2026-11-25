<?php

/**
 * Unit tests for ProviderCallFailedException: how a provider failure is
 * typed from an HTTP status, a Retry-After header or a thrown cause, and
 * which kinds move the turn to a fallback model.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-refusal-or-a-configuration-error-never-triggers-a-fallback-req-mfail-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use OCA\Hermiq\Service\Llm\ProviderCallFailedException;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\Llm\ProviderCallFailedException
 */
class ProviderCallFailedExceptionTest extends TestCase {

	/**
	 * Status codes and the kind each one is typed as.
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function statuses(): array {
		return [
			'429' => [429, ProviderCallFailedException::RATE_LIMIT],
			'401' => [401, ProviderCallFailedException::AUTH],
			'403' => [403, ProviderCallFailedException::AUTH],
			'408' => [408, ProviderCallFailedException::TIMEOUT],
			'500' => [500, ProviderCallFailedException::SERVER],
			'599' => [599, ProviderCallFailedException::SERVER],
			'400' => [400, ProviderCallFailedException::BAD_REQUEST],
			'404' => [404, ProviderCallFailedException::BAD_REQUEST],
			'499' => [499, ProviderCallFailedException::BAD_REQUEST],
			'302' => [302, ProviderCallFailedException::UNKNOWN],
			'600' => [600, ProviderCallFailedException::UNKNOWN],
		];
	}//end statuses()

	/**
	 * Each status maps to its kind.
	 *
	 * @dataProvider statuses
	 *
	 * @param int    $status The HTTP status.
	 * @param string $kind   The expected kind.
	 *
	 * @return void
	 */
	public function testAStatusIsTypedByItsKind(int $status, string $kind): void {
		$this->assertSame($kind, ProviderCallFailedException::kindForStatus(status: $status));
	}//end testAStatusIsTypedByItsKind()

	/**
	 * Only temporary kinds move the turn to another model.
	 *
	 * @return void
	 */
	public function testOnlyTemporaryKindsMoveTheTurn(): void {
		foreach (ProviderCallFailedException::TEMPORARY as $kind) {
			$this->assertTrue((new ProviderCallFailedException(message: 'x', kind: $kind))->movesTurn(), $kind);
		}

		foreach ([ProviderCallFailedException::AUTH, ProviderCallFailedException::BAD_REQUEST, ProviderCallFailedException::UNKNOWN] as $kind) {
			$this->assertFalse((new ProviderCallFailedException(message: 'x', kind: $kind))->movesTurn(), $kind);
		}
	}//end testOnlyTemporaryKindsMoveTheTurn()

	/**
	 * The constructor keeps the status as the code and the cause as previous.
	 *
	 * @return void
	 */
	public function testTheStatusIsTheCodeAndTheCauseIsKept(): void {
		$cause = new RuntimeException('cause');
		$failure = new ProviderCallFailedException(message: 'Failed', kind: ProviderCallFailedException::SERVER, httpStatus: 502, retryAfterSeconds: 3, previous: $cause);

		$this->assertSame('Failed', $failure->getMessage());
		$this->assertSame(502, $failure->getCode());
		$this->assertSame(502, $failure->httpStatus);
		$this->assertSame(3, $failure->retryAfterSeconds);
		$this->assertSame($cause, $failure->getPrevious());
		$this->assertSame(0, (new ProviderCallFailedException(message: 'x', kind: ProviderCallFailedException::UNKNOWN))->getCode());
	}//end testTheStatusIsTheCodeAndTheCauseIsKept()

	/**
	 * A status failure takes its kind from the status unless one is given, and reads a seconds Retry-After.
	 *
	 * @return void
	 */
	public function testFromHttpStatusTypesTheStatusAndReadsRetryAfterSeconds(): void {
		$failure = ProviderCallFailedException::fromHttpStatus(status: 429, message: 'Slow down', retryAfter: ' 30 ');

		$this->assertSame(ProviderCallFailedException::RATE_LIMIT, $failure->kind);
		$this->assertSame(429, $failure->httpStatus);
		$this->assertSame(30, $failure->retryAfterSeconds);
		$this->assertSame('Slow down', $failure->getMessage());

		$given = ProviderCallFailedException::fromHttpStatus(status: 500, message: 'x', kind: ProviderCallFailedException::UNAVAILABLE);
		$this->assertSame(ProviderCallFailedException::UNAVAILABLE, $given->kind);
		$this->assertNull($given->retryAfterSeconds);
	}//end testFromHttpStatusTypesTheStatusAndReadsRetryAfterSeconds()

	/**
	 * A Retry-After date counts down from now; a past date is zero; nonsense is ignored.
	 *
	 * @return void
	 */
	public function testARetryAfterDateCountsDownFromNow(): void {
		$future = gmdate(DATE_RFC7231, (time() + 120));
		$seconds = ProviderCallFailedException::fromHttpStatus(status: 503, message: 'x', retryAfter: $future)->retryAfterSeconds;
		$this->assertGreaterThanOrEqual(118, $seconds);
		$this->assertLessThanOrEqual(120, $seconds);

		$past = gmdate(DATE_RFC7231, (time() - 3600));
		$this->assertSame(0, ProviderCallFailedException::fromHttpStatus(status: 503, message: 'x', retryAfter: $past)->retryAfterSeconds);

		$this->assertNull(ProviderCallFailedException::fromHttpStatus(status: 503, message: 'x', retryAfter: 'soon')->retryAfterSeconds);
		$this->assertNull(ProviderCallFailedException::fromHttpStatus(status: 503, message: 'x', retryAfter: '')->retryAfterSeconds);
	}//end testARetryAfterDateCountsDownFromNow()

	/**
	 * A typed failure passes through unchanged; an unavailable provider is typed unavailable.
	 *
	 * @return void
	 */
	public function testFromThrowableKeepsATypedFailureAndTypesAnUnavailableProvider(): void {
		$typed = new ProviderCallFailedException(message: 'x', kind: ProviderCallFailedException::AUTH);
		$this->assertSame($typed, ProviderCallFailedException::fromThrowable(failure: $typed));

		$unavailable = new ProviderUnavailableException('No key configured');
		$failure = ProviderCallFailedException::fromThrowable(failure: $unavailable);
		$this->assertSame(ProviderCallFailedException::UNAVAILABLE, $failure->kind);
		$this->assertSame('No key configured', $failure->getMessage());
		$this->assertSame($unavailable, $failure->getPrevious());
	}//end testFromThrowableKeepsATypedFailureAndTypesAnUnavailableProvider()

	/**
	 * A cause with a response takes the response's status.
	 *
	 * @return void
	 */
	public function testFromThrowableReadsTheStatusOfAResponse(): void {
		$failure = ProviderCallFailedException::fromThrowable(failure: new ResponseFailure(status: 503));

		$this->assertSame(ProviderCallFailedException::SERVER, $failure->kind);
		$this->assertSame(503, $failure->httpStatus);
		$this->assertSame('upstream said no', $failure->getMessage());
	}//end testFromThrowableReadsTheStatusOfAResponse()

	/**
	 * Messages that name a status, a time-out or a connection failure are typed by it.
	 *
	 * @return array<string, array{0: string, 1: string, 2: int|null}>
	 */
	public static function messages(): array {
		return [
			'HTTP status' => ['Anthropic API error (HTTP 429): nope', ProviderCallFailedException::RATE_LIMIT, 429],
			'status code' => ['Request failed with status code 401', ProviderCallFailedException::AUTH, 401],
			'timed out' => ['Operation timed out after 30001 milliseconds', ProviderCallFailedException::TIMEOUT, null],
			'timeout' => ['Gateway timeout', ProviderCallFailedException::TIMEOUT, null],
			'resolve' => ['Could not resolve host: api.example.org', ProviderCallFailedException::CONNECTION, null],
			'refused' => ['Connection refused', ProviderCallFailedException::CONNECTION, null],
			'curl 7' => ['cURL error 7 while connecting', ProviderCallFailedException::CONNECTION, null],
			'other' => ['Something odd', ProviderCallFailedException::UNKNOWN, null],
			// Seen live: the openai-php transporter's message ends in the request URL,
			// whose `http://127...` must not read as HTTP status 127.
			'refused, with the URL' => [
				'cURL error 7: Failed to connect to 127.0.0.1 port 9 after 0 ms: Couldn\'t connect to server '
				. '(see https://curl.se/libcurl/c/libcurl-errors.html) for http://127.0.0.1:9/v1/chat/completions',
				ProviderCallFailedException::CONNECTION,
				null,
			],
			'url with a 3-digit host part' => ['Something odd at http://203.0.113.7/v1', ProviderCallFailedException::UNKNOWN, null],
			'HTTP/1.1 status line' => ['HTTP/1.1 503 Service Unavailable', ProviderCallFailedException::SERVER, 503],
		];
	}//end messages()

	/**
	 * A thrown cause is typed from its message.
	 *
	 * @dataProvider messages
	 *
	 * @param string   $message The cause's message.
	 * @param string   $kind    The expected kind.
	 * @param int|null $status  The expected status.
	 *
	 * @return void
	 */
	public function testFromThrowableTypesAMessage(string $message, string $kind, ?int $status): void {
		$cause = new RuntimeException($message);
		$failure = ProviderCallFailedException::fromThrowable(failure: $cause);

		$this->assertSame($kind, $failure->kind);
		$this->assertSame($status, $failure->httpStatus);
		$this->assertSame($message, $failure->getMessage());
		$this->assertSame($cause, $failure->getPrevious());
	}//end testFromThrowableTypesAMessage()

	/**
	 * The chain is read inward until a cause says what went wrong; the outer message stays.
	 *
	 * @return void
	 */
	public function testFromThrowableReadsTheCauseChain(): void {
		$wrapped = new RuntimeException('Broker call failed', 0, new RuntimeException('Connection reset by peer'));

		$failure = ProviderCallFailedException::fromThrowable(failure: $wrapped);

		$this->assertSame(ProviderCallFailedException::CONNECTION, $failure->kind);
		$this->assertSame('Broker call failed', $failure->getMessage());
		$this->assertSame($wrapped, $failure->getPrevious());
	}//end testFromThrowableReadsTheCauseChain()

	/**
	 * A refused connection wrapped by the OpenAI client moves the turn to its fallback.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002
	 */
	public function testARefusedConnectionWrappedByTheClientMovesTheTurn(): void {
		$message = 'cURL error 7: Failed to connect to 127.0.0.1 port 9 after 0 ms: Couldn\'t connect to server for http://127.0.0.1:9/v1/chat/completions';
		$wrapped = new RuntimeException(message: 'Failed to generate response: ' . $message, previous: new RuntimeException(message: $message));

		$failure = ProviderCallFailedException::fromThrowable(failure: $wrapped);

		$this->assertSame(expected: ProviderCallFailedException::CONNECTION, actual: $failure->kind);
		$this->assertNull(actual: $failure->httpStatus);
		$this->assertTrue(condition: $failure->movesTurn());
	}//end testARefusedConnectionWrappedByTheClientMovesTheTurn()

	/**
	 * A class named like a connect exception is a connection failure whatever its message.
	 *
	 * @return void
	 */
	public function testAConnectExceptionClassIsAConnectionFailure(): void {
		$failure = ProviderCallFailedException::fromThrowable(failure: new FakeConnectException('no details'));

		$this->assertSame(ProviderCallFailedException::CONNECTION, $failure->kind);
	}//end testAConnectExceptionClassIsAConnectionFailure()

}//end class

/**
 * A failure carrying a response, as an HTTP client's request exception does.
 */
class ResponseFailure extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param int $status The response status.
	 */
	public function __construct(private readonly int $status) {
		parent::__construct('upstream said no');
	}//end __construct()

	/**
	 * The response.
	 *
	 * @return object
	 */
	public function getResponse(): object {
		$status = $this->status;
		return new class($status) {
			/**
			 * Constructor.
			 *
			 * @param int $status The status.
			 */
			public function __construct(private readonly int $status) {
			}//end __construct()

			/**
			 * The status.
			 *
			 * @return int
			 */
			public function getStatusCode(): int {
				return $this->status;
			}//end getStatusCode()
		};
	}//end getResponse()

}//end class

/**
 * A failure whose class name says it could not connect.
 */
class FakeConnectException extends RuntimeException {
}//end class
