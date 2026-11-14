<?php

/**
 * The "Inkoop overleg" example session carries turns from both channels.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/chat-carry-a-conversation-across-channels/tasks.md#task-5-seed-data
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * A session begun in Talk and continued on the Chat page, so the Chat page
 * shows "via Talk" under the first question and not under the second.
 *
 * @coversNothing
 */
class ExampleSetInkoopOverlegTest extends TestCase {

	/**
	 * The example set's objects by key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function objects(): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$out = [];
		foreach ($set['objects'] as $object) {
			$out[$object['key']] = $object;
		}

		return $out;
	}//end objects()

	/**
	 * The session is Pieter's, named after the room, with the Woo-triage agent.
	 *
	 * @return void
	 */
	public function testTheSessionIsPietersInkoopOverleg(): void {
		$session = $this->objects()['session-inkoop-overleg'];

		$this->assertSame(expected: 'agentsession', actual: $session['schema']);
		$this->assertSame(expected: 'Inkoop overleg', actual: $session['data']['title']);
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $session['owner']);
		$this->assertSame(expected: '{{object:agent-woo-triage.id}}', actual: $session['data']['agentId']);
	}//end testTheSessionIsPietersInkoopOverleg()

	/**
	 * A question typed in Talk, its answer, then a question typed on the Chat page and its answer.
	 *
	 * @return void
	 */
	public function testTheTurnsComeFromBothChannels(): void {
		$turns = array_values(
			array_filter(
				$this->objects(),
				static fn (array $o): bool => $o['schema'] === 'agentsessionturn' && $o['data']['sessionId'] === '{{object:session-inkoop-overleg.id}}'
			)
		);

		$this->assertSame(
			expected: [['user', 'talk'], ['assistant', null], ['user', 'hermiq'], ['assistant', null]],
			actual: array_map(static fn (array $t): array => [$t['data']['role'], ($t['data']['channel'] ?? null)], $turns)
		);
	}//end testTheTurnsComeFromBothChannels()
}//end class
