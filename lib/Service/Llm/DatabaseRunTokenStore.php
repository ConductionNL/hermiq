<?php

/**
 * Hermiq DatabaseRunTokenStore.
 *
 * The run-token store on the Nextcloud database: the one store every PHP
 * process of an instance is guaranteed to share.
 *
 * Direct `IDBConnection` use in an OR-backed app is an ADR-070 exception, and
 * this is it (hermiq ADR-025). A run token is credential material that lives
 * for minutes and is read on every egress CONNECT; it has no business being an
 * OpenRegister object. It used to live in `ICacheFactory::createDistributed()`,
 * which is only shared when the admin configured a real distributed cache. With
 * none configured Nextcloud falls back to APCu, which is PER PROCESS POOL: a
 * token minted by a cron-mode background job lived in the CLI's APCu, and the
 * web server answering the egress proxy never saw it. Every CONNECT of that run
 * came back 401, and each 401 fed the brute-force throttle until the whole
 * egress path answered 429 (measured on a live instance 2026-09-29).
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

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Run-token records in the `hermiq_run_tokens` table.
 *
 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
 */
class DatabaseRunTokenStore implements RunTokenStore {

	/**
	 * The table, created by Version217Date20260929200000.
	 *
	 * @var string
	 */
	public const TABLE = 'hermiq_run_tokens';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The instance database.
	 */
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Store a freshly minted record.
	 *
	 * @param string $digest      The token's SHA-256 hex digest.
	 * @param string $record      The JSON binding record.
	 * @param int    $expiresAt   Unix time the token stops authorising.
	 * @param int    $retainUntil Unix time the record may be deleted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
	 */
	public function put(string $digest, string $record, int $expiresAt, int $retainUntil): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)
			->values(
				[
					'token_hash' => $qb->createNamedParameter($digest),
					'record' => $qb->createNamedParameter($record),
					'expires_at' => $qb->createNamedParameter($expiresAt, IQueryBuilder::PARAM_INT),
					'retain_until' => $qb->createNamedParameter($retainUntil, IQueryBuilder::PARAM_INT),
					'consumed' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				]
			);
		$qb->executeStatement();
	}//end put()

	/**
	 * Read a record.
	 *
	 * @param string $digest The token's SHA-256 hex digest.
	 *
	 * @return array{record: string, expiresAt: int, consumed: bool}|null The record, or null.
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
	 */
	public function find(string $digest): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('record', 'expires_at', 'consumed')
			->from(self::TABLE)
			->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($digest)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if (is_array($row) === false) {
			return null;
		}

		return [
			'record' => (string)$row['record'],
			'expiresAt' => (int)$row['expires_at'],
			'consumed' => ((int)$row['consumed'] !== 0),
		];
	}//end find()

	/**
	 * Mark a record consumed.
	 *
	 * @param string $digest The token's SHA-256 hex digest.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
	 */
	public function markConsumed(string $digest): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('consumed', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($digest)));
		$qb->executeStatement();
	}//end markConsumed()

	/**
	 * Delete every record whose retention has ended.
	 *
	 * @param int $now The current Unix time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
	 */
	public function purge(int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->lt('retain_until', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}//end purge()
}//end class
