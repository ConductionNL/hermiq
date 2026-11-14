<?php

/**
 * Hermiq RenameDutchColumns Repair Step
 *
 * Moves stored data from the Dutch columns to the English ones the shillinq
 * register now declares. Covers every vocabulary cluster migrated so far, not
 * only amounts — the class was renamed from RenameDutchAmountColumns when the
 * second cluster landed, because one register-scoped step must carry them all.
 *
 * WHY THIS IS NEEDED. OpenRegister does not store an object as a JSON blob
 * keyed by property name — each schema property is a real, snake_cased COLUMN
 * in the per-schema shard table `oc_openregister_table_{register}_{schema}`.
 * On schema sync MagicMapper ADDS a column when the snake_cased property name
 * is absent, and it NEVER renames: there is not a single `RENAME COLUMN` in
 * openregister. So renaming `bedrag` to `amount` in the register, on its own,
 * leaves the money in `bedrag` while every read looks at `amount` and finds
 * null. No error, no data loss, and invisible to the test suite, which asserts
 * against fixtures rather than migrated rows.
 *
 * For this app that is money: bedrag columns carry invoice, subsidy, payroll
 * and tax amounts.
 *
 * ALL FIFTY OWNERS MOVE TOGETHER. The map below covers every property name in
 * the cluster, and each was checked to be free of a collision with its English
 * target before being added. A register-scoped step cannot rename a column for
 * one owner and not the rest — the others would silently read null.
 *
 * SAFETY. Non-destructive and idempotent:
 *   - a column is renamed only when the OLD one exists and the NEW one does not;
 *   - where MagicMapper has already added an empty NEW column, the data is
 *     copied across and the old column is LEFT IN PLACE, so this is reversible
 *     and a re-run is a no-op;
 *   - two sources targeting one destination in a table are REFUSED, not merged;
 *   - nothing is deleted.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCP\DB\Exception;
use OCP\DB\IPreparedStatement;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Rename shillinq's Dutch amount columns to their English equivalents.
 *
 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
 */
