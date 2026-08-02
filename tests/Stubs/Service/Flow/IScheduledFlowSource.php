<?php
/**
 * Minimal OpenRegister IScheduledFlowSource stub for standalone unit runs.
 *
 * Registered at TEST TIME only by tests/bootstrap.php — see the note there on
 * why these mappings must not live in composer.json `autoload-dev`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Minimal IScheduledFlowSource stub.
 */
interface IScheduledFlowSource
{
    /**
     * The flows this app owns that declare a schedule.
     *
     * @return array<int, array{id: string, enabled: bool, trigger: string, cron: string, owner: string|null}>
     *         The candidate flows.
     */
    public function scheduledFlows(): array;
}//end interface
