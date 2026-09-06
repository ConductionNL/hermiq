<?php

/**
 * Test stub for OpenRegister's FlowRun entity.
 *
 * Stands in for OCA\OpenRegister\Db\FlowRun when OpenRegister is not
 * installed (standalone CI). The real class extends Entity, whose accessors
 * are magic, so this stub round-trips through __call the same way the Flow
 * stub does: a stub that discarded writes would hide exactly the
 * attribution-field bugs (organisation, runAs) it exists to let tests catch.
 *
 * @category Test
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal FlowRun stub with round-tripping magic accessors.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method string|null getRunAs()
 * @method void setRunAs(?string $runAs)
 * @method string|null getTriggeredBy()
 * @method void setTriggeredBy(?string $triggeredBy)
 */
class FlowRun {

	/**
	 * Values written through the magic setters.
	 *
	 * @var array<string, mixed>
	 */
	private array $magicValues = [];

	/**
	 * Serve the magic accessors the real Entity provides via __call.
	 *
	 * @param string $name The accessor name.
	 * @param array $arguments The accessor arguments.
	 *
	 * @return mixed The property value, or null for a setter.
	 */
	public function __call(string $name, array $arguments) {
		if (str_starts_with($name, 'set') === true) {
			$this->magicValues[lcfirst(substr($name, 3))] = ($arguments[0] ?? null);
			return null;
		}

		if (str_starts_with($name, 'get') === true) {
			return ($this->magicValues[lcfirst(substr($name, 3))] ?? null);
		}

		return null;
	}//end __call()
}//end class
