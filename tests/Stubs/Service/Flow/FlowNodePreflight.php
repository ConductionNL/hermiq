<?php

/**
 * Test stub for OpenRegister's FlowNodePreflight.
 *
 * Stands in for OCA\OpenRegister\Service\Flow\FlowNodePreflight when OpenRegister
 * is not installed (standalone CI). Only the two entry points SeedBundledFlows
 * calls are declared, with the real signatures: `inspect()` reports a flow
 * document's blocking findings and warnings, `describe()` turns the blocking ones
 * into a message. This stub finds nothing; tests mock it.
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
 * Stub of the save-time check that a flow's steps can run on this instance.
 */
class FlowNodePreflight {

	/**
	 * The findings for a flow document.
	 *
	 * @param array $flow The flow document.
	 *
	 * @return array{blocking: array<int, array<string, string>>, warnings: array<int, array<string, string>>}
	 */
	public function inspect(array $flow): array {
		return ['blocking' => [], 'warnings' => []];
	}//end inspect()

	/**
	 * The refusal message for a flow's blocking findings.
	 *
	 * @param string                           $flow     The flow's name.
	 * @param array<int, array<string, string>> $blocking The blocking findings.
	 *
	 * @return string The message.
	 */
	public function describe(string $flow, array $blocking): string {
		return $flow;
	}//end describe()
}//end class
