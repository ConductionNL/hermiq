<?php

/**
 * Hermiq ToolHints.
 *
 * The MCP hint sets the browser and computer tool descriptors share: a read
 * that changes nothing, and an action on someone else's page or machine that
 * cannot be taken back. Spread into a descriptor so every tool of one kind
 * carries the same four hints.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/browser-use/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

/**
 * Shared MCP hint sets for tool descriptors.
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/browser-use/spec.md
 */
final class ToolHints {

	/**
	 * A read: changes nothing, safe to repeat.
	 *
	 * @var array<string, bool|string>
	 */
	public const READ = [
		'readOnlyHint' => true,
		'destructiveHint' => false,
		'idempotentHint' => true,
		'scope' => 'read',
	];

	/**
	 * An action that changes something and cannot be taken back.
	 *
	 * @var array<string, bool|string>
	 */
	public const DESTRUCTIVE_UPDATE = [
		'readOnlyHint' => false,
		'destructiveHint' => true,
		'idempotentHint' => false,
		'scope' => 'update',
	];
}
