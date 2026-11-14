<?php

/**
 * TalkBridge::agentIdFromBotUrl() tests (talk-agent-sessions).
 *
 * The parser feeds a guard on the approval path, which decides governance
 * actions, so it is deliberately strict: only exactly
 * `nextcloudapp://hermiq-<non-empty>` carries an agent. Nothing pinned that
 * strictness until now — in particular that the legacy shared bot URL, which
 * carries NO agent, is not read as the agent `''`.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
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
 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-each-talk-enabled-agent-has-its-own-talk-bot-identity
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkBridge;
use PHPUnit\Framework\TestCase;

/**
 * The per-agent bot URL parser.
 *
 * @covers \OCA\Hermiq\Service\Talk\TalkBridge
 */
class TalkBridgeBotUrlTest extends TestCase {

	/**
	 * A per-agent bot URL yields exactly the agent id after the prefix,
	 * unchanged.
	 *
	 * @return void
	 */
	public function testAPerAgentBotUrlYieldsItsAgentId(): void {
		$this->assertSame(
			'0b7e4c1a-9f2d-4e55-a1c3-6d8f2e9b1c44',
			TalkBridge::agentIdFromBotUrl('nextcloudapp://hermiq-0b7e4c1a-9f2d-4e55-a1c3-6d8f2e9b1c44')
		);
		$this->assertSame('agent-1', TalkBridge::agentIdFromBotUrl(TalkBridge::BOT_URL_PREFIX . 'agent-1'));

	}//end testAPerAgentBotUrlYieldsItsAgentId()

	/**
	 * The legacy shared bot carries no agent and must not be mistaken for the
	 * agent `''`; nor may a prefix with only whitespace after it.
	 *
	 * @return void
	 */
	public function testTheSharedBotAndABlankSuffixCarryNoAgent(): void {
		$this->assertNull(TalkBridge::agentIdFromBotUrl('nextcloudapp://hermiq'));
		$this->assertNull(TalkBridge::agentIdFromBotUrl('nextcloudapp://hermiq-'));
		$this->assertNull(TalkBridge::agentIdFromBotUrl('nextcloudapp://hermiq-   '));

	}//end testTheSharedBotAndABlankSuffixCarryNoAgent()

	/**
	 * Anything merely resembling a Hermiq bot URL is refused: another app's
	 * bot, a different scheme, a different case, or the prefix appearing
	 * anywhere but the start.
	 *
	 * @return void
	 */
	public function testAUrlThatOnlyResemblesOursCarriesNoAgent(): void {
		$lookalikes = [
			'',
			'nextcloudapp://other-agent-1',
			'https://hermiq-agent-1',
			'NEXTCLOUDAPP://HERMIQ-agent-1',
			'nextcloudapp://hermiqx-agent-1',
			' nextcloudapp://hermiq-agent-1',
			'evil:nextcloudapp://hermiq-agent-1',
		];

		foreach ($lookalikes as $url) {
			$this->assertNull(TalkBridge::agentIdFromBotUrl($url), 'url "' . $url . '"');
		}

	}//end testAUrlThatOnlyResemblesOursCarriesNoAgent()

}//end class
