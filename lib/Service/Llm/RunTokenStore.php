<?php

/**
 * Hermiq RunTokenStore.
 *
 * Where a run-token record lives between the process that mints it and the
 * process that verifies it. Those are routinely DIFFERENT processes: a token
 * minted by a cron-mode background job (conversation titles, stages) is
 * verified by the web server answering the egress proxy. The store therefore
 * has one hard requirement, and it is the reason this seam exists: every PHP
 * process of the instance must read what any other one wrote.
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
 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

/**
 * Persistence for run-token records, shared by every process of the instance.
 *
 * Records are keyed by the token's SHA-256 digest, never the token itself.
 *
 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
 */
interface RunTokenStore {

	/**
	 * Store a freshly minted record.
	 *
	 * @param string $digest      The token's SHA-256 hex digest (the key).
	 * @param string $record      The JSON binding record.
	 * @param int    $expiresAt   Unix time after which the token no longer authorises.
	 * @param int    $retainUntil Unix time after which the record may be deleted.
	 *                            Later than `$expiresAt`, so a spent token is still
	 *                            RECOGNISED for a while after it stops working.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
	 */
	public function put(string $digest, string $record, int $expiresAt, int $retainUntil): void;

	/**
	 * Read a record.
	 *
	 * @param string $digest The token's SHA-256 hex digest.
	 *
	 * @return array{record: string, expiresAt: int, consumed: bool}|null The record, or null when none is stored.
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
	 */
	public function find(string $digest): ?array;

	/**
	 * Mark a record consumed. It keeps being recognised until its retention ends.
	 *
	 * @param string $digest The token's SHA-256 hex digest.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
	 */
	public function markConsumed(string $digest): void;

	/**
	 * Delete every record whose retention has ended.
	 *
	 * @param int $now The current Unix time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
	 */
	public function purge(int $now): void;
}//end interface