class RenameDutchColumns implements IRepairStep {
	/**
	 * Slug prefix of the registers in scope.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG_PREFIX = 'hermiq';

	/**
	 * Old snake_case column name => new snake_case column name.
	 *
	 * Snake_case, not camelCase: MagicMapper stores `requestedAmount` as
	 * `requested_amount`, and a camelCase column is exactly what its
	 * de-duplication path then drops.
	 *
	 * @var array<string, string>
	 */
	private const COLUMN_MAP = [
		'impacttoetsen' => 'impact_assessments',
		'menselijke_tussenkomst' => 'human_intervention',
		'organisatie' => 'organisation',
		'referentie' => 'reference',
		'soort' => 'kind',
		'verantwoordelijke' => 'responsible',
		'wettelijke_grondslag' => 'statutory_basis',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function getName(): string {
		return 'Move hermiq data from the Dutch columns to the English ones';
	}//end getName()

	/**
	 * Run the column migration across every hermiq shard table.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function run(IOutput $output): void {
		$tables = $this->shardTables();
		if ($tables === []) {
			$output->info('RenameDutchColumns: no hermiq shard tables on this install; nothing to do.');
			return;
		}

		$run = new ColumnMigrationRun();
		foreach ($tables as $table) {
			$this->migrateTable(run: $run, table: $table);
		}

		$output->info(
			'RenameDutchColumns: ' . $run->renamed . ' renamed, ' . $run->copied . ' back-filled, '
			. $run->refused . ' refused, across ' . count($tables) . ' shard table(s).'
		);

	}//end run()

	/**
	 * Migrate every mapped column of one shard table.
	 *
	 * @param ColumnMigrationRun $run The run.
	 * @param string $table The shard table.
	 *
	 * @return void
	 */
	private function migrateTable(ColumnMigrationRun $run, string $table): void {
		$run->table = $table;
		$run->columns = $this->columnsOf(table: $table);

		foreach (array_keys(self::COLUMN_MAP) as $old) {
			$this->migrateColumn(run: $run, old: $old);
		}
	}//end migrateTable()

	/**
	 * Migrate one Dutch column of the run's table: refuse a collision, rename when the
	 * English column is absent, back-fill it when both exist.
	 *
	 * @param ColumnMigrationRun $run The run, on its current table.
	 * @param string $old The Dutch column name.
	 *
	 * @return void
	 */
	private function migrateColumn(ColumnMigrationRun $run, string $old): void {
		$new = self::COLUMN_MAP[$old];
		if ($run->has(column: $old) === false) {
			return;
		}

		if ($this->hasCollision(columns: $run->columns, target: $new) === true) {
			$this->refuseCollision(run: $run, old: $old);
			return;
		}

		if ($run->has(column: $new) === false) {
			$run->renamed += (int)$this->renameColumn(run: $run, old: $old);
			return;
		}

		$run->copied += (int)$this->backfillColumn(run: $run, old: $old);
	}//end migrateColumn()

	/**
	 * Log and count a Dutch column whose English destination another source also targets.
	 *
	 * @param ColumnMigrationRun $run The run, on its current table.
	 * @param string $old The Dutch column name.
	 *
	 * @return void
	 */
	private function refuseCollision(ColumnMigrationRun $run, string $old): void {
		$this->logger->warning(
			'RenameDutchColumns: two sources target one destination; migrating neither.',
			['table' => $run->table, 'source' => $old, 'destination' => self::COLUMN_MAP[$old]]
		);
		$run->refused++;
	}//end refuseCollision()

	/**
	 * Rename the Dutch column of the run's table to its English name.
	 *
	 * @param ColumnMigrationRun $run The run, on its current table.
	 * @param string $old The Dutch column name.
	 *
	 * @return bool Whether the statement succeeded.
	 */
	private function renameColumn(ColumnMigrationRun $run, string $old): bool {
		$sql = 'ALTER TABLE ' . $this->quote(identifier: $run->table) . ' RENAME COLUMN '
			. $this->quote(identifier: $old) . ' TO ' . $this->quote(identifier: self::COLUMN_MAP[$old]);
		return $this->exec(sql: $sql);
	}//end renameColumn()

	/**
	 * Copy the Dutch column into the English one where the English one is still empty.
	 *
	 * @param ColumnMigrationRun $run The run, on its current table.
	 * @param string $old The Dutch column name.
	 *
	 * @return bool Whether the statement succeeded.
	 */
	private function backfillColumn(ColumnMigrationRun $run, string $old): bool {
		$qNew = $this->quote(identifier: self::COLUMN_MAP[$old]);
		$qOld = $this->quote(identifier: $old);
		$sql = 'UPDATE ' . $this->quote(identifier: $run->table) . ' SET ' . $qNew . ' = ' . $qOld
			. ' WHERE ' . $qNew . ' IS NULL AND ' . $qOld . ' IS NOT NULL';
		return $this->exec(sql: $sql);
	}//end backfillColumn()

	/**
	 * Whether another mapped source already targets the same destination here.
	 *
	 * @param array<int, string> $columns Column names present in the table.
	 * @param string $target The destination column name.
	 *
	 * @return bool True when two sources compete for one destination.
	 */
	private function hasCollision(array $columns, string $target): bool {
		$sources = 0;
		foreach (self::COLUMN_MAP as $old => $new) {
			if ($new === $target && in_array($old, $columns, true) === true) {
				$sources++;
			}
		}

		return $sources > 1;
	}//end hasCollision()

	/**
	 * Resolve the shard tables of every register whose slug starts with the prefix.
	 *
	 * Table discovery goes through information_schema, NOT IDBConnection:
	 * OCP\IDBConnection exposes neither getSchema() nor getPrefix(), and calling
	 * either is a runtime fatal that `php -l` and phpcs both report as clean.
	 * Matching anchors on the `openregister_table_` MARKER rather than a computed
	 * prefix, because getTableName('') yields the literal `*PREFIX*` placeholder
	 * which a raw information_schema string never resolves.
	 *
	 * @return array<int, string>
	 */
	private function shardTables(): array {
		$ids = $this->registerIds();
		if ($ids === []) {
			return [];
		}

		$names = $this->openRegisterTableNames();
		if ($names === []) {
			return [];
		}

		$markers = array_map(
			static fn ($id): string => 'openregister_table_' . ((int)$id) . '_',
			$ids
		);

		return $this->shardsAmong(names: $names, markers: $markers);
	}//end shardTables()

	/**
	 * The table names that are a shard behind any of the markers, each once.
	 *
	 * @param array<int, string> $names Table names containing the shard marker.
	 * @param array<int, string> $markers The `openregister_table_<id>_` markers.
	 *
	 * @return array<int, string>
	 */
	private function shardsAmong(array $names, array $markers): array {
		$tables = [];
		foreach ($names as $name) {
			foreach ($markers as $marker) {
				if ($this->isShardOf(name: $name, marker: $marker) === true) {
					$tables[] = $name;
				}
			}
		}

		return array_values(array_unique($tables));
	}//end shardsAmong()

	/**
	 * Whether a table name is a shard behind the marker: the marker followed by digits only.
	 *
	 * @param string $name The table name.
	 * @param string $marker The `openregister_table_<id>_` marker.
	 *
	 * @return bool
	 */
	private function isShardOf(string $name, string $marker): bool {
		$offset = strpos($name, $marker);
		return $offset !== false && ctype_digit(substr($name, ($offset + strlen($marker)))) === true;
	}//end isShardOf()

	/**
	 * Ids of every register whose slug starts with the prefix.
	 *
	 * Split out of shardTables() only to keep that method under phpmd's
	 * cyclomatic-complexity limit; the behaviour is unchanged.
	 *
	 * @return array<int, mixed>
	 */
	private function registerIds(): array {
		try {
			return $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_registers` WHERE slug LIKE ?',
				[self::REGISTER_SLUG_PREFIX . '%']
			)->fetchAll(\PDO::FETCH_COLUMN);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchColumns: could not resolve the registers; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}
	}

	/**
	 * Every table name containing the openregister shard marker.
	 *
	 * @return array<int, string>
	 */
	private function openRegisterTableNames(): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'
			);
			$stmt->bindValue('pattern', '%openregister\_table\_%');
			$stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchColumns: could not list tables; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		return $this->namesIn(stmt: $stmt, field: 'table_name');
	}//end openRegisterTableNames()

	/**
	 * List the column names of a table.
	 *
	 * @param string $table Table name.
	 *
	 * @return array<int, string>
	 */
	private function columnsOf(string $table): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT column_name FROM information_schema.columns WHERE table_name = :table'
			);
			$stmt->bindValue('table', $table);
			$stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchColumns: could not read columns; skipping table.',
				['table' => $table, 'exception' => $e->getMessage()]
			);
			return [];
		}

		return $this->namesIn(stmt: $stmt, field: 'column_name');
	}//end columnsOf()

	/**
	 * The non-empty values of one field across every row an executed statement returns.
	 *
	 * @param IPreparedStatement $stmt The executed statement.
	 * @param string $field The field to read.
	 *
	 * @return array<int, string>
	 */
	private function namesIn(IPreparedStatement $stmt, string $field): array {
		$names = [];
		while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row[$field] ?? '');
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}//end namesIn()

	/**
	 * Execute one statement, logging and swallowing failure.
	 *
	 * @param string $sql The statement.
	 *
	 * @return bool Whether it succeeded.
	 */
	private function exec(string $sql): bool {
		try {
			$this->db->executeStatement($sql);
			return true;
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchColumns: statement failed; leaving the column as it was.',
				['sql' => $sql, 'exception' => $e->getMessage()]
			);
			return false;
		}

	}//end exec()

	/**
	 * Quote an identifier for the active platform.
	 *
	 * @param string $identifier Table or column name.
	 *
	 * @return string
	 */
	private function quote(string $identifier): string {
		return $this->db->getDatabasePlatform()->quoteSingleIdentifier($identifier);
	}//end quote()
}//end class
