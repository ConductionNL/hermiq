<?php

/**
 * Test stub for OpenRegister ObjectSaveOptions.
 *
 * Stands in for OCA\OpenRegister\Service\Object\ObjectSaveOptions when OpenRegister is not
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
 * Where and how an object is saved: target, uuid, extend, files, policy and actor.
 */
final class ObjectSaveOptions {

	/**
	 * Constructor.
	 *
	 * @param ObjectFindTarget $target        The register and schema to save into.
	 * @param string|null      $uuid          The object UUID.
	 * @param array|null       $extend        Relations to expand on the result.
	 * @param array|null       $uploadedFiles Files uploaded alongside the object.
	 * @param SavePolicy       $policy        Events, validation, insert-only and dedup override.
	 * @param SaveActor        $actor         The acting user and the owner.
	 */
	public function __construct(
		public readonly ObjectFindTarget $target = new ObjectFindTarget(),
		public readonly ?string $uuid = null,
		public readonly ?array $extend = [],
		public readonly ?array $uploadedFiles = null,
		public readonly SavePolicy $policy = new SavePolicy(),
		public readonly SaveActor $actor = new SaveActor(),
	) {
	}//end __construct()
}//end class
