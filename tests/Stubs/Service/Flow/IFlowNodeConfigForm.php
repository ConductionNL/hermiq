<?php

/**
 * Minimal OpenRegister IFlowNodeConfigForm stub for standalone unit runs and static analysis.
 *
 * Signatures mirrored verbatim from openregister lib/Service/Flow/IFlowNodeConfigForm.php.
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
 * Minimal IFlowNodeConfigForm stub.
 */
interface IFlowNodeConfigForm {
	/**
	 * The fields the editor renders for this node.
	 *
	 * @return array<int,array<string,mixed>> The field descriptions.
	 */
	public function configForm(): array;
}//end interface
