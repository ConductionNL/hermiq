<?php

/**
 * Reads the code sandbox's answers to an install and to a refused request
 * (tools-agent-workspace-and-installed-tools D4, D5).
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
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\CodeSandbox;

use RuntimeException;

/**
 * The shapes CodeSandboxClient reads from the sandbox, kept apart so the
 * client stays a transport.
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004
 */
final class SandboxAnswers {

	/**
	 * The reason a refusal names, or the fallback.
	 *
	 * @param string $body     The response body.
	 * @param string $fallback The reason without one.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-installed-tool-runs-with-an-argument-list-and-no-shell-req-wksp-005
	 */
	public static function refusalOf(string $body, string $fallback): string {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === true && is_string($decoded['error'] ?? null) === true) {
			return $decoded['error'];
		}

		return $fallback;
	}//end refusalOf()

	/**
	 * The install result from the installer's answer.
	 *
	 * @param mixed $decoded The decoded body.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When the answer is not an install result.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004
	 */
	public static function install(mixed $decoded): array {
		$layer = [];
		if (is_array($decoded) === true && is_array($decoded['layer'] ?? null) === true) {
			$layer = $decoded['layer'];
		}

		$sha256 = ($layer['sha256'] ?? null);
		$archive = ($layer['archive'] ?? null);
		if (is_string($sha256) === false || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1 || is_string($archive) === false) {
			throw new RuntimeException('The installer answered without a tool layer.');
		}

		$versions = [];
		foreach ((array)($decoded['versions'] ?? []) as $entry) {
			if (is_array($entry) === true && is_string($entry['name'] ?? null) === true && is_string($entry['version'] ?? null) === true) {
				$versions[] = ['name' => $entry['name'], 'version' => $entry['version']];
			}
		}

		return [
			'manager' => (string)($decoded['manager'] ?? ''),
			'package' => (string)($decoded['package'] ?? ''),
			'version' => (string)($decoded['version'] ?? ''),
			'versions' => $versions,
			'binaries' => array_values(array_filter((array)($decoded['binaries'] ?? []), 'is_string')),
			'layer' => ['sha256' => $sha256, 'archive' => $archive],
		];
	}//end install()
}//end class
