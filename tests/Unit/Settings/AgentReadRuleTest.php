<?php

/**
 * Tests the Agent schema's read rule: OpenRegister's object API answers a
 * private agent only to its owner, invited users and members of its groups.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-group-sharing-is-enforced-wherever-an-agent-is-read-or-run-req-agshare-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Task 6 of agents-sharing-and-catalog-columns: the object API is closed by
 * the schema's own rule, so a colleague cannot read a private agent there.
 *
 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-group-sharing-is-enforced-wherever-an-agent-is-read-or-run-req-agshare-002
 */
class AgentReadRuleTest extends TestCase {

	/**
	 * Every read grant is conditional, and the conditions are exactly the sharing rule.
	 *
	 * @return void
	 */
	public function testEveryReadGrantIsTheSharingRule(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'), true);
		$read = $register['components']['schemas']['Agent']['authorization']['read'];

		$matches = [];
		foreach ($read as $grant) {
			$this->assertIsArray($grant, 'An unconditional read grant would open every private agent.');
			$this->assertArrayHasKey('match', $grant);
			$matches[] = $grant['match'];
		}

		$this->assertContains(['isPrivate' => false], $matches);
		$this->assertContains(['isPrivate' => null], $matches);
		$this->assertContains(['invitedUsers' => ['$contains' => '$userId']], $matches);
		$this->assertContains(['groups' => ['$contains' => '$user.groups']], $matches);
		$this->assertCount(4, $matches);

	}//end testEveryReadGrantIsTheSharingRule()
}//end class
