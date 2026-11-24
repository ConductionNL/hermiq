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
 * goes through integriq's CallService on the admin's chosen source and
 * nowhere else (HostedSandboxDispatch); without integriq or that source, a
 * hosted choice refuses every run rather than sending code anywhere.
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
	 * The sandbox's install route, forwarded to its installer service.
	 */
	public const INSTALL_ROUTE = '/install';

	/**
	 * Seconds an install may take, resolution and packing included.
	 */
	public const INSTALL_TIMEOUT_SECONDS = 600;

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
		if ($this->settings->backend() === CodeSandboxSettings::BACKEND_HOSTED) {
			return ($this->hosted()?->isAvailable(sourceId: (string)$this->settings->hostedSource()) === true);
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
		$payload = self::payload(language: $language, code: $code, stdin: $stdin, files: $files, limits: $limits);

		return $this->send(payload: $payload, uid: $uid);
	}//end run()

	/**
	 * Run code over a workspace copy: the files carry relative paths, and the run
	 * answers the files it created or changed.
	 *
	 * @param string                                           $language `python` or `node`.
	 * @param string                                           $code     The code.
	 * @param string                                           $stdin    What the code reads on stdin.
	 * @param array<int, array{name: string, content: string}> $files    The workspace files.
	 * @param array<string, mixed>                             $limits   `wallSeconds` and `memoryMb`; clamped to the ceilings.
	 * @param string|null                                      $uid      The acting user, passed to AppAPI.
	 *
	 * @return array<string, mixed> The run result and the limits it ran under.
	 *
	 * @throws InvalidArgumentException       When the language is not one the sandbox carries.
	 * @throws CodeSandboxUnavailableException When no sandbox can take the run; nothing was sent.
	 * @throws RuntimeException               When the sandbox could not carry the run out or answered malformed.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-sandbox-runs-work-on-the-workspace-and-write-back-what-they-change-req-wksp-002
	 */
	public function runOnWorkspace(
		string $language,
		string $code,
		string $stdin,
		array $files,
		array $limits,
		?string $uid,
	): array {
		$payload = self::payload(language: $language, code: $code, stdin: $stdin, files: $files, limits: $limits);
		$payload['workspace'] = true;

		return $this->send(payload: $payload, uid: $uid);
	}//end runOnWorkspace()

	/**
	 * Install one tool in the sandbox's installer and answer its layer.
	 *
	 * Only the own sandbox installs: its installer reaches the allowlisted
	 * registries through its own egress proxy. A hosted backend refuses before
	 * anything is sent.
	 *
	 * @param string      $manager The package manager: `pip` or `npm`.
	 * @param string      $package The package.
	 * @param string|null $version One exact version, or null for the latest.
	 * @param string|null $uid     The acting user, passed to AppAPI.
	 *
	 * @return array{manager: string, package: string, version: string, versions: array<int, array{name: string, version: string}>,
	 *   binaries: array<int, string>, layer: array{sha256: string, archive: string}} The installer's answer, the archive base64.
	 *
	 * @throws CodeSandboxUnavailableException When the own sandbox is not there; nothing was sent.
	 * @throws RuntimeException               When the installer refused the install or answered malformed.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SandboxAnswers is a stateless reader of the sandbox's answers.
	 */
	public function install(string $manager, string $package, ?string $version, ?string $uid): array {
		if ($this->settings->backend() === CodeSandboxSettings::BACKEND_HOSTED) {
			throw new CodeSandboxUnavailableException(CodeSandboxUnavailableException::INSTALL_NEEDS_EXAPP);
		}

		$result = $this->exAppCall(
			route: self::INSTALL_ROUTE,
			uid: $uid,
			payload: ['manager' => $manager, 'package' => $package, 'version' => $version],
			timeoutSeconds: self::INSTALL_TIMEOUT_SECONDS
		);

		$status = (int)$result->getStatusCode();
		if ($status < 200 || $status > 299) {
			$this->logger->warning('[CodeSandboxClient] the installer refused the install', ['status' => $status]);
			$fallback = 'The installer could not install the tool (HTTP ' . $status . ').';
			throw new RuntimeException(SandboxAnswers::refusalOf(body: (string)$result->getBody(), fallback: $fallback));
		}

		return SandboxAnswers::install(decoded: json_decode((string)$result->getBody(), true));
	}//end install()

	/**
	 * Run an installed tool on a workspace copy in the own sandbox.
	 *
	 * The run names the tool layer by its hash. When the sandbox answers 409
	 * `layer_missing`, the layer's bytes are sent with the run once more; the
	 * sandbox checks them against the hash before it unpacks anything.
	 *
	 * @param array<string, mixed>                             $call  `sha256`, `tool`, `args`, `stdin` and `limits`.
	 * @param array<int, array{name: string, content: string}> $files The workspace files.
	 * @param callable(): string                               $layer Reads the layer, base64, on a miss.
	 * @param string|null                                      $uid   The acting user, passed to AppAPI.
	 *
	 * @return array<string, mixed> The run result and the limits it ran under.
	 *
	 * @throws CodeSandboxUnavailableException When the own sandbox is not there; nothing was sent.
	 * @throws RuntimeException               When the sandbox refused the run or answered malformed.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-installed-tool-runs-with-an-argument-list-and-no-shell-req-wksp-005
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SandboxAnswers is a stateless reader of the sandbox's answers.
	 */
	public function runInstalled(array $call, array $files, callable $layer, ?string $uid): array {
		if ($this->settings->backend() === CodeSandboxSettings::BACKEND_HOSTED) {
			throw new CodeSandboxUnavailableException(CodeSandboxUnavailableException::RUN_INSTALLED_NEEDS_EXAPP);
		}

		$limits = self::clampLimits(limits: (array)($call['limits'] ?? []));
		$payload = [
			'installed' => [
				'sha256' => (string)$call['sha256'],
				'tool' => (string)$call['tool'],
				'args' => array_values((array)($call['args'] ?? [])),
			],
			'stdin' => (string)($call['stdin'] ?? ''),
			'files' => array_values($files),
			'limits' => $limits,
		];
		$timeout = ($limits['wallSeconds'] + self::TRANSPORT_SLACK_SECONDS);

		$result = $this->exAppCall(route: self::RUN_ROUTE, uid: $uid, payload: $payload, timeoutSeconds: $timeout);
		if ((int)$result->getStatusCode() === 409) {
			$payload['layer'] = $layer();
			$result = $this->exAppCall(route: self::RUN_ROUTE, uid: $uid, payload: $payload, timeoutSeconds: $timeout);
		}

		$status = (int)$result->getStatusCode();
		if ($status < 200 || $status > 299) {
			$this->logger->warning('[CodeSandboxClient] the sandbox refused the installed tool run', ['status' => $status]);
			$fallback = 'The code sandbox could not run the tool (HTTP ' . $status . ').';
			throw new RuntimeException(SandboxAnswers::refusalOf(body: (string)$result->getBody(), fallback: $fallback));
		}

		return self::mapResult(body: (string)$result->getBody()) + ['limits' => $limits];
	}//end runInstalled()

	/**
	 * The run contract's request, its limits clamped.
	 *
	 * @param string                                           $language `python` or `node`.
	 * @param string                                           $code     The code.
	 * @param string                                           $stdin    What the code reads on stdin.
	 * @param array<int, array{name: string, content: string}> $files    Input files.
	 * @param array<string, mixed>                             $limits   The requested limits.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When the language is not one the sandbox carries.
	 */
	private static function payload(string $language, string $code, string $stdin, array $files, array $limits): array {
		if (in_array($language, self::LANGUAGES, true) === false) {
			throw new InvalidArgumentException('The sandbox runs python or node.');
		}

		return [
			'language' => $language,
			'code'     => $code,
			'stdin'    => $stdin,
			'files'    => array_values($files),
			'limits'   => self::clampLimits(limits: $limits),
		];
	}//end payload()

	/**
	 * Send one run request to the chosen sandbox.
	 *
	 * @param array<string, mixed> $payload The run contract's request, its limits clamped.
	 * @param string|null          $uid     The acting user, passed to AppAPI.
	 *
	 * @return array<string, mixed> The run result and the limits it ran under.
	 *
	 * @throws CodeSandboxUnavailableException When no sandbox can take the run; nothing was sent.
	 * @throws RuntimeException               When the sandbox could not carry the run out or answered malformed.
	 */
	private function send(array $payload, ?string $uid): array {
		if ($this->settings->backend() === CodeSandboxSettings::BACKEND_HOSTED) {
			return $this->runHosted(payload: $payload, limits: $payload['limits']);
		}

		return $this->runOnExApp(payload: $payload, uid: $uid);
	}//end send()

	/**
	 * Run at the sandbox ExApp through AppAPI.
	 *
	 * @param array<string, mixed> $payload The run contract's request, its limits clamped.
	 * @param string|null          $uid     The acting user, passed to AppAPI.
	 *
	 * @return array<string, mixed> The run result and the limits it ran under.
	 *
	 * @throws CodeSandboxUnavailableException When the ExApp is not there; nothing was sent.
	 * @throws RuntimeException               When the sandbox refused the run or answered malformed.
	 */
	private function runOnExApp(array $payload, ?string $uid): array {
		$clamped = $payload['limits'];
		$result = $this->exAppCall(
			route: self::RUN_ROUTE,
			uid: $uid,
			payload: $payload,
			timeoutSeconds: ($clamped['wallSeconds'] + self::TRANSPORT_SLACK_SECONDS)
		);

		$status = (int)$result->getStatusCode();
		if ($status < 200 || $status > 299) {
			$this->logger->warning('[CodeSandboxClient] the sandbox refused the run', ['status' => $status]);
			throw new RuntimeException('The code sandbox could not run the code (HTTP ' . $status . ').');
		}

		return self::mapResult(body: (string)$result->getBody()) + ['limits' => $clamped];
	}//end runOnExApp()

	/**
	 * One request to the sandbox ExApp through AppAPI.
	 *
	 * @param string               $route          The ExApp route.
	 * @param string|null          $uid            The acting user, passed to AppAPI.
	 * @param array<string, mixed> $payload        The request body.
	 * @param int                  $timeoutSeconds The transport timeout.
	 *
	 * @return object The response: `getStatusCode()` and `getBody()`.
	 *
	 * @throws CodeSandboxUnavailableException When the ExApp is not there or cannot be reached.
	 */
	private function exAppCall(string $route, ?string $uid, array $payload, int $timeoutSeconds): object {
		$appApi = $this->appApi();
		if ($appApi === null) {
			throw new CodeSandboxUnavailableException(CodeSandboxUnavailableException::NOT_INSTALLED);
		}

		$result = $appApi->exAppRequest(self::EXAPP_ID, $route, $uid, 'POST', $payload, ['timeout' => $timeoutSeconds]);

		// AppAPI never throws: an array answer is its failure channel.
		if (is_array($result) === true) {
			$this->logger->warning(
				'[CodeSandboxClient] the sandbox ExApp could not be reached',
				['reason' => (string)($result['error'] ?? 'unknown')]
			);
			throw new CodeSandboxUnavailableException(CodeSandboxUnavailableException::NOT_INSTALLED);
		}

		return $result;
	}//end exAppCall()

	/**
	 * Run at the hosted sandbox through integriq.
	 *
	 * @param array<string, mixed>                  $payload The run contract's request.
	 * @param array{wallSeconds: int, memoryMb: int} $limits  The clamped limits.
	 *
	 * @return array{exitCode: int, stdout: string, stderr: string, durationMs: int, timedOut: bool,
	 *   files: array<int, mixed>, limits: array{wallSeconds: int, memoryMb: int}}
	 *
	 * @throws CodeSandboxUnavailableException When integriq or the source is not there; nothing was sent.
	 * @throws RuntimeException               When the run could not be carried out or answered malformed.
	 */
	private function runHosted(array $payload, array $limits): array {
		$hosted = $this->hosted();
		if ($hosted === null) {
			throw new CodeSandboxUnavailableException(HostedSandboxDispatch::SOURCE_MISSING);
		}

		$body = $hosted->run(
			sourceId: (string)$this->settings->hostedSource(),
			payload: $payload,
			timeoutSeconds: ($limits['wallSeconds'] + self::TRANSPORT_SLACK_SECONDS)
		);

		return self::mapResult(body: $body) + ['limits' => $limits];
	}//end runHosted()

	/**
	 * The hosted dispatch, or null without a container to reach integriq through.
	 *
	 * @return HostedSandboxDispatch|null
	 */
	private function hosted(): ?HostedSandboxDispatch {
		if ($this->container === null) {
			return null;
		}

		return new HostedSandboxDispatch(container: $this->container, logger: $this->logger);
	}//end hosted()

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
