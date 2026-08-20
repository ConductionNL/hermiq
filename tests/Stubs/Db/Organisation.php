<?php

/**
 * Test stub for OpenRegister Organisation.
 *
 * Stands in for OCA\OpenRegister\Db\Organisation when OpenRegister is not installed
 * (standalone CI). Exposes only the accessors the kill-switch surface reads
 * (uuid/name/owner) so the tenant model can be unit-tested. The real entity ships with
 * OpenRegister.
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
 * Minimal Organisation stub for standalone unit runs.
 */
class Organisation {

	/**
	 * The organisation UUID.
	 *
	 * @var string|null
	 */
	private ?string $uuid = null;

	/**
	 * The organisation display name.
	 *
	 * @var string|null
	 */
	private ?string $name = null;

	/**
	 * The owning user id.
	 *
	 * @var string|null
	 */
	private ?string $owner = null;

	/**
	 * Get the organisation UUID.
	 *
	 * @return string|null
	 */
	public function getUuid(): ?string {
		return $this->uuid;
	}//end getUuid()

	/**
	 * Get the organisation display name.
	 *
	 * @return string|null
	 */
	public function getName(): ?string {
		return $this->name;
	}//end getName()

	/**
	 * Get the owning user id.
	 *
	 * @return string|null
	 */
	public function getOwner(): ?string {
		return $this->owner;
	}//end getOwner()

	/**
	 * Set the organisation UUID (mirrors the real Entity's magic setter).
	 *
	 * @param string|null $uuid The UUID.
	 *
	 * @return void
	 */
	public function setUuid(?string $uuid): void {
		$this->uuid = $uuid;
	}//end setUuid()

	/**
	 * Set the organisation display name (mirrors the real Entity's magic setter).
	 *
	 * @param string|null $name The name.
	 *
	 * @return void
	 */
	public function setName(?string $name): void {
		$this->name = $name;
	}//end setName()

	/**
	 * Set the owning user id (mirrors the real Entity's magic setter).
	 *
	 * @param string|null $owner The owner uid.
	 *
	 * @return void
	 */
	public function setOwner(?string $owner): void {
		$this->owner = $owner;
	}//end setOwner()
}//end class
