<?php

/**
 * Test stub for OpenRegister's FlowVersion entity.
 *
 * Stands in for OCA\OpenRegister\Db\FlowVersion when OpenRegister is not
 * installed (standalone CI). hermiq only touches the lifecycle constants
 * (`ScheduleFlowBridge` reads a flow's `lifecycleStatus` against them) and
 * receives instances back from `FlowVersionService::publish()`/`createDraft()`,
 * so the stub carries the real constants plus the same magic-accessor shape as
 * the Flow stub. The real entity is an OCP Entity whose accessors are served by
 * `Entity::__call`; declaring them concretely here would make the stub stricter
 * than the original.
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
 * Minimal FlowVersion stub for standalone static analysis.
 *
 * @method string|null getFlowUuid()
 * @method void setFlowUuid(?string $flowUuid)
 * @method integer|null getVersion()
 * @method void setVersion(?int $version)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 */
class FlowVersion {

	/**
	 * Lifecycle state of an editable head, matching the real entity.
	 *
	 * @var string
	 */
	public const STATUS_DRAFT = 'draft';

	/**
	 * Lifecycle state of the version the engine pins runs to.
	 *
	 * @var string
	 */
	public const STATUS_PUBLISHED = 'published';

	/**
	 * Lifecycle state of a version that backs no new runs.
	 *
	 * @var string
	 */
	public const STATUS_DEPRECATED = 'deprecated';

	/**
	 * Values written through the magic setters.
	 *
	 * @var array<string, mixed>
	 */
	private array $magicValues = [];

	/**
	 * Serve the magic accessors the real Entity provides via __call.
	 *
	 * Round-trips every write, the same faithfulness rule as the Flow stub: a
	 * stub that discards writes hides exactly the class of bug these tests
	 * exist to catch.
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
