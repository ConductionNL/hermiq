<?php

/**
 * Hermiq RunLookup
 *
 * What a compared run is read against: the caller's visible agents and the
 * run list's own row shaper.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use Closure;

/**
 * The visible agents and the row shaper of one comparison.
 *
 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
 */
final class RunLookup
{

    /**
     * Build the lookup.
     *
     * @param array<string, string> $visibleAgents The caller's visible agents (uuid => name).
     * @param Closure               $toRunRow      Shapes an entry as the run list does.
     *
     * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
     */
    public function __construct(
        public readonly array $visibleAgents,
        public readonly Closure $toRunRow,
    ) {
    }//end __construct()
}//end class
