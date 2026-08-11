<?php

/**
 * Test stub for OCA\Talk\Participant (spreed).
 *
 * The owner's participant, resolved for message attribution / rate-limit context and
 * passed to ChatManager::sendMessage(). Hermiq calls no methods on it.
 *
 * ⚠️ The namespace is `OCA\Talk`, NOT `OCA\Talk\Model`. spreed declares this class in
 * `lib/Participant.php` as `OCA\Talk\Participant`; there is no `OCA\Talk\Model\Participant`
 * in spreed at all. This stub used to sit under `Model\`, so it stood in for a class
 * that does not exist and could never satisfy `createMock(OCA\Talk\Participant::class)` —
 * five DeliveryServiceTest cases errored with
 * `UnknownTypeException: Class or interface "OCA\Talk\Participant" does not exist`
 * in every matrix cell (run 31490144919).
 *
 * @category Stub
 * @package  OCA\Talk
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

namespace OCA\Talk;

/**
 * Minimal stub of the spreed Participant model.
 */
class Participant
{
}//end class
