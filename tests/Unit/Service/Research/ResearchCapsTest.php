<?php

/**
 * Unit tests for ResearchCaps (tools-deep-research-report).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Research
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Research;

use OCA\Hermiq\Service\Research\ResearchCaps;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\Research\ResearchCaps
 */
final class ResearchCapsTest extends TestCase {

	public function testTheInstanceDefaultsApplyWhereNothingUsableIsSet(): void {
		$caps = ResearchCaps::fromSettings(['sources' => '12', 'toolCalls' => 0, 'minutes' => 'lang', 'tokens' => -5]);

		self::assertSame(['toolCalls' => 40, 'sources' => 12, 'minutes' => 20, 'tokens' => 200000], $caps->toArray());
	}

	public function testAnAgentCanLowerACapButNeverRaiseIt(): void {
		$caps = ResearchCaps::fromSettings([])->loweredBy(['sources' => 5, 'toolCalls' => 400, 'minutes' => null]);

		self::assertSame(['toolCalls' => 40, 'sources' => 5, 'minutes' => 20, 'tokens' => 200000], $caps->toArray());
	}

	public function testAQuickRunGetsHalfOfEachCap(): void {
		$caps = new ResearchCaps(toolCalls: 40, sources: 1, minutes: 20, tokens: 200000);

		self::assertSame(['toolCalls' => 20, 'sources' => 1, 'minutes' => 10, 'tokens' => 100000], $caps->forDepth('quick')->toArray());
		self::assertSame($caps, $caps->forDepth('thorough'));
	}

	public function testNoReasonWhileEveryCapHolds(): void {
		self::assertNull(ResearchCaps::fromSettings([])->stopReason(39, 19, 1199, 199999));
	}

	public function testTheTwentiethPageStopsTheRunWithTheSpecSentence(): void {
		self::assertSame(
			'This report stopped early: the source limit of 20 was reached.',
			ResearchCaps::fromSettings([])->stopReason(40, 20, 1200, 200000)
		);
	}

	public function testEachCapNamesItself(): void {
		$caps = ResearchCaps::fromSettings([]);

		self::assertSame('This report stopped early: the tool call limit of 40 was reached.', $caps->stopReason(40, 3, 10, 10));
		self::assertSame('This report stopped early: the time limit of 20 minutes was reached.', $caps->stopReason(3, 3, 1200, 10));
		self::assertSame('This report stopped early: the token limit of 200,000 was reached.', $caps->stopReason(3, 3, 10, 200000));
	}
}
