<?php

/**
 * Test stub for OpenRegister SavePolicy.
 *
 * Stands in for OCA\OpenRegister\Service\Object\SavePolicy when OpenRegister is not
 * installed (standalone CI), with the real constructor.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * How a save behaves: events, validation, insert-only and dedup override.
 */
final class SavePolicy {

	/**
	 * Constructor.
	 *
	 * @param bool $silent        Suppress events for this save.
	 * @param bool $validation    Validate against the schema.
	 * @param bool $failIfExists  Fail instead of updating when the object already exists.
	 * @param bool $dedupOverride Save even when duplicate detection would stop it.
	 */
	public function __construct(
		public readonly bool $silent = false,
		public readonly bool $validation = true,
		public readonly bool $failIfExists = false,
		public readonly bool $dedupOverride = false,
	) {
	}//end __construct()
}//end class
