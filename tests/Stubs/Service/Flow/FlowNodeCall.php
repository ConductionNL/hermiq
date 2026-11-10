<?php

/**
 * Test stub for OpenRegister FlowNodeCall.
 *
 * Stands in for OCA\OpenRegister\Service\Flow\FlowNodeCall when OpenRegister is
 * not installed (standalone CI), with the real constructor.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * One flow-node invocation: the input items, the step configuration and the run context.
 */
final class FlowNodeCall {

	/**
	 * Constructor.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata, not the data channel.
	 */
	public function __construct(
		public readonly array $items = [],
		public readonly array $config = [],
		public readonly array $context = [],
	) {
	}//end __construct()
}//end class
