<?php

/**
 * Hermiq CustomToolDescriptors
 *
 * Turns a stored custom tool into the descriptor HermiqToolProvider offers.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-a-custom-tool-is-offered-under-a-two-segment-id-and-only-by-exact-grant-req-custool-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\OpenRegister\Service\Capability\ToolReachResolver;

/**
 * The descriptor of one custom tool.
 *
 * The id is `hermiq.custom_<slug>`: two segments on purpose, because the grant
 * grammar reads three segments as `{app}.{schema}.{verb}`, and a wildcard such
 * as `hermiq.custom.*` must never reach a custom tool. Hints come from what
 * the admin declared; reach is `external` for every target, the fallback the
 * reach spec names, so a custom tool is default-denied until granted by its id.
 *
 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-a-custom-tool-is-offered-under-a-two-segment-id-and-only-by-exact-grant-req-custool-002
 */
final class CustomToolDescriptors {

	/**
	 * The prefix of every custom tool id after the app id.
	 */
	public const PREFIX = 'custom_';

	/**
	 * The tool id of a slug.
	 *
	 * @param string $slug The custom tool's slug.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-a-custom-tool-is-offered-under-a-two-segment-id-and-only-by-exact-grant-req-custool-002
	 */
	public static function idOf(string $slug): string {
		return AppIdentity::APP_ID . '.' . self::PREFIX . $slug;
	}//end idOf()

	/**
	 * Whether a tool id names a custom tool, and which slug.
	 *
	 * @param string $toolId The tool id.
	 *
	 * @return string|null The slug, or null for any other id.
	 *
	 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-a-custom-tool-is-offered-under-a-two-segment-id-and-only-by-exact-grant-req-custool-002
	 */
	public static function slugOf(string $toolId): ?string {
		$prefix = AppIdentity::APP_ID . '.' . self::PREFIX;
		if (str_starts_with($toolId, $prefix) === false) {
			return null;
		}

		$slug = substr($toolId, strlen($prefix));
		if (preg_match('/^[a-z0-9_]{3,40}$/', $slug) !== 1) {
			return null;
		}

		return $slug;
	}//end slugOf()

	/**
	 * The descriptor of a stored custom tool, or null when it is disabled or incomplete.
	 *
	 * @param array<string, mixed> $tool The stored custom tool.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-a-custom-tool-is-offered-under-a-two-segment-id-and-only-by-exact-grant-req-custool-002
	 */
	public static function describe(array $tool): ?array {
		$slug = (string)($tool['slug'] ?? '');
		if (($tool['enabled'] ?? true) === false || preg_match('/^[a-z0-9_]{3,40}$/', $slug) !== 1) {
			return null;
		}

		$changes = (string)($tool['changesData'] ?? 'yes');
		$schema  = ($tool['inputSchema'] ?? []);
		if (is_array($schema) === false) {
			$schema = [];
		}

		return [
			'id' => self::idOf(slug: $slug),
			'subject' => 'customTool',
			'action' => 'invoke',
			'reach' => ToolReachResolver::REACH_EXTERNAL,
			'name' => (string)($tool['name'] ?? $slug),
			'description' => (string)($tool['description'] ?? ''),
			'inputSchema' => [
				'type' => 'object',
				'properties' => ($schema['properties'] ?? []),
				'required' => ($schema['required'] ?? []),
			],
			'readOnlyHint' => $changes === 'no',
			'destructiveHint' => $changes === 'irreversible',
			'idempotentHint' => $changes === 'no',
			'scope' => self::scopeOf(changes: $changes),
		];
	}//end describe()

	/**
	 * The scope of what a tool changes; anything unknown is treated as a change.
	 *
	 * @param string $changes no, yes or irreversible.
	 *
	 * @return string
	 */
	private static function scopeOf(string $changes): string {
		if ($changes === 'no') {
			return 'read';
		}

		return 'update';
	}//end scopeOf()
}//end class
