<?php

/**
 * Hermiq column migration run.
 *
 * The progress of one RenameDutchColumns run: the shard table being migrated, its
 * columns, and how many columns were renamed, back-filled and refused so far. Built
 * by RenameDutchColumns and never handed out of it.
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

/**
 * One column migration run.
 *
 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
 *  migration. Pointing this at an existing spec would report conformance to a
 *  requirement that says nothing about it.
 */
final class ColumnMigrationRun {

	/**
	 * The shard table being migrated.
	 *
	 * @var string
	 */
	public string $table = '';

	/**
	 * The column names present in that table.
	 *
	 * @var array<int, string>
	 */
	public array $columns = [];

	/**
	 * Columns renamed so far.
	 *
	 * @var int
	 */
	public int $renamed = 0;

	/**
	 * Columns back-filled so far.
	 *
	 * @var int
	 */
	public int $copied = 0;

	/**
	 * Columns refused so far (two sources, one destination).
	 *
	 * @var int
	 */
	public int $refused = 0;

	/**
	 * Whether the table being migrated has the column.
	 *
	 * @param string $column The column name.
	 *
	 * @return bool
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function has(string $column): bool {
		return in_array($column, $this->columns, true);
	}//end has()
}//end class
