<?php

/**
 * Unit tests for OutputHold (oversight-ask-and-hold): the schedule's
 * holdOutput decides when it sets one, otherwise the agent's.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Schedule;

use OCA\Hermiq\Service\Schedule\OutputHold;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutputHold::class)]
class OutputHoldTest extends TestCase {

	public function testTheSchedulesValueWinsWhenSet(): void {
		$this->assertTrue(OutputHold::holds(schedule: ['holdOutput' => true], agent: ['holdOutput' => false]));
		$this->assertFalse(OutputHold::holds(schedule: ['holdOutput' => false], agent: ['holdOutput' => true]));
	}

	public function testAnUnsetScheduleFollowsItsAgent(): void {
		$this->assertTrue(OutputHold::holds(schedule: [], agent: ['holdOutput' => true]));
		$this->assertTrue(OutputHold::holds(schedule: ['holdOutput' => null], agent: ['holdOutput' => true]));
		$this->assertFalse(OutputHold::holds(schedule: [], agent: []));
	}

	public function testOnlyARealTrueHolds(): void {
		$this->assertFalse(OutputHold::holds(schedule: ['holdOutput' => 'yes'], agent: ['holdOutput' => 1]));
		$this->assertFalse(OutputHold::holds(schedule: [], agent: null));
	}
}//end class
