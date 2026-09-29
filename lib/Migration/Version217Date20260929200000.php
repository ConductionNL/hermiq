<?php

/**
 * The run-token table.
 *
 * Run tokens moved out of the distributed cache because, on an instance with no
 * `memcache.distributed` configured, that cache is APCu and APCu is per process
 * pool: a token minted by a cron job was invisible to the web server that
 * verifies it. The database is the one store every process shares. See
 * DatabaseRunTokenStore and hermiq ADR-025.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Migration
 * @package  OCA\Hermiq\Migration
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

namespace OCA\Hermiq\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates hermiq_run_tokens.
 *
 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
 */
class Version217Date20260929200000 extends SimpleMigrationStep {

	/**
	 * The table this step creates.
	 *
	 * @var string
	 */
	private const TABLE = 'hermiq_run_tokens';

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                   $output        Migration output.
	 * @param Closure(): ISchemaWrapper $schemaClosure The schema.
	 * @param array<string, mixed>      $options       Migration options.
	 *
	 * @return ISchemaWrapper The schema, changed or not.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is fixed by SimpleMigrationStep.
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable(tableName: self::TABLE) === true) {
			return $schema;
		}

		$table = $schema->createTable(self::TABLE);
		// The SHA-256 hex digest of the token. Never the token itself.
		$table->addColumn('token_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
		// The binding: runId, agentId, userId, conversationId and the digest again.
		$table->addColumn('record', Types::TEXT, ['notnull' => true]);
		// Unix seconds. After this the token no longer authorises anything.
		$table->addColumn('expires_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		// Unix seconds. Until this the record is kept so a spent token is still
		// RECOGNISED, and a legitimate straggler is not counted as a brute-force
		// guess. After it the sweep deletes the row.
		$table->addColumn('retain_until', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('consumed', Types::SMALLINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);

		$table->setPrimaryKey(['token_hash']);
		$table->addIndex(['retain_until'], 'hermiq_runtok_retain');

		$output->info('Created ' . self::TABLE);

		return $schema;
	}//end changeSchema()
}//end class
