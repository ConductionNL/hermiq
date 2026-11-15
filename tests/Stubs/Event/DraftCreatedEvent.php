<?php

/**
 * Minimal OpenRegister DraftCreatedEvent stub for standalone unit runs
 * and static analysis.
 *
 * Mirrored from openregister lib/Event/DraftCreatedEvent.php: A draft was stored for the first time.
 * OpenRegister dispatches it instead of ObjectCreatedEvent, and it extends that
 * event, so it carries the same constructor and getters. Registered at TEST
 * TIME only by tests/bootstrap.php; the real class wins whenever OpenRegister
 * is enabled.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

/**
 * Minimal DraftCreatedEvent stub.
 */
class DraftCreatedEvent extends ObjectCreatedEvent {
}//end class
