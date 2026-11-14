<?php

/**
 * An in-memory ObjectService for the eval template, schedule and evidence tests.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Support;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Stores objects per schema, answers filters by equality, and records every
 * save with its flags and every system elevation, in the order they happen.
 */
class InMemoryEvalObjects extends ObjectService {

	/**
	 * Objects by schema slug, then uuid.
	 *
	 * @var array<string, array<string, ObjectEntity>>
	 */
	public array $objects = [];

	/**
	 * Every save, in order: schema, uuid, payload, flags.
	 *
	 * @var list<array{schema: string, uuid: ?string, object: array<string, mixed>, rbac: bool, multitenancy: bool, owner: ?string, elevated: bool}>
	 */
	public array $saved = [];

	/**
	 * What happened, in order, for tests that check commit-before-run.
	 *
	 * @var list<string>
	 */
	public array $events = [];

	/**
	 * How many times a system identity was asked for.
	 *
	 * @var int
	 */
	public int $elevations = 0;

	/**
	 * The schema the next findAll() reads.
	 *
	 * @var string|null
	 */
	private ?string $schema = null;

	/**
	 * Whether a system identity is active.
	 *
	 * @var bool
	 */
	private bool $elevated = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
	}//end __construct()

	/**
	 * Store an object as if it had been saved before.
	 *
	 * @param string               $schema The schema slug.
	 * @param string               $uuid   The uuid.
	 * @param array<string, mixed> $data   The payload.
	 * @param string|null          $owner  The object's owner.
	 *
	 * @return ObjectEntity
	 */
	public function put(string $schema, string $uuid, array $data, ?string $owner = null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		if ($owner !== null) {
			$entity->setOwner($owner);
		}

		$this->objects[$schema][$uuid] = $entity;
		return $entity;
	}//end put()

	/**
	 * Run the operation as the system identity, recording it.
	 *
	 * @param callable $operation The work.
	 *
	 * @return mixed
	 */
	public function runAsSystem(callable $operation): mixed {
		$this->elevations++;
		$this->elevated = true;
		try {
			return $operation();
		} finally {
			$this->elevated = false;
		}
	}//end runAsSystem()

	/**
	 * Remember nothing: every read names its schema.
	 *
	 * @param mixed $register The register.
	 *
	 * @return static
	 */
	public function setRegister(mixed $register): static {
		return $this;
	}//end setRegister()

	/**
	 * The schema the next findAll() reads.
	 *
	 * @param mixed $schema The schema.
	 *
	 * @return static
	 */
	public function setSchema(mixed $schema): static {
		$this->schema = (string)$schema;
		return $this;
	}//end setSchema()

	/**
	 * The objects of the schema whose fields equal every filter.
	 *
	 * @param array<string, mixed> $config        The query.
	 * @param bool                 $_rbac         Unused.
	 * @param bool                 $_multitenancy Unused.
	 *
	 * @return list<ObjectEntity>
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$filters = ($config['filters'] ?? []);
		$rows = [];
		foreach (($this->objects[(string)$this->schema] ?? []) as $entity) {
			$data = $entity->getObject();
			foreach ($filters as $key => $value) {
				if (($data[$key] ?? null) !== $value) {
					continue 2;
				}
			}

			$rows[] = $entity;
		}

		return $rows;
	}//end findAll()

	/**
	 * One object by uuid within the schema, or null.
	 *
	 * @param int|string $id            The uuid.
	 * @param array|null $_extend       Unused.
	 * @param bool       $files         Unused.
	 * @param mixed      $register      Unused.
	 * @param mixed      $schema        The schema slug.
	 * @param bool       $_rbac         Unused.
	 * @param bool       $_multitenancy Unused.
	 * @param bool       $_render       Unused.
	 * @param bool       $_audit        Unused.
	 *
	 * @return ObjectEntity|null
	 */
	public function find(
		int|string $id,
		?array $_extend = [],
		bool $files = false,
		mixed $register = null,
		mixed $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_render = true,
		bool $_audit = true,
	): ?ObjectEntity {
		return ($this->objects[(string)$schema][(string)$id] ?? null);
	}//end find()

	/**
	 * Store the object, recording the call.
	 *
	 * @param array|ObjectEntity $object          The payload.
	 * @param array|null         $extend          Unused.
	 * @param mixed              $register        Unused.
	 * @param mixed              $schema          The schema slug.
	 * @param string|null        $uuid            The uuid to update, or null to create.
	 * @param bool               $_rbac           Recorded.
	 * @param bool               $_multitenancy   Recorded.
	 * @param bool               $silent          Unused.
	 * @param bool               $_validation     Unused.
	 * @param array|null         $uploadedFiles   Unused.
	 * @param \OCP\IUser|null    $currentUser     Unused.
	 * @param bool               $failIfExists    Unused.
	 * @param bool               $_unowned        Unused.
	 * @param bool               $_dedupOverride  Unused.
	 * @param string|null        $_owner          Recorded and stamped as owner.
	 *
	 * @return ObjectEntity
	 */
	public function saveObject(
		array|ObjectEntity $object,
		?array $extend = [],
		mixed $register = null,
		mixed $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $silent = false,
		bool $_validation = true,
		?array $uploadedFiles = null,
		?\OCP\IUser $currentUser = null,
		bool $failIfExists = false,
		bool $_unowned = false,
		bool $_dedupOverride = false,
		?string $_owner = null,
	): ObjectEntity {
		$payload = is_array($object) ? $object : $object->getObject();
		$this->saved[] = [
			'schema' => (string)$schema,
			'uuid' => $uuid,
			'object' => $payload,
			'rbac' => $_rbac,
			'multitenancy' => $_multitenancy,
			'owner' => $_owner,
			'elevated' => $this->elevated,
		];
		$this->events[] = 'save:' . (string)$schema;

		$id = $uuid ?? ('new-' . count($this->saved));
		$owner = $_owner ?? ($this->objects[(string)$schema][$id] ?? null)?->getOwner();

		return $this->put(schema: (string)$schema, uuid: $id, data: $payload, owner: $owner);
	}//end saveObject()
}//end class
