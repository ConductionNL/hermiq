<?php

/**
 * Hermiq runCode tool (tools-code-sandbox).
 *
 * Runs an agent's code in the code sandbox. Before anything is read or sent,
 * the AI feature `agent-code-execution` must be enabled: absent, unreadable
 * or disabled refuses the call, whatever the agent's grants. Files the call
 * names are read as the acting user from their own Files and copied into the
 * run as text. Never throws: every failure is an error envelope.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\CodeSandbox
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-an-agent-runs-code-only-with-a-grant-and-an-enabled-ai-feature-req-sandbox-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\CodeSandbox;

use InvalidArgumentException;
use OCA\Hermiq\Mcp\CodeSandboxToolDescriptors;
use OCA\Hermiq\Service\AiFeature\AiFeatureLookup;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The runCode tool behind its AI feature gate.
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-an-agent-runs-code-only-with-a-grant-and-an-enabled-ai-feature-req-sandbox-002
 */
class RunCodeTool {

	/**
	 * The AI feature that must be enabled before any code runs.
	 *
	 * @var string
	 */
	public const FEATURE_SLUG = 'agent-code-execution';

	/**
	 * The refusal while the feature is not enabled.
	 *
	 * @var string
	 */
	public const NOT_ENABLED = 'Running code must be enabled as an AI feature before an agent can run code.';

	/**
	 * The largest file one call copies into a run, in bytes.
	 *
	 * @var int
	 */
	public const MAX_FILE_BYTES = 1048576;

