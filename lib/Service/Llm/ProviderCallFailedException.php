<?php

/**
 * Hermiq Provider Call Failed Exception.
 *
 * A provider call that failed, with the kind of failure: whether it is
 * temporary (a fallback may answer the turn) or a refusal or configuration
 * error that must reach the admin (it never moves a turn).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/models-several-models-per-turn/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

use DateTimeImmutable;
use Exception;
use Throwable;

/**
 * Typed provider failure (design: "Typed provider failures").
 *
 * It extends Exception and keeps the message the provider path always gave,
 * so every existing catch and every message a person reads is unchanged; the
 * kind is what the fallback loop reads.
 *
 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-refusal-or-a-configuration-error-never-triggers-a-fallback-req-mfail-003
 */
class ProviderCallFailedException extends Exception {

	public const RATE_LIMIT = 'rate_limit';

	public const SERVER = 'server';

	public const TIMEOUT = 'timeout';

	public const CONNECTION = 'connection';

	public const UNAVAILABLE = 'unavailable';

	public const AUTH = 'auth';

	public const BAD_REQUEST = 'bad_request';

	/**
	 * A failure that could not be classified; like a refusal it never moves a turn.
	 *
	 * @var string
	 */
	public const UNKNOWN = 'unknown';

	/**
	 * The kinds after which the next hop of the fallback chain may answer.
	 *
	 * @var array<int,string>
	 */
	public const TEMPORARY = [self::RATE_LIMIT, self::SERVER, self::TIMEOUT, self::CONNECTION, self::UNAVAILABLE];

	/**
	 * Constructor.
	 *
	 * @param string         $message           What failed, as the provider path words it.
	 * @param string         $kind              One of the kind constants.
	 * @param int|null       $httpStatus        The provider's HTTP status, when it answered.
	 * @param int|null       $retryAfterSeconds The provider's retry-after, when it sent one.
	 * @param Throwable|null $previous          The underlying failure.
	 *
	 * @spec openspec/changes/models-several-models-per-turn/tasks.md#task-2
	 */
	public function __construct(
		string $message,
		public readonly string $kind,
		public readonly ?int $httpStatus = null,
		public readonly ?int $retryAfterSeconds = null,
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: (int)($httpStatus ?? 0), previous: $previous);
	}//end __construct()

	/**
	 * A failure from a provider's HTTP answer.
	 *
	 * @param int         $status     The HTTP status.
	 * @param string      $message    The message the provider path gives.
	 * @param string|null $retryAfter The `retry-after` header, when sent.
	 * @param string|null $kind       A kind the caller knows better than the status (e.g. a 429 that is a refused credential).
	 *
	 * @return self
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-refusal-or-a-configuration-error-never-triggers-a-fallback-req-mfail-003
	 */
	public static function fromHttpStatus(int $status, string $message, ?string $retryAfter = null, ?string $kind = null): self {
		return new self(
			message: $message,
			kind: ($kind ?? self::kindForStatus(status: $status)),
			httpStatus: $status,
			retryAfterSeconds: self::retryAfterSeconds(header: $retryAfter)
		);
	}//end fromHttpStatus()

	/**
	 * Type a failure the LLM client raised, once, keeping its message.
	 *
	 * @param Throwable $failure The client's failure.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/models-several-models-per-turn/tasks.md#task-2
	 */
	public static function fromThrowable(Throwable $failure): self {
		if ($failure instanceof self) {
			return $failure;
		}

		if ($failure instanceof ProviderUnavailableException) {
			return new self(message: $failure->getMessage(), kind: self::UNAVAILABLE, previous: $failure);
		}

		// A client wraps its cause (the broker client keeps the transport
		// failure as `previous`), so the chain is read until something says
		// what went wrong; the message stays the outermost one.
		for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
			$status = self::statusOf(failure: $cause);
			if ($status !== null) {
				return new self(message: $failure->getMessage(), kind: self::kindForStatus(status: $status), httpStatus: $status, previous: $failure);
			}

			$kind = self::transportKind(failure: $cause);
			if ($kind !== self::UNKNOWN) {
				return new self(message: $failure->getMessage(), kind: $kind, previous: $failure);
			}
		}

		return new self(message: $failure->getMessage(), kind: self::UNKNOWN, previous: $failure);
	}//end fromThrowable()

	/**
	 * Whether the next hop of the fallback chain may answer after this failure.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002
	 */
	public function movesTurn(): bool {
		return in_array($this->kind, self::TEMPORARY, true);
	}//end movesTurn()

	/**
	 * The kind an HTTP status means: 429 rate limit, 5xx server, 401/403 auth, other 4xx a rejected request.
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/models-several-models-per-turn/tasks.md#task-2
	 */
	public static function kindForStatus(int $status): string {
		return match (true) {
			$status === 429 => self::RATE_LIMIT,
			$status === 401, $status === 403 => self::AUTH,
			$status === 408 => self::TIMEOUT,
			$status >= 500 && $status <= 599 => self::SERVER,
			$status >= 400 && $status <= 499 => self::BAD_REQUEST,
			default => self::UNKNOWN,
		};
	}//end kindForStatus()

	/**
	 * Seconds to wait from a `retry-after` header: a number of seconds, or an HTTP date.
	 *
	 * @param string|null $header The header value.
	 *
	 * @return int|null Null when absent or unreadable.
	 */
	private static function retryAfterSeconds(?string $header): ?int {
		$header = trim((string)$header);
		if ($header === '') {
			return null;
		}

		if (ctype_digit($header) === true) {
			return (int)$header;
		}

		$at = DateTimeImmutable::createFromFormat(DATE_RFC7231, $header);
		if ($at === false) {
			return null;
		}

		return max(0, $at->getTimestamp() - time());
	}//end retryAfterSeconds()

	/**
	 * The HTTP status a client failure carries, through its response or its message.
	 *
	 * @param Throwable $failure The failure.
	 *
	 * @return int|null
	 */
	private static function statusOf(Throwable $failure): ?int {
		if (method_exists($failure, 'getResponse') === true) {
			$response = $failure->getResponse();
			if (is_object($response) === true && method_exists($response, 'getStatusCode') === true) {
				return (int)$response->getStatusCode();
			}
		}

		if (preg_match('/\b(?:HTTP|status(?: code)?)[\s:\/]*([1-5]\d\d)\b/i', $failure->getMessage(), $matches) === 1) {
			return (int)$matches[1];
		}

		return null;
	}//end statusOf()

	/**
	 * Timeout or connection failure when the failure says so, else unknown.
	 *
	 * @param Throwable $failure The failure.
	 *
	 * @return string
	 */
	private static function transportKind(Throwable $failure): string {
		$message = strtolower($failure->getMessage());
		if (str_contains($message, 'timed out') === true || str_contains($message, 'timeout') === true) {
			return self::TIMEOUT;
		}

		if (preg_match('/could not resolve|connection (?:refused|reset)|failed to connect|connect error|curl error 7\b/', $message) === 1
			|| str_contains(get_class($failure), 'ConnectException') === true
		) {
			return self::CONNECTION;
		}

		return self::UNKNOWN;
	}//end transportKind()
}//end class
