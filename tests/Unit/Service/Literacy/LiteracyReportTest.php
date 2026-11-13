<?php

/**
 * Unit tests for LiteracyReport: the per-person overview, its CSV export and
 * the article 4 evidence status, over hand-built lessons and sessions.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Literacy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Literacy;

use DateTime;
use OCA\Hermiq\Service\Literacy\LiteracyCourse;
use OCA\Hermiq\Service\Literacy\LiteracyReport;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The admin report and evidence.
 *
 * @covers \OCA\Hermiq\Service\Literacy\LiteracyReport
 * @uses   \OCA\Hermiq\Service\Literacy\LiteracyCourse
 */
final class LiteracyReportTest extends TestCase {

	/**
	 * The in-memory store.
	 *
	 * @var InMemoryObjects
	 */
	private InMemoryObjects $objects;

	/**
	 * Seed two English lessons.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new InMemoryObjects();
		foreach (['one' => 1, 'two' => 2] as $slug => $order) {
			$this->objects->put('literacylesson', ['slug' => $slug, 'locale' => 'en', 'order' => $order, 'checkAnswer' => 0]);
		}

	}//end setUp()

	/**
	 * The report under test.
	 *
	 * @return LiteracyReport
	 */
	private function report(): LiteracyReport {
		return new LiteracyReport($this->objects, new LiteracyCourse($this->objects, new NullLogger()));

	}//end report()

	/**
	 * Store completions for a person.
	 *
	 * @param string $uid The person
	 * @param string $organisation The organisation
	 * @param string ...$slugs The completed lessons
	 *
	 * @return void
	 */
	private function complete(string $uid, string $organisation, string ...$slugs): void {
		foreach ($slugs as $slug) {
			$this->objects->put(
				'literacycompletion',
				['userId' => $uid, 'lessonSlug' => $slug, 'lessonVersion' => 1, 'locale' => 'en'],
				$organisation
			);
		}

	}//end complete()

	/**
	 * Store an agent session for a person.
	 *
	 * @param string $uid The person
	 * @param string $organisation The organisation
	 * @param string $updated When the session was last updated
	 *
	 * @return void
	 */
	private function session(string $uid, string $organisation, string $updated = '-1 day'): void {
		$this->objects->put('agentsession', ['userId' => $uid], $organisation, null, new DateTime($updated));

	}//end session()

	/**
	 * The overview lists every person with completions in the organisation, sorted by id.
	 *
	 * @return void
	 */
	public function testOverviewPerPersonSorted(): void {
		$this->complete('zoe', 'org-a', 'one', 'two');
		$this->complete('adam', 'org-a', 'one', 'one');
		$this->complete('other', 'org-b', 'one', 'two');
		$this->complete('', 'org-a', 'one');

		$this->assertSame(
			[
				['userId' => 'adam', 'done' => 1, 'total' => 2, 'complete' => false],
				['userId' => 'zoe', 'done' => 2, 'total' => 2, 'complete' => true],
			],
			$this->report()->overview('org-a')
		);

	}//end testOverviewPerPersonSorted()

	/**
	 * Without any lessons nobody counts as complete.
	 *
	 * @return void
	 */
	public function testOverviewWithoutLessonsIsNeverComplete(): void {
		$this->objects = new InMemoryObjects();
		$this->complete('adam', 'org-a', 'one');

		$this->assertSame(
			[['userId' => 'adam', 'done' => 0, 'total' => 0, 'complete' => false]],
			$this->report()->overview('org-a')
		);

	}//end testOverviewWithoutLessonsIsNeverComplete()

