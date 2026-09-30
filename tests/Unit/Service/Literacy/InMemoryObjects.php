<?php

/**
 * An in-memory ObjectService for the literacy tests (compliance-ai-literacy).
 *
 * Keeps objects per schema slug and answers findAll() with equality filters on
 * object data, the subset of OpenRegister's filter the literacy services use.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Literacy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Literacy;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * In-memory object store.
 */
final class InMemoryObjects extends ObjectService {

	/**
	 * Objects by schema slug.
	 *
	 * @var array<string, array<int, ObjectEntity>>
	 */
	public array $bySchema = [];

	/**
	 * The schema selected by setSchema().
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
	}//end __construct()

	/**
	 * Add an object directly.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $data The object data.
	 * @param string $organisation The organisation.
	 * @param string|null $owner The owner.
	 * @param DateTime|null $updated When it was last updated.
	 *
	 * @return ObjectEntity The stored object.
	 */
	public function put(string $schema, array $data, string $organisation = '', ?string $owner = null, ?DateTime $updated = null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($schema . '-' . (count($this->bySchema[$schema] ?? []) + 1));
		$entity->setObject($data);
		$entity->setOrganisation($organisation);
		$entity->setOwner($owner);
		$entity->setUpdated($updated ?? new DateTime());
		$this->bySchema[$schema][] = $entity;

		return $entity;
	}//end put()

	public function setRegister(mixed $register): static {
		return $this;
	}//end setRegister()

	public function setSchema(mixed $schema): static {
		$this->schema = (string)$schema;
		return $this;
	}//end setSchema()

	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$filters = (array)($config['filters'] ?? []);
		$out = [];
		foreach ($this->bySchema[$this->schema] ?? [] as $entity) {
			$data = $entity->getObject();
			$match = true;
			foreach ($filters as $key => $value) {
				$actual = ($data[$key] ?? null);
				if ($key === '@self.organisation' || $key === 'organisation') {
					$actual = $entity->getOrganisation();
				}

				if ($actual !== $value) {
					$match = false;
				}
			}

			if ($match === true) {
				$out[] = $entity;
			}
		}

		return $out;
	}//end findAll()

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
	): ObjectEntity {
		$data = is_array($object) === true ? $object : $object->getObject();
		$self = (array)($data['@self'] ?? []);
		unset($data['@self']);
		$schemaSlug = (string)($schema ?? $this->schema);

		if ($uuid !== null) {
			foreach ($this->bySchema[$schemaSlug] ?? [] as $entity) {
				if ($entity->getUuid() === $uuid) {
					$entity->setObject($data);
					return $entity;
				}
			}
		}

		return $this->put(
			schema: $schemaSlug,
			data: $data,
			organisation: (string)($self['organisation'] ?? ''),
			owner: isset($self['owner']) === true ? (string)$self['owner'] : null
		);
	}//end saveObject()

}//end class
