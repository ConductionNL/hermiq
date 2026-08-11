<?php
/**
 * Minimal OpenRegister IFlowNodeLogActions stub for standalone unit runs and static analysis.
 *
 * Signatures mirrored verbatim from openregister lib/Service/Flow/IFlowNodeLogActions.php.
 * Registered at TEST TIME only by tests/bootstrap.php and scanned (never
 * executed) by phpstan/psalm — see the note there on why these mappings must
 * not live in composer.json `autoload-dev`.
 *
 * ⚠️ `HermiqAgentNode` IMPLEMENTS this interface, so its absence is a class-header
 * fatal, not a lazy-resolution miss: every test that touches HermiqAgentNode errors
 * with `Interface "OCA\OpenRegister\Service\Flow\IFlowNodeLogActions" not found`.
 * Its sibling `IFlowNode` was stubbed here and this one was not, so the suite passed
 * only in the matrix cells where a real OpenRegister happened to enable — 3 of 6 in
 * run 31490144919, and 0 of 1 locally.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Minimal IFlowNodeLogActions stub.
 */
interface IFlowNodeLogActions
{
    /**
     * The links this log entry earns.
     *
     * @param array<string, mixed> $entry One entry from the run's log.
     *
     * @return array<int, array{label: string, href: string, icon?: string}> The links.
     */
    public function logActions(array $entry): array;
}//end interface