	/**
	 * Constructor.
	 *
	 * @param AiFeatureLookup     $features   The AI feature register.
	 * @param CodeSandboxClient   $client     Sends the run.
	 * @param CodeSandboxSettings $settings   Where code runs, for the reach.
	 * @param IRootFolder         $rootFolder The acting user's Files.
	 * @param LoggerInterface     $logger     Records failures, never code or output.
	 *
	 * @spec openspec/changes/tools-code-sandbox/tasks.md#task-3-the-runcode-tool-and-its-ai-feature-gate
	 */
	public function __construct(
		private readonly AiFeatureLookup $features,
		private readonly CodeSandboxClient $client,
		private readonly CodeSandboxSettings $settings,
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the call's code as the acting user.
	 *
	 * @param string               $uid       The acting user.
	 * @param string               $toolId    The tool id; only runCode is served.
	 * @param array<string, mixed> $arguments language, code, stdin, files [{path}], timeoutSeconds, memoryMb.
	 *
	 * @return array<string, mixed> exitCode, stdout, stderr, durationMs, timedOut, files, limits, language
	 *   and reach; or `['error' => ['code', 'message']]`.
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#scenario-the-ai-feature-is-off
	 */
	public function invoke(string $uid, string $toolId, array $arguments): array {
		if ($toolId !== CodeSandboxToolDescriptors::RUN_CODE) {
			return self::err(code: 'unknown_tool', message: "Unknown tool id '" . $toolId . "'.");
		}

		if ($this->featureEnabled() === false) {
			return self::err(code: 'code_execution_not_enabled', message: self::NOT_ENABLED);
		}

		$language = (string)($arguments['language'] ?? '');
		$code = ($arguments['code'] ?? null);
		if (is_string($code) === false || trim($code) === '') {
			return self::err(code: 'invalid_argument', message: 'The code to run is required.');
		}

		$files = $this->files(uid: $uid, requested: ($arguments['files'] ?? []));
		if (isset($files['error']) === true) {
			return $files;
		}

		$reach = $this->settings->reach();
		if ($files !== []) {
			$reach = CodeSandboxToolDescriptors::atLeastUser(reach: $reach);
		}

		return $this->dispatch(
			uid: $uid,
			language: $language,
			code: $code,
			stdin: (string)($arguments['stdin'] ?? ''),
			files: $files,
			limits: ['wallSeconds' => ($arguments['timeoutSeconds'] ?? null), 'memoryMb' => ($arguments['memoryMb'] ?? null)],
			reach: $reach
		);
	}//end invoke()

	/**
	 * Send the run and shape its answer.
	 *
	 * @param string                                          $uid      The acting user.
	 * @param string                                          $language The language.
	 * @param string                                          $code     The code.
	 * @param string                                          $stdin    The input.
	 * @param array<int, array{name: string, content: string}> $files    The input files.
	 * @param array<string, mixed>                            $limits   The requested limits.
	 * @param string                                          $reach    The resolved reach.
	 *
	 * @return array<string, mixed>
	 */
	private function dispatch(string $uid, string $language, string $code, string $stdin, array $files, array $limits, string $reach): array {
		try {
			$result = $this->client->run(language: $language, code: $code, stdin: $stdin, files: $files, limits: $limits, uid: $uid);
		} catch (CodeSandboxUnavailableException $e) {
			return self::err(code: 'code_sandbox_unavailable', message: $e->getMessage());
		} catch (InvalidArgumentException $e) {
			return self::err(code: 'invalid_argument', message: $e->getMessage());
		} catch (RuntimeException $e) {
			$this->logger->warning('[RunCodeTool] the run failed', ['reason' => $e->getMessage()]);
			return self::err(code: 'code_run_failed', message: $e->getMessage());
		}

		return $result + ['language' => $language, 'reach' => $reach];
	}//end dispatch()

	/**
	 * The named files as run input, read as the acting user, or an error envelope.
	 *
	 * @param string $uid       The acting user.
	 * @param mixed  $requested The `files` argument: a list of {path}.
	 *
	 * @return array<int|string, mixed> A list of {name, content}, or `['error' => ...]`.
	 */
	private function files(string $uid, mixed $requested): array {
		if ($requested === [] || $requested === null) {
			return [];
		}

		if (is_array($requested) === false || array_is_list($requested) === false || count($requested) > CodeSandboxToolDescriptors::MAX_FILES) {
			return self::err(code: 'invalid_argument', message: 'files is a list of at most ' . CodeSandboxToolDescriptors::MAX_FILES . ' paths.');
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($uid);
		} catch (Throwable $e) {
			$this->logger->warning('[RunCodeTool] the acting user\'s Files could not be opened', ['reason' => $e->getMessage()]);
			return self::err(code: 'not_found', message: 'Your files could not be opened.');
		}

		$files = [];
		foreach ($requested as $entry) {
			$path = '';
			if (is_array($entry) === true) {
				$path = trim((string)($entry['path'] ?? ''));
			}

			if (trim($path, '/') === '') {
				return self::err(code: 'invalid_argument', message: 'Every file needs a path.');
			}

			// IDOR: resolved inside the acting user's own folder only.
			if ($userFolder->nodeExists($path) === false) {
				return self::err(code: 'not_found', message: 'File not found in your files: ' . $path);
			}

			$node = $userFolder->get($path);
			if (($node instanceof File) === false) {
				return self::err(code: 'not_a_file', message: 'Path is not a file: ' . $path);
			}

			if ($node->getSize() > self::MAX_FILE_BYTES) {
				return self::err(code: 'file_too_large', message: 'A file copied into a run may be at most 1 MB: ' . $path);
			}

			$files[] = ['name' => basename($path), 'content' => (string)$node->getContent()];
		}//end foreach

		return $files;
	}//end files()

	/**
	 * Whether the AI feature is explicitly enabled; any failure reads as not.
	 * The code-step flow node asks the same gate.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-a-flow-can-run-a-code-step-over-each-item-req-sandbox-003
	 */
	public function featureEnabled(): bool {
		try {
			$feature = $this->features->findBySlug(self::FEATURE_SLUG);
			if ($feature === null) {
				return false;
			}

			$data = $feature->getObject();
		} catch (Throwable $e) {
			$this->logger->warning('[RunCodeTool] the agent-code-execution feature lookup failed', ['reason' => $e->getMessage()]);
			return false;
		}

		return ($data['enabled'] ?? false) === true;
	}//end featureEnabled()

	/**
	 * An error envelope.
	 *
	 * @param string $code    The machine code.
	 * @param string $message The message.
	 *
	 * @return array{error: array{code: string, message: string}}
	 */
	private static function err(string $code, string $message): array {
		return ['error' => ['code' => $code, 'message' => $message]];
	}//end err()
}//end class
