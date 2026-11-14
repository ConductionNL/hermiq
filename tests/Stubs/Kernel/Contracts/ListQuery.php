<?php

/**
 * Test stub for OCA\OpenRegister\Kernel\Contracts\ListQuery.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Stubs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * A page of a listing; the fields hermiq uses of OpenRegister's ListQuery.
 */
final class ListQuery {
	/**
	 * Constructor.
	 *
	 * @param int      $limit  The page size.
	 * @param int|null $offset The number of rows to skip.
	 */
	public function __construct(
		public readonly int $limit=20,
		public readonly ?int $offset=null,
	) {
	}//end __construct()
}//end class
