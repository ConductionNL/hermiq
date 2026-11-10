<?php

/**
 * Test stub for OpenRegister SearchOptions.
 *
 * Stands in for OCA\OpenRegister\Kernel\Contracts\SearchOptions when OpenRegister is not
 * installed (standalone CI), with the real constructor.
 *
 * @category Test
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The optional parts of an object search: the object selection, the views that
 * scope it and the deleted flag a paginated search reports back.
 */
final class SearchOptions {

	/**
	 * Constructor.
	 *
	 * @param ObjectSelection $selection The ids and uses filter.
	 * @param array<int, string>|null $views The view ids applied to the query, or null for none.
	 * @param bool $viewScopeRequired Whether the views are the caller's only bound.
	 * @param bool $deleted The deleted flag a paginated search echoes.
	 */
	public function __construct(
		public readonly ObjectSelection $selection = new ObjectSelection(),
		public readonly ?array $views = null,
		public readonly bool $viewScopeRequired = false,
		public readonly bool $deleted = false,
	) {
	}//end __construct()
}//end class
