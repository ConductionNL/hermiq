<?php

/**
 * Pins the shape of the three seeded example skills (skill-maturity): their key
 * order and the local, unscanned, uninstalled state every seed row starts in.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
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
 * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\SeedMaturityExampleSkills;
use PHPUnit\Framework\TestCase;

/**
 * The seed rows' keys, in order, and their starting state.
 */
class SeedMaturityExampleSkillsShapeTest extends TestCase {

	/**
	 * Every seed row carries the same keys in the same order (the L4 seed adds its
	 * evidence last) and starts active, local, unscanned, unowned and uninstalled.
	 *
	 * @return void
	 */
	public function testSeedRowsKeepTheirKeyOrderAndStartingState(): void {
		$keys = [
			'name',
			'description',
			'frontmatter',
			'body',
			'files',
			'state',
			'source',
			'quarantineReason',
			'scanReport',
			'createdBy',
			'installedOn',
			'maturityLevel',
			'targetLevel',
		];
		$seeds = SeedMaturityExampleSkills::seedSkills();

		$this->assertSame(['meeting-notes-cleanup', 'woo-request-triage', 'tender-summary'], array_column($seeds, 'name'));
		$this->assertSame($keys, array_keys($seeds[0]));
		$this->assertSame($keys, array_keys($seeds[1]));
		$this->assertSame(array_merge($keys, ['levelEvidence']), array_keys($seeds[2]));
		foreach ($seeds as $seed) {
			$this->assertSame(
				[
					'state' => 'active',
					'source' => 'local',
					'quarantineReason' => null,
					'scanReport' => null,
					'createdBy' => '',
					'installedOn' => [],
				],
				array_slice($seed, 5, 6, true)
			);
		}

		$this->assertSame([1, 2, 4], array_column($seeds, 'maturityLevel'));
		$this->assertSame([2, 3, 5], array_column($seeds, 'targetLevel'));

	}//end testSeedRowsKeepTheirKeyOrderAndStartingState()
}//end class
