<?php

/**
 * Test stub for OCA\Talk\Service\ParticipantService (spreed 24.0.1).
 *
 * Only getParticipant() is modelled — resolving the owner's Participant for
 * sendMessage(). Real signature verified against spreed 24.0.1
 * lib/Service/ParticipantService.php.
 *
 * @category Stub
 * @package  OCA\Talk\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Talk\Service;

use OCA\Talk\Participant;
use OCA\Talk\Room;

/**
 * Minimal stub of the spreed participant service.
 */
class ParticipantService
{
    /**
     * Resolve a participant for the given user in the room.
     *
     * @param Room        $room      The room.
     * @param string|null $userId    The user.
     * @param mixed       $sessionId Unused in the stub.
     *
     * @return Participant The participant.
     */
    public function getParticipant(Room $room, ?string $userId, $sessionId=null): Participant
    {
        return new Participant();

    }//end getParticipant()
}//end class
