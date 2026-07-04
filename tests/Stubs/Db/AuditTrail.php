<?php

/**
 * Test stub for OpenRegister AuditTrail.
 *
 * Stands in for OCA\OpenRegister\Db\AuditTrail when OpenRegister is not installed
 * (standalone CI: php:8.3-cli + OCP stubs). Exposes only the accessors Hermiq's
 * RunHistoryService reads: uuid, action, user, changed (context), created. The
 * real entity ships with OpenRegister at runtime.
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
 * Minimal AuditTrail stub for standalone unit runs.
 */
class AuditTrail
{

    /**
     * The audit entry UUID.
     *
     * @var string|null
     */
    private ?string $uuid = null;

    /**
     * The audit action (e.g. 'run', 'create', 'update').
     *
     * @var string|null
     */
    private ?string $action = null;

    /**
     * The acting user UID.
     *
     * @var string|null
     */
    private ?string $user = null;

    /**
     * The changed/context payload.
     *
     * @var array<string,mixed>|null
     */
    private ?array $changed = null;

    /**
     * The creation timestamp.
     *
     * @var DateTime|null
     */
    private ?DateTime $created = null;

    /**
     * Get the UUID.
     *
     * @return string|null
     */
    public function getUuid(): ?string
    {
        return $this->uuid;
    }//end getUuid()

    /**
     * Set the UUID.
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
     * Get the action.
     *
     * @return string|null
     */
    public function getAction(): ?string
    {
        return $this->action;
    }//end getAction()

    /**
     * Set the action.
     *
     * @param string|null $action The action.
     *
     * @return void
     */
    public function setAction(?string $action): void
    {
        $this->action = $action;
    }//end setAction()

    /**
     * Get the acting user UID.
     *
     * @return string|null
     */
    public function getUser(): ?string
    {
        return $this->user;
    }//end getUser()

    /**
     * Set the acting user UID.
     *
     * @param string|null $user The user UID.
     *
     * @return void
     */
    public function setUser(?string $user): void
    {
        $this->user = $user;
    }//end setUser()

    /**
     * Get the changed/context payload.
     *
     * @return array<string,mixed>|null
     */
    public function getChanged(): ?array
    {
        return $this->changed;
    }//end getChanged()

    /**
     * Set the changed/context payload.
     *
     * @param array<string,mixed>|null $changed The context payload.
     *
     * @return void
     */
    public function setChanged(?array $changed): void
    {
        $this->changed = $changed;
    }//end setChanged()

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
     * @param DateTime|null $created The timestamp.
     *
     * @return void
     */
    public function setCreated(?DateTime $created): void
    {
        $this->created = $created;
    }//end setCreated()
}//end class
