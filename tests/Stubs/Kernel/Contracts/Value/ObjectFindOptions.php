<?php

/**
 * Test stub for OpenRegister ObjectFindOptions.
 *
 * Stands in for OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions when
 * OpenRegister is not installed (standalone CI), with the real constructor.
 *
 * @category Test
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * How one object is read: its register and schema, what to extend, and whether
 * to include files, render and audit the read.
 */
final class ObjectFindOptions {

	/**
	 * Constructor.
	 *
	 * @param mixed $register The register entity, id or slug.
	 * @param mixed $schema The schema entity, id or slug.
	 * @param array|null $extend Properties to extend.
	 * @param bool $files Whether to include file information.
	 * @param bool $render Whether to render the object.
	 * @param bool $audit Whether to write an audit entry for the read.
	 */
	public function __construct(
		public readonly mixed $register = null,
		public readonly mixed $schema = null,
		public readonly ?array $extend = [],
		public readonly bool $files = false,
		public readonly bool $render = true,
		public readonly bool $audit = true,
	) {
	}//end __construct()
}//end class
