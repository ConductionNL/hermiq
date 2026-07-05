<?php

/**
 * Test stub for OpenRegister ObjectEntity.
 *
 * Stands in for OCA\OpenRegister\Db\ObjectEntity when OpenRegister is not
 * installed (standalone CI). Exposes only the accessors Hermiq's ScheduleService
 * and ApprovalService read/write: uuid, owner, organisation, and the JSON object
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

/**
 * Minimal ObjectEntity stub for standalone unit runs.
 */
class ObjectEntity
{

    /**
     * The object UUID.
     *
     * @var string|null
     */
    private ?string $uuid = null;

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
     * Get the object UUID.
     *
     * @return string|null
     */
    public function getUuid(): ?string
    {
        return $this->uuid;
    }//end getUuid()

    /**
     * Set the object UUID.
     *
     * @param string|null $uuid The UUID.
     *
     * @return void
     */
    public function setUuid(?string $uuid): void
    {
        $this->uuid = $uuid;
    }//end setUuid()

    /**
     * Get the owner UID.
     *
     * @return string|null
     */
    public function getOwner(): ?string
    {
        return $this->owner;
    }//end getOwner()

    /**
     * Set the owner UID.
     *
     * @param string|null $owner The owner UID.
     *
     * @return void
     */
    public function setOwner(?string $owner): void
    {
        $this->owner = $owner;
    }//end setOwner()

    /**
     * Get the organisation identifier.
     *
     * @return string|null
     */
    public function getOrganisation(): ?string
    {
        return $this->organisation;
    }//end getOrganisation()

    /**
     * Set the organisation identifier.
     *
     * @param string|null $organisation The organisation identifier.
     *
     * @return void
     */
    public function setOrganisation(?string $organisation): void
    {
        $this->organisation = $organisation;
    }//end setOrganisation()

    /**
     * Get the JSON object payload.
     *
     * @return array<string,mixed>
     */
    public function getObject(): array
    {
        return $this->object;
    }//end getObject()

    /**
     * Set the JSON object payload.
     *
     * @param array<string,mixed>|null $object The payload.
     *
     * @return void
     */
    public function setObject(?array $object): void
    {
        $this->object = ($object ?? []);
    }//end setObject()
}//end class
