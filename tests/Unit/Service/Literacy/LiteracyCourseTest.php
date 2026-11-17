<?php

/**
 * Unit tests for LiteracyCourse over hand-built lessons: locale selection,
 * ordering, lesson versions, answering and completion counting.
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

use InvalidArgumentException;
use OCA\Hermiq\Service\Literacy\LiteracyCourse;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The lesson course.
 *
 * @covers \OCA\Hermiq\Service\Literacy\LiteracyCourse
 */
final class LiteracyCourseTest extends TestCase {

	/**
	 * The in-memory store.
	 *
	 * @var InMemoryObjects
	 */
	private InMemoryObjects $objects;

	/**
	 * Seed two English lessons (stored out of order) and one Dutch lesson.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new InMemoryObjects();
		$this->objects->put('literacylesson', self::lesson('second', 'en', 2, ['version' => 3]));
		$this->objects->put('literacylesson', self::lesson('first', 'en', 1, ['version' => 0]));
		$this->objects->put('literacylesson', self::lesson('eerste', 'nl', 1));

	}//end setUp()

	/**
	 * A lesson payload.
	 *
	 * @param string $slug The slug
	 * @param string $locale The locale
	 * @param int $order The order
	 * @param array<string, mixed> $extra Overrides
	 *
	 * @return array<string, mixed>
	 */
	private static function lesson(string $slug, string $locale, int $order, array $extra = []): array {
		return array_merge(
			[
				'slug' => $slug,
				'locale' => $locale,
				'order' => $order,
				'title' => 'Title ' . $slug,
				'body' => 'Body ' . $slug,
				'checkQuestion' => 'Question ' . $slug,
				'checkOptions' => [2 => 'a', 5 => 'b', 9 => 'c'],
				'checkAnswer' => 2,
				'explanation' => 'Because ' . $slug,
			],
			$extra
		);

	}//end lesson()

	/**
	 * The course under test.
	 *
	 * @param LoggerInterface|null $logger Optional logger
	 *
	 * @return LiteracyCourse
	 */
	private function course(?LoggerInterface $logger = null): LiteracyCourse {
		return new LiteracyCourse($this->objects, $logger ?? new NullLogger());

	}//end course()

	/**
	 * Store a completion.
	 *
	 * @param string $uid The person
	 * @param string $slug The lesson
	 * @param int $version The completed version
	 * @param string $locale The locale
	 *
	 * @return void
	 */
	private function complete(string $uid, string $slug, int $version, string $locale = 'en'): void {
		$this->objects->put(
			'literacycompletion',
			['userId' => $uid, 'lessonSlug' => $slug, 'lessonVersion' => $version, 'locale' => $locale],
			'org-a'
		);

	}//end complete()

	/**
	 * Lessons come back in order, with reindexed options, normalised versions and nothing done.
	 *
	 * @return void
	 */
	public function testLessonsAreOrderedAndNormalised(): void {
		$result = $this->course()->lessons(uid: 'bob', locale: 'en_GB');

		$this->assertSame(0, $result['done']);
		$this->assertSame(2, $result['total']);
		$this->assertSame(
			[
				'slug' => 'first',
				'order' => 1,
				'title' => 'Title first',
				'body' => 'Body first',
				'checkQuestion' => 'Question first',
				'checkOptions' => ['a', 'b', 'c'],
				'version' => 1,
				'done' => false,
			],
			$result['lessons'][0]
		);
		$this->assertSame('second', $result['lessons'][1]['slug']);
		$this->assertSame(3, $result['lessons'][1]['version']);

	}//end testLessonsAreOrderedAndNormalised()

	/**
	 * Dutch locales get the Dutch lessons; unknown locales fall back to English.
	 *
	 * @return void
	 */
	public function testLocaleSelection(): void {
		$course = $this->course();

		$this->assertSame(['eerste'], array_column($course->lessons(uid: 'bob', locale: 'NL-be')['lessons'], 'slug'));
		$this->assertSame(['first', 'second'], array_column($course->lessons(uid: 'bob', locale: 'fr')['lessons'], 'slug'));

	}//end testLocaleSelection()

	/**
	 * A lesson is done only once a completion reaches its current version, in the same locale.
	 *
	 * @return void
	 */
	public function testDoneNeedsTheCurrentVersionInTheSameLocale(): void {
		$this->complete('bob', 'first', 1);
		$this->complete('bob', 'second', 2);
		$this->complete('bob', 'second', 1);
		$this->complete('bob', 'eerste', 1, 'nl');
		$this->complete('carol', 'second', 3);

		$result = $this->course()->lessons(uid: 'bob', locale: 'en');
		$this->assertSame([true, false], array_column($result['lessons'], 'done'));
		$this->assertSame(1, $result['done']);

		$this->complete('bob', 'second', 3);
		$this->assertSame(2, $this->course()->lessons(uid: 'bob', locale: 'en')['done']);

	}//end testDoneNeedsTheCurrentVersionInTheSameLocale()

	/**
	 * A wrong answer returns the explanation and stores nothing.
	 *
	 * @return void
	 */
	public function testWrongAnswerExplainsAndStoresNothing(): void {
		$result = $this->course()->answer(uid: 'bob', organisation: 'org-a', slug: 'first', locale: 'en', choice: 1);

		$this->assertSame(['correct' => false, 'explanation' => 'Because first'], $result);
		$this->assertArrayNotHasKey('literacycompletion', $this->objects->bySchema);

	}//end testWrongAnswerExplainsAndStoresNothing()

