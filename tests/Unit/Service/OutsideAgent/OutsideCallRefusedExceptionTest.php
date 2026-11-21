<?php

/**
 * Hermiq OutsideCallRefusedException unit tests.
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

use OCA\Hermiq\Service\OutsideAgent\OutsideCallRefusedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A refusal names the gate that closed.
 *
 * @covers \OCA\Hermiq\Service\OutsideAgent\OutsideCallRefusedException
 */
class OutsideCallRefusedExceptionTest extends TestCase {

	/**
	 * The gate names are the stable strings integrators see.
	 *
	 * @return void
	 */
	public function testTheGateNamesAreStable(): void {
		self::assertSame('registration-grant', OutsideCallRefusedException::GATE_GRANT);
		self::assertSame('registration', OutsideCallRefusedException::GATE_REGISTRATION);
		self::assertSame('tool-surface', OutsideCallRefusedException::GATE_SURFACE);
		self::assertSame('owning-app', OutsideCallRefusedException::GATE_OWNING_APP);
	}//end testTheGateNamesAreStable()

	/**
	 * The message names gate, tool, principal and reason, and the code is 403.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesGateToolPrincipalAndReason(): void {
		$e = new OutsideCallRefusedException(
			gate: OutsideCallRefusedException::GATE_OWNING_APP,
			toolId: 'dossiq.case.delete',
			principal: 'dave',
			reason: 'the owning app refused'
		);

		self::assertInstanceOf(RuntimeException::class, $e);
		self::assertSame(
			"Refused by the owning-app gate: 'dossiq.case.delete' for principal 'dave': the owning app refused.",
			$e->getMessage()
		);
		self::assertSame(403, $e->getCode());
		self::assertSame('owning-app', $e->gate());
		self::assertSame('owning-app', $e->gate);
		self::assertSame('dossiq.case.delete', $e->toolId);
		self::assertSame('dave', $e->principal);
	}//end testTheRefusalNamesGateToolPrincipalAndReason()
}//end class
