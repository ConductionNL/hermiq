<?php

/**
 * Minimal OpenRegister FlowItems stub for standalone unit runs and static analysis.
 *
 * Constants mirrored verbatim from openregister lib/Service/Flow/FlowItems.php.
 * Registered at TEST TIME only by tests/bootstrap.php and scanned (never
 * executed) by phpstan/psalm.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Minimal FlowItems stub: the key names an item carries between steps.
 */
final class FlowItems {
	/**
	 * The item's record.
	 *
	 * @var string
	 */
	public const JSON = 'json';

	/**
	 * The item's binary attachments.
	 *
	 * @var string
	 */
	public const BINARY = 'binary';

	/**
	 * Which input item an output item came from.
	 *
	 * @var string
	 */
	public const PAIRED_ITEM = 'pairedItem';

	/**
	 * The step output key.
	 *
	 * @var string
	 */
	public const OUTPUT = 'output';
}//end class
