<?php

/**
 * Hermiq ComparedRun
 *
 * One side of a run comparison: its step timeline and its redacted summary.
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
 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * A run's steps and summary, as compared.
 *
 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002
 */
final class ComparedRun
{

    /**
     * Build one side.
     *
     * @param array<int, mixed> $steps   The run's step timeline.
     * @param string            $summary The run's redacted summary.
     *
     * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002
     */
    public function __construct(
        public readonly array $steps,
        public readonly string $summary,
    ) {
    }//end __construct()
}//end class
