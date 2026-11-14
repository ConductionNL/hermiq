<?php

/**
 * Hermiq OutsideCall unit tests.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\OutsideAgent
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

namespace OCA\Hermiq\Tests\Unit\Service\OutsideAgent;

use OCA\Hermiq\Service\OutsideAgent\OutsideCall;
use OCA\Hermiq\Service\OutsideAgent\OutsideCallRefusedException;
use PHPUnit\Framework\TestCase;

/**
 * Each refusal is built from the call it refuses.
 *
 * @covers \OCA\Hermiq\Service\OutsideAgent\OutsideCall
 * @uses   \OCA\Hermiq\Service\OutsideAgent\OutsideCallRefusedException
 */
class OutsideCallTest extends TestCase {

	/**
	 * The call keeps the tool and principal it was built with.
	 *
	 * @return void
	 */
	public function testTheCallCarriesItsToolAndPrincipal(): void {
		$call = new OutsideCall(toolId: 'dossiq.case.get', principal: 'alice');

		self::assertSame('dossiq.case.get', $call->toolId);
		self::assertSame('alice', $call->principal);
	}//end testTheCallCarriesItsToolAndPrincipal()

	/**
	 * A refusal names the gate and carries this call's tool and principal.
	 *
	 * @return void
	 */
	public function testARefusalIsBuiltFromTheCall(): void {
		$call = new OutsideCall(toolId: 'dossiq.case.update', principal: 'bob');

		$refusal = $call->refused(gate: OutsideCallRefusedException::GATE_GRANT, reason: 'not granted');

		self::assertInstanceOf(OutsideCallRefusedException::class, $refusal);
		self::assertSame(OutsideCallRefusedException::GATE_GRANT, $refusal->gate());
		self::assertSame('dossiq.case.update', $refusal->toolId);
		self::assertSame('bob', $refusal->principal);
		self::assertSame(
			"Refused by the registration-grant gate: 'dossiq.case.update' for principal 'bob': not granted.",
			$refusal->getMessage()
		);
		self::assertSame(403, $refusal->getCode());
	}//end testARefusalIsBuiltFromTheCall()

	/**
	 * Two refusals of one call are distinct exceptions with their own gates.
	 *
	 * @return void
	 */
	public function testEachRefusalIsANewException(): void {
		$call = new OutsideCall(toolId: 'dossiq.case.get', principal: 'carol');

		$first  = $call->refused(gate: OutsideCallRefusedException::GATE_SURFACE, reason: 'not offered');
		$second = $call->refused(gate: OutsideCallRefusedException::GATE_REGISTRATION, reason: 'no registration');

		self::assertNotSame($first, $second);
		self::assertSame('tool-surface', $first->gate());
		self::assertSame('registration', $second->gate());
	}//end testEachRefusalIsANewException()
}//end class
