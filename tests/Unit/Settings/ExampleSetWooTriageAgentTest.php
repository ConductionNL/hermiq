<?php

/**
 * Hermiq's part of the Zuiddrecht example set carries the Woo-triage agent the
 * HmTalk board shows in its own Talk room: private to the demo employee and
 * the Woo team lead, reachable from Talk, asking approval before it acts.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped example-set fragment from disk.
 */
class ExampleSetWooTriageAgentTest extends TestCase {

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/example-sets/zuiddrecht.json';

	/**
	 * The agent is private to Pieter and Anouk, Talk-enabled and asks approval.
	 *
	 * @return void
	 */
	public function testTheWooTriageAgentIsReachableFromTalk(): void {
		self::assertFileExists(filename: self::FRAGMENT);
		$fragment = json_decode((string)file_get_contents(self::FRAGMENT), true);
		$agent    = (array_column($fragment['objects'], null, 'key')['agent-woo-triage'] ?? null);

		$this->assertNotNull(actual: $agent);
		$this->assertSame(expected: ['hermiq', 'agent', 'Woo-triage'], actual: [$agent['register'], $agent['schema'], $agent['data']['name']]);
		$this->assertTrue(condition: $agent['data']['talkEnabled']);
		$this->assertTrue(condition: $agent['data']['isPrivate']);
		$this->assertTrue(condition: $agent['data']['requiresApproval']);
		$this->assertSame(expected: ['{{cast:pieter.uid}}', '{{cast:anouk.uid}}'], actual: $agent['data']['invitedUsers']);
	}//end testTheWooTriageAgentIsReachableFromTalk()
}//end class
