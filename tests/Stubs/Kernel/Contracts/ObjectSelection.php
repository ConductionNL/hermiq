<?php

/**
 * Test stub for OpenRegister ObjectSelection.
 *
 * Stands in for OCA\OpenRegister\Kernel\Contracts\ObjectSelection when OpenRegister is not
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
 * The object ids and the uses filter a search is restricted to.
 */
final class ObjectSelection {

	/**
	 * Constructor.
	 *
	 * @param array|null $ids The object ids, or null for no id filter.
	 * @param string|null $uses The uses filter, or null for none.
	 */
	public function __construct(
		public readonly ?array $ids = null,
		public readonly ?string $uses = null,
	) {
	}//end __construct()
}//end class