	/**
	 * The CSV has a header, yes/no flags, quoted fields and neutralised formulas.
	 *
	 * @return void
	 */
	public function testCsvEscapesFields(): void {
		$this->complete('alice', 'org-a', 'one', 'two');
		$this->complete('bob, jr', 'org-a', 'one');
		$this->complete('=cmd', 'org-a', 'one');
		$this->complete('say "hi"', 'org-a', 'one');
		$this->complete('-1,2', 'org-a', 'one');

		$this->assertSame(
			"userId,done,total,complete\n"
			. "\"'-1,2\",1,2,no\n"
			. "'=cmd,1,2,no\n"
			. "alice,2,2,yes\n"
			. "\"bob, jr\",1,2,no\n"
			. "\"say \"\"hi\"\"\",1,2,no\n",
			$this->report()->csv('org-a')
		);

	}//end testCsvEscapesFields()

	/**
	 * An empty organisation exports only the header.
	 *
	 * @return void
	 */
	public function testCsvEmpty(): void {
		$this->assertSame("userId,done,total,complete\n", $this->report()->csv('org-a'));

	}//end testCsvEmpty()

	/**
	 * Without recent agent use there is no evidence, whatever was completed.
	 *
	 * @return void
	 */
	public function testEvidenceWithoutRecentUse(): void {
		$this->complete('alice', 'org-a', 'one', 'two');
		$this->session('alice', 'org-a', '-120 days');
		$this->session('bob', 'org-b');
		$this->session('', 'org-a');

		$this->assertSame(
			['status' => 'unevidenced', 'detail' => 'Nobody in this organisation used an agent in the last 90 days.'],
			$this->report()->evidence('org-a')
		);

	}//end testEvidenceWithoutRecentUse()

	/**
	 * Everyone who used an agent recently completed: satisfied, each user counted once.
	 *
	 * @return void
	 */
	public function testEvidenceSatisfied(): void {
		$this->complete('alice', 'org-a', 'one', 'two');
		$this->session('alice', 'org-a');
		$this->session('alice', 'org-a', '-2 days');

		$this->assertSame(
			['status' => 'satisfied', 'detail' => '1 of 1 people who used an agent in the last 90 days completed the course.'],
			$this->report()->evidence('org-a')
		);

	}//end testEvidenceSatisfied()

	/**
	 * Some recent users completed: partial.
	 *
	 * @return void
	 */
	public function testEvidencePartial(): void {
		$this->complete('alice', 'org-a', 'one', 'two');
		$this->complete('bob', 'org-a', 'one');
		$this->session('alice', 'org-a');
		$this->session('bob', 'org-a');
		$this->session('carol', 'org-a');

		$this->assertSame(
			['status' => 'partial', 'detail' => '1 of 3 people who used an agent in the last 90 days completed the course.'],
			$this->report()->evidence('org-a')
		);

	}//end testEvidencePartial()

	/**
	 * Recent users but none completed: unevidenced with the count.
	 *
	 * @return void
	 */
	public function testEvidenceNoneCompleted(): void {
		$this->complete('bob', 'org-a', 'one');
		$this->session('bob', 'org-a');

		$this->assertSame(
			['status' => 'unevidenced', 'detail' => '0 of 1 people who used an agent in the last 90 days completed the course.'],
			$this->report()->evidence('org-a')
		);

	}//end testEvidenceNoneCompleted()

	/**
	 * Non-entity results and sessions without an update time are ignored.
	 *
	 * @return void
	 */
	public function testIgnoresNonEntitiesAndUndatedSessions(): void {
		$undated = new ObjectEntity();
		$undated->setObject(['userId' => 'alice']);
		$undated->setOrganisation('org-a');

		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturn([['userId' => 'alice'], $undated]);
		$course = $this->createMock(LiteracyCourse::class);
		$course->method('lessonCount')->willReturn(1);

		$report = new LiteracyReport($objects, $course);

		$this->assertSame('unevidenced', $report->evidence('org-a')['status']);
		$this->assertSame('Nobody in this organisation used an agent in the last 90 days.', $report->evidence('org-a')['detail']);

	}//end testIgnoresNonEntitiesAndUndatedSessions()
}//end class
