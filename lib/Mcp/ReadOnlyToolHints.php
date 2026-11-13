<?php

/**
 * Hermiq ReadOnlyToolHints.
 *
 * The MCP hint set of a tool that only reads: it never writes
 * (`readOnlyHint`), so it cannot destroy anything (`destructiveHint`), calling
 * it twice answers the same (`idempotentHint`), and the grant it needs is the
 * `read` scope. The knowledge-graph and workspace read tools end their
 * descriptor with exactly these four keys, spread from here.
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
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-graph-traversal-is-exposed-as-governed-agent-tools
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

/**
 * The hint keys of a read-only tool descriptor.
 *
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-graph-traversal-is-exposed-as-governed-agent-tools
 */
final class ReadOnlyToolHints {

	/**
	 * The four hint keys, in descriptor order.
	 *
	 * @var array{readOnlyHint: true, destructiveHint: false, idempotentHint: true, scope: string}
	 */
	public const HINTS = [
		'readOnlyHint' => true,
		'destructiveHint' => false,
		'idempotentHint' => true,
		'scope' => 'read',
	];
}//end class
