<?php

/**
 * Unit tests for ToolDispatch.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ToolDispatch;
use PHPUnit\Framework\TestCase;

/**
 * A tool dispatch's outcome and encoded result.
 *
 * @covers \OCA\Hermiq\Service\Engine\ToolDispatch
 */
final class ToolDispatchTest extends TestCase {
	public function testOutcomeIsOkUnlessOverriddenOrErrored(): void {
		$dispatch = new ToolDispatch('t', []);
		$dispatch->envelope = ['isError' => false, 'result' => 1];
		$this->assertSame('ok', $dispatch->outcome());

		$dispatch->outcomeOverride = 'truncated';
		$this->assertSame('truncated', $dispatch->outcome());

		$dispatch->envelope['isError'] = true;
		$this->assertSame('error', $dispatch->outcome());
	}

	public function testEncodedResultIsTheJsonOfTheResult(): void {
		$dispatch = new ToolDispatch('t', []);
		$dispatch->envelope = ['isError' => false, 'result' => ['a' => [1, 2]]];

		$this->assertSame('{"a":[1,2]}', $dispatch->encodedResult());
	}

	public function testAnUnencodableResultBecomesAnErrorObject(): void {
		$dispatch = new ToolDispatch('t', []);
		$dispatch->envelope = ['isError' => false, 'result' => "\xB1\x31"];

		$this->assertSame('{"error":"Tool result could not be encoded"}', $dispatch->encodedResult());
	}
}
