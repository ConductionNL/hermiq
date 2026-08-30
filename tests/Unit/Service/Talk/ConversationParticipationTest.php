<?php

/**
 * Unit tests for ConversationParticipation.
 *
 * This is the single definition of "may this user take a turn", enforced at
 * three entry points, so its negative cases matter more than its positive
 * ones — a mistake here is a cross-tenant data leak, not a cosmetic bug.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-shared-sessions/spec.md#requirement-a-session-may-be-taken-up-by-its-owner-or-a-listed-participant
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\ConversationParticipation;
use PHPUnit\Framework\TestCase;

/**
 * Tests the owner-or-participant rule.
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-shared-sessions/spec.md#requirement-a-session-may-be-taken-up-by-its-owner-or-a-listed-participant
 */
class ConversationParticipationTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var ConversationParticipation
	 */
	private ConversationParticipation $participation;

	/**
	 * Set up the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->participation = new ConversationParticipation();

	}//end setUp()

	/**
	 * The owner may always take a turn.
	 *
	 * @return void
	 */
	public function testOwnerMayTakeTurn(): void {
		$this->assertTrue(
			$this->participation->mayTakeTurn(
				conversationData: ['userId' => 'alice', 'participants' => []],
				userId: 'alice'
			)
		);

	}//end testOwnerMayTakeTurn()

	/**
	 * A listed participant may take a turn.
	 *
	 * @return void
	 */
	public function testListedParticipantMayTakeTurn(): void {
		$this->assertTrue(
			$this->participation->mayTakeTurn(
				conversationData: ['userId' => 'alice', 'participants' => ['bob', 'carol']],
				userId: 'bob'
			)
		);

	}//end testListedParticipantMayTakeTurn()

	/**
	 * A user who is neither owner nor participant is refused.
	 *
	 * The load-bearing negative case.
	 *
	 * @return void
	 */
	public function testNonParticipantIsRefused(): void {
		$this->assertFalse(
			$this->participation->mayTakeTurn(
				conversationData: ['userId' => 'alice', 'participants' => ['bob']],
				userId: 'mallory'
			)
		);

	}//end testNonParticipantIsRefused()

	/**
	 * An empty roster means owner-only — the pre-existing behaviour.
	 *
	 * @return void
	 */
	public function testEmptyRosterMeansOwnerOnly(): void {
		$data = ['userId' => 'alice'];

		$this->assertTrue($this->participation->mayTakeTurn(conversationData: $data, userId: 'alice'));
		$this->assertFalse($this->participation->mayTakeTurn(conversationData: $data, userId: 'bob'));

	}//end testEmptyRosterMeansOwnerOnly()

	/**
	 * An empty uid is always refused — never treated as a wildcard.
	 *
	 * @return void
	 */
	public function testEmptyUidIsRefused(): void {
		$this->assertFalse(
			$this->participation->mayTakeTurn(
				conversationData: ['userId' => '', 'participants' => ['']],
				userId: ''
			)
		);

	}//end testEmptyUidIsRefused()

	/**
	 * A malformed roster degrades to owner-only, never to open access.
	 *
	 * @return void
	 */
	public function testMalformedRosterDegradesClosed(): void {
		$this->assertFalse(
			$this->participation->mayTakeTurn(
				conversationData: ['userId' => 'alice', 'participants' => 'not-an-array'],
				userId: 'bob'
			)
		);

		$this->assertSame(
			[],
			$this->participation->roster(conversationData: ['participants' => 'nope'])
		);

	}//end testMalformedRosterDegradesClosed()

	/**
	 * Non-string and empty roster entries are discarded.
	 *
	 * @return void
	 */
	public function testRosterDiscardsJunkEntries(): void {
		$this->assertSame(
			['bob'],
			$this->participation->roster(
				conversationData: ['participants' => ['bob', '', null, 42, ['nested']]]
			)
		);

	}//end testRosterDiscardsJunkEntries()

	/**
	 * The permitted set is owner-first and de-duplicated.
	 *
	 * @return void
	 */
	public function testPermittedUidsAreOwnerFirstAndDeduplicated(): void {
		$this->assertSame(
			['alice', 'bob'],
			$this->participation->permittedUids(
				conversationData: ['userId' => 'alice', 'participants' => ['alice', 'bob', 'bob']]
			)
		);

	}//end testPermittedUidsAreOwnerFirstAndDeduplicated()
}//end class