	/**
	 * A wrong answer on a lesson without an explanation gets an empty one.
	 *
	 * @return void
	 */
	public function testWrongAnswerWithoutExplanation(): void {
		$this->objects->put('literacylesson', self::lesson('bare', 'en', 3, ['explanation' => null]));

		$result = $this->course()->answer(uid: 'bob', organisation: 'org-a', slug: 'bare', locale: 'en', choice: 0);

		$this->assertSame(['correct' => false, 'explanation' => ''], $result);

	}//end testWrongAnswerWithoutExplanation()

	/**
	 * A right answer stores a completion at the lesson's version, owned by the person.
	 *
	 * @return void
	 */
	public function testRightAnswerStoresCompletion(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with('[hermiq] AI literacy lesson {slug} completed', ['slug' => 'second']);

		$result = $this->course($logger)->answer(uid: 'bob', organisation: 'org-a', slug: 'second', locale: 'en-US', choice: 2);

		$this->assertSame(['correct' => true], $result);
		$this->assertCount(1, $this->objects->bySchema['literacycompletion']);
		$stored = $this->objects->bySchema['literacycompletion'][0];
		$this->assertSame('org-a', $stored->getOrganisation());
		$this->assertSame('bob', $stored->getOwner());
		$data = $stored->getObject();
		$this->assertSame('bob', $data['userId']);
		$this->assertSame('second', $data['lessonSlug']);
		$this->assertSame(3, $data['lessonVersion']);
		$this->assertSame('en', $data['locale']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $data['completedAt']);
		$this->assertSame(1, $this->course()->lessons(uid: 'bob', locale: 'en')['done']);

	}//end testRightAnswerStoresCompletion()

	/**
	 * A lesson that does not exist in the requested language is refused.
	 *
	 * @return void
	 */
	public function testUnknownLessonIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Unknown lesson 'eerste'.");

		$this->course()->answer(uid: 'bob', organisation: 'org-a', slug: 'eerste', locale: 'en', choice: 2);

	}//end testUnknownLessonIsRefused()

	/**
	 * doneCount ignores stale versions and unknown slugs, counts each lesson once and defaults to English.
	 *
	 * @return void
	 */
	public function testDoneCount(): void {
		$course = $this->course();

		$this->assertSame(
			2,
			$course->doneCount(
				completions: [
					['lessonSlug' => 'first', 'lessonVersion' => 1],
					['lessonSlug' => 'first', 'lessonVersion' => 1, 'locale' => 'en'],
					['lessonSlug' => 'second', 'lessonVersion' => 2, 'locale' => 'en'],
					['lessonSlug' => 'unknown', 'lessonVersion' => 9, 'locale' => 'en'],
					['lessonSlug' => 'eerste', 'lessonVersion' => 1, 'locale' => 'nl'],
				]
			)
		);
		$this->assertSame(0, $course->doneCount(completions: []));

	}//end testDoneCount()

	/**
	 * The lesson count is the English set.
	 *
	 * @return void
	 */
	public function testLessonCountIsEnglish(): void {
		$this->assertSame(2, $this->course()->lessonCount());

	}//end testLessonCountIsEnglish()

	/**
	 * The course is complete once every English lesson is done at its current version.
	 *
	 * @return void
	 */
	public function testIsComplete(): void {
		$this->complete('bob', 'first', 1);
		$this->assertFalse($this->course()->isComplete('bob'));

		$this->complete('bob', 'second', 3);
		$this->assertTrue($this->course()->isComplete('bob'));
		$this->assertFalse($this->course()->isComplete('carol'));

	}//end testIsComplete()

	/**
	 * Without any lessons nobody is complete.
	 *
	 * @return void
	 */
	public function testNoLessonsIsNeverComplete(): void {
		$this->objects = new InMemoryObjects();

		$this->assertSame(0, $this->course()->lessonCount());
		$this->assertFalse($this->course()->isComplete('bob'));

	}//end testNoLessonsIsNeverComplete()

	/**
	 * Results that are not object entities, or belong to someone else, are ignored.
	 *
	 * @return void
	 */
	public function testForeignResultsAreIgnored(): void {
		$lesson = new ObjectEntity();
		$lesson->setObject(self::lesson('only', 'en', 1));
		$otherLocale = new ObjectEntity();
		$otherLocale->setObject(self::lesson('other', 'nl', 1));
		$completion = new ObjectEntity();
		$completion->setObject(['userId' => 'bob', 'lessonSlug' => 'only', 'lessonVersion' => 1, 'locale' => 'en']);
		$foreign = new ObjectEntity();
		$foreign->setObject(['userId' => 'carol', 'lessonSlug' => 'only', 'lessonVersion' => 1, 'locale' => 'en']);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$schema = '';
		$objects->method('setSchema')->willReturnCallback(
			static function (mixed $value) use (&$schema, $objects): ObjectService {
				$schema = (string)$value;
				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			static function () use (&$schema, $lesson, $otherLocale, $completion, $foreign): array {
				if ($schema === 'literacylesson') {
					return [['slug' => 'raw'], $lesson, $otherLocale];
				}

				return [['userId' => 'bob'], $foreign, $completion];
			}
		);

		$course = new LiteracyCourse($objects, new NullLogger());

		$result = $course->lessons(uid: 'bob', locale: 'en');
		$this->assertSame(['only'], array_column($result['lessons'], 'slug'));
		$this->assertSame(1, $result['done']);
		$this->assertTrue($course->isComplete('bob'));

	}//end testForeignResultsAreIgnored()
}//end class
