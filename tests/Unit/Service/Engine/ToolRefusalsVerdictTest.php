<?php

/**
 * Hermiq ToolRefusals gate-verdict and encoding unit tests.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ToolRefusals;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the approval-gate verdict and the refusal envelope encoding.
 *
 * @covers \OCA\Hermiq\Service\Engine\ToolRefusals
 */
class ToolRefusalsVerdictTest extends TestCase {

	/**
	 * An approval object with a uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity The approval.
	 */
	private function approval(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		return $entity;
	}//end approval()

	/**
	 * A decided (denied) approval wins over a pending one.
	 *
	 * @return void
	 */
	public function testADecidedApprovalIsADenial(): void {
		$refusals = new ToolRefusals(trace: null, channel: null);

		$this->assertSame(
			['status' => 'denied', 'approvalId' => 'dec-1', 'message' => 'This action was denied by a reviewer and cannot be run.'],
			$refusals->gateVerdict($this->approval('dec-1'), $this->approval('pend-1'))
		);
	}//end testADecidedApprovalIsADenial()

	/**
	 * Without a decision the verdict is pending, naming the pending approval or none.
	 *
	 * @return void
	 */
	public function testWithoutADecisionTheVerdictIsPending(): void {
		$refusals = new ToolRefusals(trace: null, channel: null);
		$message = 'This action requires human approval before it can run.';

		$this->assertSame(
			['status' => 'pending', 'approvalId' => 'pend-1', 'message' => $message],
			$refusals->gateVerdict(null, $this->approval('pend-1'))
		);
		$this->assertSame(['status' => 'pending', 'approvalId' => '', 'message' => $message], $refusals->gateVerdict(null, null));
	}//end testWithoutADecisionTheVerdictIsPending()

	/**
	 * An envelope encodes as JSON; one that cannot be encoded keeps only ok/isError/error.
	 *
	 * @return void
	 */
	public function testAnUnencodableEnvelopeKeepsOnlyTheErrorKeys(): void {
		$refusals = new ToolRefusals(trace: null, channel: null);

		$this->assertSame('{"isError":true,"error":"x","toolId":"t"}', $refusals->encoded(['isError' => true, 'error' => 'x', 'toolId' => 't']));
		$this->assertSame(
			'{"ok":false,"isError":true,"error":"denied"}',
			$refusals->encoded(['ok' => false, 'isError' => true, 'error' => 'denied', 'message' => "bad \xB1 bytes"])
		);
	}//end testAnUnencodableEnvelopeKeepsOnlyTheErrorKeys()
}//end class
