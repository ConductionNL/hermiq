<?php

/**
 * Test stub for OCA\Talk\Service\NoteToSelfService (spreed 24.0.1).
 *
 * Only ensureNoteToSelfExistsForUser() is modelled — resolving (and lazily creating)
 * the owner's Note-to-self room, the Talk fallback target. Real signature verified
 * against spreed 24.0.1 lib/Service/NoteToSelfService.php.
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

/**
 * Minimal stub of the spreed Note-to-self service.
 */
class NoteToSelfService
{
    /**
     * Resolve (or create) the user's Note-to-self room.
     *
     * @param string $userId The user.
     *
     * @return Room The Note-to-self room.
     */
    public function ensureNoteToSelfExistsForUser(string $userId): Room
    {
        return new Room();

    }//end ensureNoteToSelfExistsForUser()
}//end class
