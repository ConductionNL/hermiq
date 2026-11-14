<?php

/**
 * Minimal OpenRegister DraftUpdatedEvent stub for standalone unit runs
 * and static analysis.
 *
 * Mirrored from openregister lib/Event/DraftUpdatedEvent.php: A draft was saved again, or just left draft.
 * OpenRegister dispatches it instead of ObjectUpdatedEvent, and it extends that
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
 * Minimal DraftUpdatedEvent stub.
 */
class DraftUpdatedEvent extends ObjectUpdatedEvent {
}//end class
