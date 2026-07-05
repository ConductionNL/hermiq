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
class Organisation
{

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
    public function getUuid(): ?string
    {
        return $this->uuid;
    }//end getUuid()

    /**
     * Get the organisation display name.
     *
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }//end getName()

    /**
     * Get the owning user id.
     *
     * @return string|null
     */
    public function getOwner(): ?string
    {
        return $this->owner;
    }//end getOwner()
}//end class
