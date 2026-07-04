<?php

/**
 * Test stub for OpenRegister ConversationMapper.
 *
 * Stands in for OCA\OpenRegister\Db\ConversationMapper when OpenRegister is not
 * installed (standalone CI). Exposes only insert, used by ScheduleService to open
 * an agent-bound conversation. The real mapper ships with OpenRegister.
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
 * Minimal ConversationMapper stub for standalone unit runs.
 */
class ConversationMapper
{

    /**
     * Insert a conversation.
     *
     * @param Conversation $entity The conversation entity.
     *
     * @return Conversation
     */
    public function insert(Conversation $entity): Conversation
    {
        return $entity;
    }//end insert()
}//end class
