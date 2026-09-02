<?php

/**
 * Minimal OpenRegister IFlowOversightCheck stub for standalone runs.
 *
 * Signatures mirrored verbatim from openregister
 * lib/Service/Flow/IFlowOversightCheck.php. Registered at TEST TIME only by
 * tests/bootstrap.php and scanned (never executed) by phpstan/psalm.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Minimal IFlowOversightCheck stub.
 */
interface IFlowOversightCheck {
	/**
	 * Stable id, namespaced by the contributing app (`{app}.{check}`).
	 *
	 * @return string The check's id.
	 */
	public function getId(): string;

	/**
	 * Decide whether the next hop may execute.
	 *
	 * Returning a reason string is a veto; returning null is consent.
	 *
	 * @param array<string, mixed> $context The run context.
	 *
	 * @return string|null The reason for refusing, or null to allow the hop.
	 */
	public function veto(array $context): ?string;
}//end interface
