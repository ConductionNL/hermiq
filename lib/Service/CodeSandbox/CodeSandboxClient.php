<?php

/**
 * Sends one code run to the chosen sandbox and returns its result.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\CodeSandbox
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\CodeSandbox;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The dispatch half of `hermiq.runCode` and `hermiq.code-step`.
 *
 * The own sandbox is the `hermiq-code-sandbox` ExApp, reached through AppAPI's
 * `POST /run` with `{language, code, stdin, files, limits}`. The hosted backend
 * goes through integriq's CallService and nowhere else; until that path is
 * wired, a hosted choice refuses every run rather than sending code anywhere.
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
 */
class CodeSandboxClient {

	/**
	 * AppAPI's public dispatch surface, resolved through the container.
	 */
	public const APP_API_PUBLIC_FUNCTIONS = 'OCA\\AppAPI\\PublicFunctions';

	/**
	 * App id of the sandbox ExApp.
	 */
	public const EXAPP_ID = 'hermiq-code-sandbox';

	/**
	 * The sandbox's run route.
	 */
	public const RUN_ROUTE = '/run';

	/**
	 * The languages the sandbox image carries.
	 */
	public const LANGUAGES = ['python', 'node'];

	/**
	 * Wall time per run in seconds: default and ceiling.
	 */
	public const DEFAULT_WALL_SECONDS = 30;
	public const MAX_WALL_SECONDS     = 300;

	/**
	 * Memory per run in megabytes: default and ceiling.
	 */
	public const DEFAULT_MEMORY_MB = 512;
	public const MAX_MEMORY_MB     = 2048;

	/**
	 * Seconds added to the run's own wall time for the AppAPI request.
	 */
	private const TRANSPORT_SLACK_SECONDS = 15;

	/**
	 * The refusal while the hosted path is not wired.
	 */
	public const HOSTED_NOT_WIRED = 'The hosted code sandbox cannot take runs yet. Choose the own sandbox in Admin settings, Code sandbox.';

	/**
	 * Constructor.
	 *
	 * @param CodeSandboxSettings     $settings  Where code runs.
	 * @param LoggerInterface         $logger    Records transport failures, never code or output.
	 * @param ContainerInterface|null $container Resolves AppAPI, an optional sibling app.
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
	 */
	public function __construct(
		private readonly CodeSandboxSettings $settings,
		private readonly LoggerInterface $logger,
		private readonly ?ContainerInterface $container=null,
	) {
	}//end __construct()

	/**
	 * Whether a run could be dispatched now.
	 *
	 * @return bool True when the chosen backend can take a run.
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
	 */
	public function isAvailable(): bool {
		if ($this->settings->backend() !== CodeSandboxSettings::BACKEND_EXAPP) {
			return false;
		}

		return $this->appApi() !== null;
	}//end isAvailable()

