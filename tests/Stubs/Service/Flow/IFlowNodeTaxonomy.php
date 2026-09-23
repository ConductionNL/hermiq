<?php

/**
 * Minimal OpenRegister IFlowNodeTaxonomy stub for standalone unit runs and static analysis.
 *
 * Signatures mirrored verbatim from openregister lib/Service/Flow/IFlowNodeTaxonomy.php.
 * Registered at TEST TIME only by tests/bootstrap.php and scanned (never
 * executed) by phpstan/psalm — see the note there on why these mappings must
 * not live in composer.json `autoload-dev`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Minimal IFlowNodeTaxonomy stub.
 */
interface IFlowNodeTaxonomy {
	/**
	 * The BPMN-ish shape the canvas draws this node as.
	 *
	 * @return string The kind.
	 */
	public function getKind(): string;

	/**
	 * The palette group this node belongs to.
	 *
	 * @return string The category.
	 */
	public function getCategory(): string;
}//end interface
