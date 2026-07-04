<?php

/**
 * Test stub for OpenRegister Agent entity.
 *
 * Stands in for OCA\OpenRegister\Db\Agent when OpenRegister is not installed
 * (standalone CI). Exposes only getId, used by ScheduleService to bind a
 * conversation to the resolved agent. The real entity ships with OpenRegister.
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
 * Minimal Agent stub for standalone unit runs.
 */
class Agent
{

    /**
     * Agent id.
     *
     * @var int|null
     */
    private ?int $id = null;

    /**
     * Get the agent id.
     *
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }//end getId()

    /**
     * Set the agent id.
     *
     * @param int|null $id The id.
     *
     * @return void
     */
    public function setId(?int $id): void
    {
        $this->id = $id;
    }//end setId()
}//end class
