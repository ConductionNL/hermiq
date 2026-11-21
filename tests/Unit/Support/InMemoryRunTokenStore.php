<?php

/**
 * An in-memory RunTokenStore for unit tests.
 *
 * One instance stands for the instance DATABASE: hand the same object to two
 * RunTokenService instances and they behave like two PHP process pools sharing
 * one database, which is exactly the property the production store has and the
 * old APCu-backed cache did not.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Support;

use OCA\Hermiq\Service\Llm\RunTokenStore;

/**
 * Array-backed RunTokenStore.
 */
final class InMemoryRunTokenStore implements RunTokenStore {

	/**
	 * Rows keyed by digest.
	 *
	 * @var array<string, array{record: string, expiresAt: int, retainUntil: int, consumed: bool}>
	 */
	public array $rows = [];

	/**
	 * {@inheritDoc}
	 */
	public function put(string $digest, string $record, int $expiresAt, int $retainUntil): void {
		$this->rows[$digest] = [
			'record' => $record,
			'expiresAt' => $expiresAt,
			'retainUntil' => $retainUntil,
			'consumed' => false,
		];
	}//end put()

	/**
	 * {@inheritDoc}
	 */
	public function find(string $digest): ?array {
		if (isset($this->rows[$digest]) === false) {
			return null;
		}

		$row = $this->rows[$digest];
		return ['record' => $row['record'], 'expiresAt' => $row['expiresAt'], 'consumed' => $row['consumed']];
	}//end find()

	/**
	 * {@inheritDoc}
	 */
	public function markConsumed(string $digest): void {
		if (isset($this->rows[$digest]) === true) {
			$this->rows[$digest]['consumed'] = true;
		}
	}//end markConsumed()

	/**
	 * {@inheritDoc}
	 */
	public function purge(int $now): void {
		foreach ($this->rows as $digest => $row) {
			if ($row['retainUntil'] < $now) {
				unset($this->rows[$digest]);
			}
		}
	}//end purge()
}//end class
