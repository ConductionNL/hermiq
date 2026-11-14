<?php

/**
 * Unit tests for MemoryLookup: the search config handed to the register.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\MemoryLookup;
use PHPUnit\Framework\TestCase;

/**
 * The filters, limit and optional search of a memory query.
 *
 * @covers \OCA\Hermiq\Service\MemoryLookup
 */
class MemoryLookupTest extends TestCase {

	/**
	 * Without a search the config holds the filters and the default limit of 100.
	 *
	 * @return void
	 */
	public function testWithoutASearchOnlyFiltersAndLimit(): void {
		self::assertSame(
			['filters' => ['agent' => 'a-1'], 'limit' => 100],
			(new MemoryLookup(filters: ['agent' => 'a-1']))->config()
		);

	}//end testWithoutASearchOnlyFiltersAndLimit()

	/**
	 * A search term is added; an empty one is left out.
	 *
	 * @return void
	 */
	public function testASearchIsAddedOnlyWhenNotEmpty(): void {
		$lookup = new MemoryLookup(filters: [], limit: 5);
		$lookup->search = 'invoice';
		self::assertSame(['filters' => [], 'limit' => 5, 'search' => 'invoice'], $lookup->config());

		$lookup->search = '';
		self::assertArrayNotHasKey('search', $lookup->config());

	}//end testASearchIsAddedOnlyWhenNotEmpty()
}//end class
