<?php

declare(strict_types=1);

/**
 * TalkMentionMatcher unit tests (talk-agent-sessions).
 *
 * The rule these cover used to be one line — `stripos($content, '@Hermiq')` —
 * and every property below was true only by accident of that name being a
 * single ASCII word. Per-agent bots make the target an arbitrary agent name, so
 * each of those accidents becomes a case that has to actually hold.
 *
 * The sharpest one is the word-boundary test: without it an agent called
 * "Release" is addressed by a message aimed at "Release Notes Agent", and two
 * agents in one room start answering each other's messages.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkMentionMatcher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\Talk\TalkMentionMatcher
 */
class TalkMentionMatcherTest extends TestCase {

	private TalkMentionMatcher $matcher;

	protected function setUp(): void {
		parent::setUp();
		$this->matcher = new TalkMentionMatcher();

	}//end setUp()

	/**
	 * The happy path, including the multi-word name the old matcher could not express.
	 */
	public function testMatchesAPlainMention(): void {
		$this->assertTrue($this->matcher->matches('@Hermiq what is the status', 'Hermiq'));
		$this->assertTrue($this->matcher->matches('@Release Notes Agent draft it', 'Release Notes Agent'));

	}//end testMatchesAPlainMention()

	/**
	 * Case and trailing punctuation are how people actually type.
	 */
	public function testToleratesCaseAndTrailingPunctuation(): void {
		$this->assertTrue($this->matcher->matches('@release notes agent, please summarise', 'Release Notes Agent'));
		$this->assertTrue($this->matcher->matches('hey @Hermiq!', 'Hermiq'));
		$this->assertTrue($this->matcher->matches('@Hermiq', 'Hermiq'));

	}//end testToleratesCaseAndTrailingPunctuation()

	/**
	 * 🔴 A name that runs into a longer WORD is not a mention.
	 *
	 * `@Hermiqbot` addresses something called Hermiqbot, not Hermiq. This is
	 * what the boundary check exists for, and what a bare `stripos` got wrong.
	 *
	 * Note what is deliberately NOT asserted here: that `@Release Notes Agent`
	 * fails to match an agent called `Release`. A space is a legitimate
	 * boundary — without it `@Hermiq what is the status` would stop matching,
	 * which is the single most common way anyone addresses an agent. The
	 * remaining ambiguity ("@X Y" where both `X` and `X Y` are agent names) is
	 * unresolvable from the text alone, and it cannot arise on the real path:
	 * a room resolves exactly ONE bound agent, so `matchesAny()` is only ever
	 * offered that agent's name plus the shared bot name — never a sibling
	 * agent's. Solving it inside the matcher would mean inventing a candidate
	 * set the matcher does not have.
	 */
	public function testANameRunningIntoALongerWordIsNotAMention(): void {
		$this->assertFalse($this->matcher->matches('@Hermiqbot hello', 'Hermiq'));
		$this->assertFalse($this->matcher->matches('@Hermiq-deploy ping', 'Hermiq'));

	}//end testANameRunningIntoALongerWordIsNotAMention()

	/**
	 * A bare name without the `@` is a reference, not an address.
	 */
	public function testAnUnprefixedNameIsNotAMention(): void {
		$this->assertFalse($this->matcher->matches('ask Hermiq about it', 'Hermiq'));

	}//end testAnUnprefixedNameIsNotAMention()

	/**
	 * Degenerate input must be a silent no-match — never an exception. The only
	 * caller decides whether an agent answers, so a throw would turn an oddly
	 * punctuated message into a failed turn.
	 */
	public function testEmptyInputIsASilentNoMatch(): void {
		$this->assertFalse($this->matcher->matches('', 'Hermiq'));
		$this->assertFalse($this->matcher->matches('@Hermiq', ''));
		$this->assertFalse($this->matcher->matches('@Hermiq', '   '));
		$this->assertFalse($this->matcher->matchesAny('@Hermiq hello', []));

	}//end testEmptyInputIsASilentNoMatch()

	/**
	 * matchesAny() takes the first hit across candidates (agent name, then the
	 * shared bot name so rooms bound before per-agent bots keep working).
	 */
	public function testMatchesAnyAcceptsEitherCandidate(): void {
		$names = ['Release Notes Agent', 'Hermiq'];
		$this->assertTrue($this->matcher->matchesAny('@Hermiq status?', $names));
		$this->assertTrue($this->matcher->matchesAny('@Release Notes Agent go', $names));
		$this->assertFalse($this->matcher->matchesAny('@SomebodyElse go', $names));

	}//end testMatchesAnyAcceptsEitherCandidate()

	/**
	 * Rendered mention parameters are matched case-insensitively, and a
	 * malformed parameter list is skipped rather than fatal.
	 */
	public function testMatchesRenderedMentionParameters(): void {
		$parameters = [
			'mention-call1' => ['type' => 'call', 'name' => 'release notes agent'],
		];
		$this->assertTrue($this->matcher->matchesParameters($parameters, ['Release Notes Agent']));
		$this->assertFalse($this->matcher->matchesParameters($parameters, ['Hermiq']));
		$this->assertFalse($this->matcher->matchesParameters(['broken', ['name' => '']], ['Hermiq']));

	}//end testMatchesRenderedMentionParameters()
}//end class
