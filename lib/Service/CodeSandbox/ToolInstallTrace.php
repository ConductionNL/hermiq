<?php

/**
 * The run record of a tool install (tools-agent-workspace-and-installed-tools D4).
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
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#scenario-an-agent-installs-csvkit
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\CodeSandbox;

/**
 * Lifts what an install's trace step records from its result: the manager,
 * the package, the resolved version and the layer's hash. Nothing else of the
 * answer is kept; a refused install records its error code.
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#scenario-an-agent-installs-csvkit
 */
final class ToolInstallTrace {

	/**
	 * The trace extra for an install result.
	 *
	 * @param mixed $result The tool's decoded result.
	 *
	 * @return array{toolInstall: array<string, string|null>}|array{} The record, or nothing for a result that is not an install's.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#scenario-an-agent-installs-csvkit
	 */
	public static function extra(mixed $result): array {
		if (is_array($result) === false) {
			return [];
		}

		if (is_array($result['error'] ?? null) === true) {
			return ['toolInstall' => ['error' => (string)($result['error']['code'] ?? '')]];
		}

		if (is_string($result['sha256'] ?? null) === false) {
			return [];
		}

		return [
			'toolInstall' => [
				'manager' => (string)($result['manager'] ?? ''),
				'package' => (string)($result['package'] ?? ''),
				'version' => (string)($result['version'] ?? ''),
				'sha256' => $result['sha256'],
			],
		];
	}//end extra()
}//end class
