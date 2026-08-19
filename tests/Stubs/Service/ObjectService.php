<?php

/**
 * Test stub for OpenRegister ObjectService.
 *
 * Stands in for OCA\OpenRegister\Service\ObjectService when OpenRegister is not
 * installed (standalone CI: php:8.3-cli + OCP stubs). Mirrors only the method
 * surface Hermiq's ScheduleService consumes: setRegister/setSchema context
 * chaining, findAll, saveObject, deleteObject. The real class ships with
 * OpenRegister at runtime.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Minimal ObjectService stub for standalone unit runs.
 */
class ObjectService {

	/**
	 * Set the active register context.
	 *
	 * @param mixed $register Register slug/id/entity.
	 *
	 * @return static
	 */
	public function setRegister(mixed $register): static {
		return $this;
	}//end setRegister()

	/**
	 * Set the active schema context.
	 *
	 * @param mixed $schema Schema slug/id/entity.
	 *
	 * @return static
	 */
	public function setSchema(mixed $schema): static {
		return $this;
	}//end setSchema()

	/**
	 * Find all objects for the active register/schema.
	 *
	 * @param array $config Query config (filters/limit/offset/...).
	 * @param bool $_rbac Whether RBAC applies.
	 * @param bool $_multitenancy Whether multi-tenancy applies.
	 *
	 * @return array<int, mixed> Rendered objects (element type is loose, matching the real service).
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		return [];
	}//end findAll()

	/**
	 * Find a single object by id/uuid.
	 *
	 * @param int|string $id The object id or uuid.
	 * @param array|null $_extend Extend config.
	 * @param bool $files Whether to include files.
	 * @param mixed $register Register context.
	 * @param mixed $schema Schema context.
	 * @param bool $_rbac Whether RBAC applies.
	 * @param bool $_multitenancy Whether multi-tenancy applies.
	 * @param bool $_render Whether to render the object (mirrors the real service).
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
	): ?ObjectEntity {
		return null;
	}//end find()

	/**
	 * Persist an object.
	 *
	 * @param array|ObjectEntity $object The object payload or entity.
	 * @param array|null $extend Extend config.
	 * @param mixed $register Register context.
	 * @param mixed $schema Schema context.
	 * @param string|null $uuid Target UUID.
	 * @param bool $_rbac Whether RBAC applies.
	 * @param bool $_multitenancy Whether multi-tenancy applies.
	 * @param bool $silent Whether to skip event dispatch (mirrors the real service).
	 * @param bool $_validation Whether schema validation applies (mirrors the real service).
	 * @param array|null $uploadedFiles Uploaded files to attach (mirrors the real service).
	 * @param \OCP\IUser|null $currentUser Acting user override (mirrors the real service).
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
		// Position matters: the real service declares `$_validation` BETWEEN
		// `$silent` and `$uploadedFiles`. A double that drifts from the real
		// signature is a FATAL, not a failed assertion — PHP refuses to declare
		// any subclass and the whole suite dies at load time, before it runs.
		bool $_validation = true,
		?array $uploadedFiles = null,
		?\OCP\IUser $currentUser = null,
		// openregister#2211 (insert-only saves) added this. A double that
		// drifts from the real signature is a FATAL, not a failed
		// assertion: PHP refuses to declare the class and the whole
		// suite dies before it runs.
		bool $failIfExists = false,
		// openregister added this (stamp the SYSTEM identity as owner even when a
		// user session exists). This stub is what CI loads — the real class is
		// absent there — so a drift here is invisible in CI and only fatals when
		// the suite runs inside a booted Nextcloud, where the real class wins.
		bool $_unowned = false,
	): ObjectEntity {
		return new ObjectEntity();
	}//end saveObject()

	/**
	 * Unified paginated/faceted search (`_search` full-text term, `_limit`, ...).
	 *
	 * @param array $query The search query array (`_search`, `_limit`,
	 *                     `_register`, `_schema`, ...).
	 * @param bool $_rbac Whether RBAC applies.
	 * @param bool $_multitenancy Whether multi-tenancy applies.
	 * @param bool $deleted Whether to include deleted objects.
	 * @param array|null $ids Optional array of object IDs to filter by.
	 * @param string|null $uses Optional uses parameter for filtering.
	 * @param array|null $views Optional array of view IDs to apply filters from.
	 *
	 * @return array<string, mixed> Paginated result shape (results, total, ...).
	 */
	public function searchObjectsPaginated(
		array $query = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $deleted = false,
		?array $ids = null,
		?string $uses = null,
		?array $views = null,
	): array {
		return [
			'results' => [],
			'total' => 0,
		];
	}//end searchObjectsPaginated()

	/**
	 * Get the audit log entries for an object.
	 *
	 * @param string $uuid The object UUID.
	 * @param array $filters Optional audit filters (e.g. ['action' => 'run']).
	 * @param bool $_rbac Whether RBAC applies.
	 * @param bool $_multitenancy Whether multi-tenancy applies.
	 *
	 * @return array<int, mixed> AuditTrail entries (loose element type, matching the real service).
	 */
	public function getLogs(string $uuid, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
		return [];
	}//end getLogs()

	/**
	 * Delete an object by UUID.
	 *
	 * @param string $uuid The object UUID.
	 * @param mixed $register Register context.
	 * @param mixed $schema Schema context.
	 * @param bool $_rbac Whether RBAC applies.
	 * @param bool $_multitenancy Whether multi-tenancy applies.
	 *
	 * @return bool
	 */
	public function deleteObject(
		string $uuid,
		mixed $register = null,
		mixed $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): bool {
		return true;
	}//end deleteObject()
}//end class
