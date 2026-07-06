<?php

/**
 * Test stub for OpenRegister AgentRunRequestedEvent.
 *
 * Stands in for OCA\OpenRegister\Event\AgentRunRequestedEvent when OpenRegister
 * is not installed (standalone CI). Mirrors the real event's public constructor
 * signature and getPayload() flattening exactly — AgentRunRequestedListener and
 * FlowAgentRunServiceTest construct/consume this stub the same way they would the
 * real class. The real event ships with OpenRegister
 * (lib/Event/AgentRunRequestedEvent.php, change flow-agent-action).
 *
 * @category Test
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * Minimal AgentRunRequestedEvent stub for standalone unit runs.
 */
class AgentRunRequestedEvent extends Event
{

    /**
     * @var string
     */
    private string $correlationId;

    /**
     * Constructor — same parameter order as the real event.
     *
     * @param string      $subjectUuid      UUID of the triggering object.
     * @param string      $subjectRegister  Register slug/id of the triggering object.
     * @param string      $subjectSchema    Schema slug/id of the triggering object.
     * @param string      $agent            The configured agent reference (UUID in v1).
     * @param string|null $skill            Optional configured skill reference (slug).
     * @param string      $prompt           The fully-rendered prompt.
     * @param string      $resultField      The object field the run's output is written to.
     * @param bool        $requiresApproval Whether the run must pass a human-approval gate.
     * @param string      $mode             Dispatch mode — `"async"` only in v1.
     * @param string      $flowName         The owning flow's name.
     */
    public function __construct(
        private readonly string $subjectUuid,
        private readonly string $subjectRegister,
        private readonly string $subjectSchema,
        private readonly string $agent,
        private readonly ?string $skill,
        private readonly string $prompt,
        private readonly string $resultField,
        private readonly bool $requiresApproval,
        private readonly string $mode,
        private readonly string $flowName,
        ?string $correlationId=null
    ) {
        parent::__construct();
        $this->correlationId = ($correlationId ?? 'test-correlation-id');
    }//end __construct()

    /**
     * @return string
     */
    public function getSubjectUuid(): string
    {
        return $this->subjectUuid;
    }//end getSubjectUuid()

    /**
     * @return string
     */
    public function getSubjectRegister(): string
    {
        return $this->subjectRegister;
    }//end getSubjectRegister()

    /**
     * @return string
     */
    public function getSubjectSchema(): string
    {
        return $this->subjectSchema;
    }//end getSubjectSchema()

    /**
     * @return string
     */
    public function getAgent(): string
    {
        return $this->agent;
    }//end getAgent()

    /**
     * @return string|null
     */
    public function getSkill(): ?string
    {
        return $this->skill;
    }//end getSkill()

    /**
     * @return string
     */
    public function getPrompt(): string
    {
        return $this->prompt;
    }//end getPrompt()

    /**
     * @return string
     */
    public function getResultField(): string
    {
        return $this->resultField;
    }//end getResultField()

    /**
     * @return bool
     */
    public function isRequiresApproval(): bool
    {
        return $this->requiresApproval;
    }//end isRequiresApproval()

    /**
     * @return string
     */
    public function getMode(): string
    {
        return $this->mode;
    }//end getMode()

    /**
     * @return string
     */
    public function getFlowName(): string
    {
        return $this->flowName;
    }//end getFlowName()

    /**
     * @return string
     */
    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }//end getCorrelationId()

    /**
     * Flatten the event into a plain, JSON-serialisable payload — mirrors the
     * real event's getPayload() shape exactly.
     *
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return [
            'subjectUuid'      => $this->subjectUuid,
            'subjectRegister'  => $this->subjectRegister,
            'subjectSchema'    => $this->subjectSchema,
            'agent'            => $this->agent,
            'skill'            => $this->skill,
            'prompt'           => $this->prompt,
            'resultField'      => $this->resultField,
            'requiresApproval' => $this->requiresApproval,
            'mode'             => $this->mode,
            'flowName'         => $this->flowName,
            'correlationId'    => $this->correlationId,
        ];
    }//end getPayload()
}//end class
