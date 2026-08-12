<?php

/**
 * Test stub for OpenRegister ObjectEntity.
 *
 * Stands in for OCA\OpenRegister\Db\ObjectEntity when OpenRegister is not
 * installed (standalone CI). Exposes the accessors Hermiq's ScheduleService,
 * ApprovalService, and the agent-engine-port Engine/Llm classes read/write: id,
 * uuid, owner, organisation, created/updated timestamps, and the JSON object
 * payload. The real entity ships with OpenRegister at runtime.
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
 * Minimal ObjectEntity stub for standalone unit runs.
 */
class ObjectEntity {

	/**
	 * The numeric object id.
	 *
	 * @var int|null
	 */
	private ?int $id = null;

	/**
	 * The object UUID.
	 *
	 * @var string|null
	 */
	private ?string $uuid = null;

	/**
	 * The creation timestamp.
	 *
	 * @var DateTime|null
	 */
	private ?DateTime $created = null;

	/**
	 * The last-updated timestamp.
	 *
	 * @var DateTime|null
	 */
	private ?DateTime $updated = null;

	/**
	 * The owner UID.
	 *
	 * @var string|null
	 */
	private ?string $owner = null;

	/**
	 * The organisation identifier (tenant scope).
	 *
	 * @var string|null
	 */
	private ?string $organisation = null;

	/**
	 * The JSON object payload.
	 *
	 * @var array<string,mixed>
	 */
	private array $object = [];

	/**
	 * Soft-delete metadata; null while the object is live.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $deleted = null;

	/**
	 * Get the numeric object id.
	 *
	 * @return int|null
	 */
	public function getId(): ?int {
		return $this->id;
	}//end getId()

	/**
	 * Set the numeric object id.
	 *
	 * @param int|null $id The object id.
	 *
	 * @return void
	 */
	public function setId(?int $id): void {
		$this->id = $id;
	}//end setId()

	/**
	 * Get the object UUID.
	 *
	 * @return string|null
	 */
	public function getUuid(): ?string {
		return $this->uuid;
	}//end getUuid()

	/**
	 * Set the object UUID.
	 *
	 * @param string|null $uuid The UUID.
	 *
	 * @return void
	 */
	public function setUuid(?string $uuid): void {
		$this->uuid = $uuid;
	}//end setUuid()

	/**
	 * Get the owner UID.
	 *
	 * @return string|null
	 */
	public function getOwner(): ?string {
		return $this->owner;
	}//end getOwner()

	/**
	 * Set the owner UID.
	 *
	 * @param string|null $owner The owner UID.
	 *
	 * @return void
	 */
	public function setOwner(?string $owner): void {
		$this->owner = $owner;
	}//end setOwner()

	/**
	 * Get the organisation identifier.
	 *
	 * @return string|null
	 */
	public function getOrganisation(): ?string {
		return $this->organisation;
	}//end getOrganisation()

	/**
	 * Set the organisation identifier.
	 *
	 * @param string|null $organisation The organisation identifier.
	 *
	 * @return void
	 */
	public function setOrganisation(?string $organisation): void {
		$this->organisation = $organisation;
	}//end setOrganisation()

	/**
	 * Get the JSON object payload.
	 *
	 * @return array<string,mixed>
	 */
	public function getObject(): array {
		return $this->object;
	}//end getObject()

	/**
	 * Set the JSON object payload.
	 *
	 * @param array<string,mixed>|null $object The payload.
	 *
	 * @return void
	 */
	public function setObject(?array $object): void {
		$this->object = ($object ?? []);
	}//end setObject()

	/**
	 * Get the soft-delete marker.
	 *
	 * 🔴 Null means "live". OpenRegister's API delete is a SOFT delete: it
	 * writes this marker and dispatches an UPDATE, not ObjectDeletedEvent —
	 * only the hard `MagicMapper::delete()` dispatches that. A listener that
	 * treats an update as "still active" will therefore keep acting on objects
	 * the user has thrown away, so this accessor is what makes trashing
	 * observable at all.
	 *
	 * @return array<string,mixed>|null The delete metadata, or null when live.
	 */
	public function getDeleted(): ?array {
		return $this->deleted;
	}//end getDeleted()

	/**
	 * Set the soft-delete marker.
	 *
	 * @param array<string,mixed>|null $deleted The delete metadata.
	 *
	 * @return void
	 */
	public function setDeleted(?array $deleted): void {
		$this->deleted = $deleted;
	}//end setDeleted()

	/**
	 * Get the creation timestamp.
	 *
	 * @return DateTime|null
	 */
	public function getCreated(): ?DateTime {
		return $this->created;
	}//end getCreated()

	/**
	 * Set the creation timestamp.
	 *
	 * @param DateTime|null $created The creation timestamp.
	 *
	 * @return void
	 */
	public function setCreated(?DateTime $created): void {
		$this->created = $created;
	}//end setCreated()

	/**
	 * Get the last-updated timestamp.
	 *
	 * @return DateTime|null
	 */
	public function getUpdated(): ?DateTime {
		return $this->updated;
	}//end getUpdated()

	/**
	 * Set the last-updated timestamp.
	 *
	 * @param DateTime|null $updated The last-updated timestamp.
	 *
	 * @return void
	 */
	public function setUpdated(?DateTime $updated): void {
		$this->updated = $updated;
	}//end setUpdated()

	/**
	 * The register id the object lives in.
	 *
	 * @var string|int|null
	 */
	private string|int|null $register = null;

	/**
	 * The schema id the object lives in.
	 *
	 * @var string|int|null
	 */
	private string|int|null $schema = null;

	/**
	 * Get the register id.
	 *
	 * @return string|int|null
	 */
	public function getRegister(): string|int|null {
		return $this->register;
	}//end getRegister()

	/**
	 * Set the register id.
	 *
	 * @param string|int|null $register The register id.
	 *
	 * @return void
	 */
	public function setRegister(string|int|null $register): void {
		$this->register = $register;
	}//end setRegister()

	/**
	 * Get the schema id.
	 *
	 * @return string|int|null
	 */
	public function getSchema(): string|int|null {
		return $this->schema;
	}//end getSchema()

	/**
	 * Set the schema id.
	 *
	 * @param string|int|null $schema The schema id.
	 *
	 * @return void
	 */
	public function setSchema(string|int|null $schema): void {
		$this->schema = $schema;
	}//end setSchema()
}//end class
