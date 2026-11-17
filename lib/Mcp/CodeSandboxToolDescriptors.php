<?php

/**
 * Hermiq code sandbox tool descriptor (tools-code-sandbox).
 *
 * Held outside `HermiqToolProvider` so that class stays inside its length
 * budget, like the other descriptor classes. `hermiq.runCode` is
 * write-classified (`scope: create`, `readOnlyHint: false`), so it is
 * default-denied: an agent needs it in `Agent.tools`. Its reach follows the
 * admin's choice of where code runs, so the catalogue is built from the
 * current setting rather than held as a constant.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-an-agent-runs-code-only-with-a-grant-and-an-enabled-ai-feature-req-sandbox-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\OpenRegister\Service\Capability\ToolReachResolver;

/**
 * Descriptor source for the runCode tool.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-an-agent-runs-code-only-with-a-grant-and-an-enabled-ai-feature-req-sandbox-002
 */
final class CodeSandboxToolDescriptors {

	/**
	 * The tool id.
	 *
	 * @var string
	 */
	public const RUN_CODE = AppIdentity::APP_ID . '.runCode';

	/**
	 * Every tool id this class describes.
	 *
	 * @var array<int, string>
	 */
	public const IDS = [self::RUN_CODE];

	/**
	 * The most files one call may name.
	 *
	 * @var int
	 */
	public const MAX_FILES = 10;

	/**
	 * The descriptors for a reach.
	 *
	 * `reach` is where the code and its input go: `self` in the own sandbox,
	 * `external` at a hosted one. `reachWithArguments` states that naming files
	 * of the acting user lifts the reach to at least `user`.
	 *
	 * @param string $reach The setting's reach: `self` or `external`.
	 *
	 * @return array<int, array<string, mixed>> The descriptors.
	 *
	 * @spec openspec/changes/tools-code-sandbox/tasks.md#task-3-the-runcode-tool-and-its-ai-feature-gate
	 */
	public static function forReach(string $reach): array {
		if ($reach !== ToolReachResolver::REACH_EXTERNAL) {
			$reach = ToolReachResolver::REACH_SELF;
		}

		return [
			[
				'id' => self::RUN_CODE,
				'subject' => 'codeRun',
				'action' => 'create',
				'reach' => $reach,
				'reachWithArguments' => ['files' => self::atLeastUser(reach: $reach)],
				'name' => 'Run code',
				'description' => 'Run a short Python or JavaScript program in an isolated sandbox without network '
					. 'access and with limits on time and memory, and get its exit code and output. Use it to '
					. 'calculate, to parse a file you were given, or to check your own reasoning. `files` copies '
					. 'text files from the acting user\'s Files into the run. Requires the agent code execution '
					. 'AI feature.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'language' => ['type' => 'string', 'enum' => ['python', 'node'], 'description' => 'The language.'],
						'code' => ['type' => 'string', 'description' => 'The program.'],
						'stdin' => ['type' => 'string', 'description' => 'What the program reads on standard input.'],
						'files' => [
							'type' => 'array',
							'maxItems' => self::MAX_FILES,
							'items' => [
								'type' => 'object',
								'properties' => ['path' => ['type' => 'string', 'description' => 'A path in the acting user\'s Files.']],
								'required' => ['path'],
							],
							'description' => 'Files of the acting user to place in the run\'s work directory.',
						],
						'timeoutSeconds' => ['type' => 'integer', 'description' => 'Wall time limit (default 30, max 300).'],
						'memoryMb' => ['type' => 'integer', 'description' => 'Memory limit (default 512, max 2048).'],
					],
					'required' => ['language', 'code'],
				],
				'readOnlyHint' => false,
				'destructiveHint' => false,
				'idempotentHint' => false,
				'scope' => 'create',
			],
		];
	}//end forReach()

	/**
	 * The reach lifted to at least `user`.
	 *
	 * @param string $reach The reach.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tools-code-sandbox/tasks.md#task-3-the-runcode-tool-and-its-ai-feature-gate
	 */
	public static function atLeastUser(string $reach): string {
		if ($reach === ToolReachResolver::REACH_SELF) {
			return ToolReachResolver::REACH_USER;
		}

		return $reach;
	}//end atLeastUser()
}//end class
