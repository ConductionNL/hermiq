<?php

/**
 * Test stub for OpenRegister's Flow entity.
 *
 * Stands in for OCA\OpenRegister\Db\Flow when OpenRegister is not installed
 * (standalone CI). The real entity is an OCP Entity: every accessor below is
 * MAGIC, served by Entity::__call and declared only as an @method tag. This stub
 * mirrors that exactly rather than declaring real methods, because a stub whose
 * methods are more concrete than the original passes locally and then fails
 * against the live class — the accessors must stay magic here too.
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

use DateTime;

/**
 * Minimal Flow stub for standalone static analysis.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getApp()
 * @method void setApp(?string $app)
 * @method boolean|null getEnabled()
 * @method void setEnabled(?bool $enabled)
 * @method string|null getTrigger()
 * @method void setTrigger(?string $trigger)
 * @method string|null getTriggerRegister()
 * @method void setTriggerRegister(?string $triggerRegister)
 * @method string|null getTriggerSchema()
 * @method void setTriggerSchema(?string $triggerSchema)
 * @method string|null getCron()
 * @method void setCron(?string $cron)
 * @method string|null getExecutionMode()
 * @method void setExecutionMode(?string $executionMode)
 * @method array|null getNodes()
 * @method void setNodes(?array $nodes)
 * @method array|null getEdges()
 * @method void setEdges(?array $edges)
 * @method array|null getLimits()
 * @method void setLimits(?array $limits)
 * @method integer|null getRetentionDays()
 * @method void setRetentionDays(?int $retentionDays)
 * @method boolean|null getAuditEnabled()
 * @method void setAuditEnabled(?bool $auditEnabled)
 * @method boolean|null getOversightEnabled()
 * @method void setOversightEnabled(?bool $oversightEnabled)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 */
class Flow {
	/**
	 * Values written through the magic setters.
	 *
	 * @var array<string, mixed>
	 */
	private array $magicValues = [];

	/**
	 * Serve the magic accessors the real Entity provides via __call.
	 *
	 * 🔴 This STORES what it is given, and the previous version did not — it
	 * returned null for everything, setter and getter alike.
	 *
	 * A stub that silently discards every write makes an entire class of test
	 * impossible to write: "the seed sets the organisation on the flow" could
	 * only ever read back null, so the assertion looks broken and the tempting
	 * conclusion is that the production code is fine. hermiq#140 is precisely a
	 * bug about a field not being set, and this stub would have hidden the fix
	 * as readily as the bug.
	 *
	 * Round-tripping is also what the real `Entity::__call` does, so this is a
	 * stub becoming more faithful, not a convenience.
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
