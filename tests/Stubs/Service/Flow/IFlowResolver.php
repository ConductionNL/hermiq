<?php
/**
 * Minimal OpenRegister IFlowResolver stub for standalone unit runs.
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
 * Minimal IFlowResolver stub.
 */
interface IFlowResolver
{
    /**
     * Load a flow document by its id.
     *
     * @param string $flowId The flow's id.
     *
     * @return array|null The flow document, or null when not owned.
     */
    public function resolveFlow(string $flowId): ?array;

    /**
     * Resolve the subject a run is about.
     *
     * @param string $uuid     The subject uuid.
     * @param string $register The register.
     * @param string $schema   The schema.
     *
     * @return object|null The subject, or null.
     */
    public function resolveSubject(string $uuid, string $register, string $schema): ?object;

    /**
     * The flows wired to an event.
     *
     * @param string $event    The event id.
     * @param string $register The register.
     * @param string $schema   The schema.
     *
     * @return array<int, string> The flow ids.
     */
    public function flowsForTrigger(string $event, string $register, string $schema): array;
}//end interface
