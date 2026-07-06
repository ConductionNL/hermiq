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
class ObjectEntity
{

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
     * Get the numeric object id.
     *
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }//end getId()

    /**
     * Set the numeric object id.
     *
     * @param int|null $id The object id.
     *
     * @return void
     */
    public function setId(?int $id): void
    {
        $this->id = $id;
    }//end setId()

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

    /**
     * Get the creation timestamp.
     *
     * @return DateTime|null
     */
    public function getCreated(): ?DateTime
    {
        return $this->created;
    }//end getCreated()

    /**
     * Set the creation timestamp.
     *
     * @param DateTime|null $created The creation timestamp.
     *
     * @return void
     */
    public function setCreated(?DateTime $created): void
    {
        $this->created = $created;
    }//end setCreated()

    /**
     * Get the last-updated timestamp.
     *
     * @return DateTime|null
     */
    public function getUpdated(): ?DateTime
    {
        return $this->updated;
    }//end getUpdated()

    /**
     * Set the last-updated timestamp.
     *
     * @param DateTime|null $updated The last-updated timestamp.
     *
     * @return void
     */
    public function setUpdated(?DateTime $updated): void
    {
        $this->updated = $updated;
    }//end setUpdated()
}//end class