	/**
	 * Run code in the chosen sandbox.
	 *
	 * @param string                                     $language `python` or `node`.
	 * @param string                                     $code     The code.
	 * @param string                                     $stdin    What the code reads on stdin.
	 * @param array<int, array{name: string, content: string}> $files    Input files.
	 * @param array<string, mixed>                       $limits   `wallSeconds` and `memoryMb`; clamped to the ceilings.
	 * @param string|null                                $uid      The acting user, passed to AppAPI.
	 *
	 * @return array{exitCode: int, stdout: string, stderr: string, durationMs: int, timedOut: bool,
	 *   files: array<int, mixed>, limits: array{wallSeconds: int, memoryMb: int}} The run result and the limits it ran under.
	 *
	 * @throws InvalidArgumentException       When the language is not one the sandbox carries.
	 * @throws CodeSandboxUnavailableException When no sandbox can take the run; nothing was sent.
	 * @throws RuntimeException               When the sandbox could not carry the run out or answered malformed.
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
	 */
	public function run(
		string $language,
		string $code,
		string $stdin='',
		array $files=[],
		array $limits=[],
		?string $uid=null,
	): array {
		if (in_array($language, self::LANGUAGES, true) === false) {
			throw new InvalidArgumentException('The sandbox runs python or node.');
		}

		if ($this->settings->backend() === CodeSandboxSettings::BACKEND_HOSTED) {
			throw new CodeSandboxUnavailableException(self::HOSTED_NOT_WIRED);
		}

		$appApi = $this->appApi();
		if ($appApi === null) {
			throw new CodeSandboxUnavailableException(CodeSandboxUnavailableException::NOT_INSTALLED);
		}

		$clamped = self::clampLimits(limits: $limits);
		$result  = $appApi->exAppRequest(
			self::EXAPP_ID,
			self::RUN_ROUTE,
			$uid,
			'POST',
			[
				'language' => $language,
				'code' => $code,
				'stdin' => $stdin,
				'files' => array_values($files),
				'limits' => $clamped,
			],
			['timeout' => ($clamped['wallSeconds'] + self::TRANSPORT_SLACK_SECONDS)]
		);

		// AppAPI never throws: an array answer is its failure channel.
		if (is_array($result) === true) {
			$this->logger->warning(
				'[CodeSandboxClient] the sandbox ExApp could not be reached',
				['reason' => (string)($result['error'] ?? 'unknown')]
			);
			throw new CodeSandboxUnavailableException(CodeSandboxUnavailableException::NOT_INSTALLED);
		}

		$status = (int)$result->getStatusCode();
		if ($status < 200 || $status > 299) {
			$this->logger->warning('[CodeSandboxClient] the sandbox refused the run', ['status' => $status]);
			throw new RuntimeException('The code sandbox could not run the code (HTTP ' . $status . ').');
		}

		return self::mapResult(body: (string)$result->getBody()) + ['limits' => $clamped];
	}//end run()

	/**
	 * The limits a run is sent with: defaults filled in, each clamped to 1..ceiling.
	 *
	 * @param array<string, mixed> $limits The requested limits.
	 *
	 * @return array{wallSeconds: int, memoryMb: int}
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-sandbox-runs-code-without-network-without-data-mounts-and-within-limits-req-sandbox-001
	 */
	public static function clampLimits(array $limits): array {
		return [
			'wallSeconds' => self::clamp(value: ($limits['wallSeconds'] ?? null), default: self::DEFAULT_WALL_SECONDS, max: self::MAX_WALL_SECONDS),
			'memoryMb' => self::clamp(value: ($limits['memoryMb'] ?? null), default: self::DEFAULT_MEMORY_MB, max: self::MAX_MEMORY_MB),
		];
	}//end clampLimits()

	/**
	 * One limit: the default when absent or not a whole number, else within 1..max.
	 *
	 * @param mixed $value   The requested value.
	 * @param int   $default The default.
	 * @param int   $max     The ceiling.
	 *
	 * @return int
	 */
	private static function clamp(mixed $value, int $default, int $max): int {
		if (is_int($value) === false) {
			return $default;
		}

		return max(1, min($max, $value));
	}//end clamp()

	/**
	 * The run result from the sandbox's answer.
	 *
	 * @param string $body The response body.
	 *
	 * @return array{exitCode: int, stdout: string, stderr: string, durationMs: int, timedOut: bool, files: array<int, mixed>}
	 *
	 * @throws RuntimeException When the answer is not a run result.
	 */
	private static function mapResult(string $body): array {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === false || is_int($decoded['exitCode'] ?? null) === false) {
			throw new RuntimeException('The code sandbox answered without a run result.');
		}

		$files = ($decoded['files'] ?? []);
		if (is_array($files) === false) {
			$files = [];
		}

		return [
			'exitCode' => $decoded['exitCode'],
			'stdout' => (string)($decoded['stdout'] ?? ''),
			'stderr' => (string)($decoded['stderr'] ?? ''),
			'durationMs' => (int)($decoded['durationMs'] ?? 0),
			'timedOut' => (($decoded['timedOut'] ?? false) === true),
			'files' => array_values($files),
		];
	}//end mapResult()

	/**
	 * AppAPI's PublicFunctions, or null when AppAPI is not installed.
	 *
	 * @return object|null
	 */
	private function appApi(): ?object {
		if ($this->container === null) {
			return null;
		}

		try {
			$appApi = $this->container->get(self::APP_API_PUBLIC_FUNCTIONS);
		} catch (Throwable) {
			return null;
		}

		if (is_object($appApi) === false || method_exists($appApi, 'exAppRequest') === false) {
			return null;
		}

		return $appApi;
	}//end appApi()
}//end class
