<?php

/**
 * Test stub for OCA\Talk\Service\RoomService (spreed 24.0.1).
 *
 * Only createConversation() is modelled — creating the per-user default "Hermiq"
 * delivery room. Real signature verified against spreed lib/Service/RoomService.php
 * (int $type, string $name, ?IUser $owner = null, ...); only the leading arguments
 * Hermiq passes are declared here.
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

use OCA\Talk\Room;
use OCP\IUser;

/**
 * Minimal stub of the spreed room service.
 */
class RoomService
{
    /**
     * Create a conversation.
     *
     * @param int        $type  The room type (2 = group).
     * @param string     $name  The room display name.
     * @param IUser|null $owner The owning user.
     *
     * @return Room The created room.
     */
    public function createConversation(int $type, string $name, ?IUser $owner=null): Room
    {
        return new Room();

    }//end createConversation()
}//end class
