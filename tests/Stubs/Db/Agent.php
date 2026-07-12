<?php

/**
 * Test stub for OpenRegister Agent entity.
 *
 * Stands in for OCA\OpenRegister\Db\Agent when OpenRegister is not installed
 * (standalone CI). Exposes getId (used by ScheduleService to bind a conversation
 * to the resolved agent) plus getUuid/getOwner (used by FlowAgentRunService to
 * resolve the agent reference and its acting-user impersonation target) and
 * getOrganisation (used by WebhookAgentRunService for GATE 1 kill-switch
 * scoping — a webhook trigger has no "triggering object" to read an
 * organisation from, unlike a flow-triggered run, so it reads the Agent's own
 * organisation instead). The real entity ships with OpenRegister.
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
     * Agent UUID.
     *
     * @var string|null
     */
    private ?string $uuid = null;

    /**
     * Owner user id (the agent's acting user).
     *
     * @var string|null
     */
    private ?string $owner = null;

    /**
     * Organisation identifier (kill-switch scoping for a webhook-triggered run).
     *
     * @var string|null
     */
    private ?string $organisation = null;

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

    /**
     * Get the agent UUID.
     *
     * @return string|null
     */
    public function getUuid(): ?string
    {
        return $this->uuid;
    }//end getUuid()

    /**
     * Set the agent UUID.
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
     * Get the owner user id.
     *
     * @return string|null
     */
    public function getOwner(): ?string
    {
        return $this->owner;
    }//end getOwner()

    /**
     * Set the owner user id.
     *
     * @param string|null $owner The owner user id.
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
}//end class
