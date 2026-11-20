<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\ComputerUse
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\ComputerUse;

use RuntimeException;

/**
 * Why a computer target request could not be answered, with the HTTP status that says so.
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
 */
final class ComputerTargetRefusal extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $message The reason, as the caller sees it.
	 * @param int    $status  The HTTP status: 400, 404, 409 or 502.
	 */
	public function __construct(string $message, private readonly int $status) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
