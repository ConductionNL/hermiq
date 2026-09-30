<?php

/**
 * Tests for Working with AI (compliance-ai-literacy): the lessons, completion,
 * the organisation's requirement and its guard, the admin report and the
 * article 4 evidence. Payloads written into OpenRegister are validated against
 * the real register fragments with Opis.
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
use InvalidArgumentException;
use OCA\Hermiq\Repair\SeedLiteracyLessons;
use OCA\Hermiq\Service\Literacy\LiteracyCourse;
use OCA\Hermiq\Service\Literacy\LiteracyReport;
use OCA\Hermiq\Service\Literacy\LiteracyRequiredException;
use OCA\Hermiq\Service\Literacy\LiteracyRequirement;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Working with AI.
 *
 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
 */
final class LiteracyTest extends TestCase {

	private InMemoryObjects $objects;

	/**
	 * Seed the six lessons in both languages through the real seeder.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new InMemoryObjects();
		$this->seed();

	}//end setUp()

	/**
	 * Run the lesson seeder over the in-memory store.
	 *
	 * @return void
	 */
	private function seed(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ObjectService::class)->willReturn($this->objects);
		(new SeedLiteracyLessons($container, new NullLogger()))->run($this->createMock(IOutput::class));

	}//end seed()

	/**
	 * The course service.
	 *
	 * @return LiteracyCourse
	 */
	private function course(): LiteracyCourse {
		return new LiteracyCourse($this->objects, new NullLogger());

	}//end course()

	/**
	 * The requirement service, with alice owning org-a and nobody an instance admin.
	 *
	 * @return LiteracyRequirement
	 */
	private function requirement(): LiteracyRequirement {
		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('getActiveOrganisationWithFallback')->willReturn('org-a');
		$organisations->method('findByUuid')->willReturnCallback(
			static function (string $uuid): Organisation {
				$organisation = new Organisation();
				$organisation->setUuid($uuid);
				$organisation->setOwner($uuid === 'org-a' ? 'alice' : 'someone-else');
				return $organisation;
			}
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);

		return new LiteracyRequirement($this->objects, $this->course(), $organisations, $groups);

	}//end requirement()

	/**
	 * A user double.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;

	}//end user()

	/**
	 * Answer every lesson right for a person.
	 *
	 * @param string $uid The person.
	 * @param string $organisation Their organisation.
	 *
	 * @return void
	 */
	private function completeAll(string $uid, string $organisation = 'org-a'): void {
		$course = $this->course();
		foreach ($course->lessons(uid: $uid, locale: 'en')['lessons'] as $lesson) {
			$course->answer(uid: $uid, organisation: $organisation, slug: $lesson['slug'], locale: 'en', choice: 1);
		}

	}//end completeAll()

	/**
	 * Seeding twice leaves twelve lessons, and an admin's edit survives a reseed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
	 */
	public function testSeedingIsIdempotentAndKeepsAdminEdits(): void {
		$this->assertCount(12, $this->objects->bySchema['literacylesson']);

		$edited = $this->objects->bySchema['literacylesson'][0];
		$edited->setObject(array_merge($edited->getObject(), ['title' => 'Edited by an admin', 'version' => 2]));
		$this->seed();

		$this->assertCount(12, $this->objects->bySchema['literacylesson']);
		$this->assertSame('Edited by an admin', $this->objects->bySchema['literacylesson'][0]->getObject()['title']);
		$this->assertSame(['en' => 6, 'nl' => 6], array_count_values(array_map(static fn ($l) => $l->getObject()['locale'], $this->objects->bySchema['literacylesson'])));

	}//end testSeedingIsIdempotentAndKeepsAdminEdits()

	/**
	 * Every seeded lesson and a completion validate against the real register
	 * fragments, and the lesson text keeps to the house style (no em dashes).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
	 */
	public function testPayloadsMatchTheRegisterSchemas(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/hermiq_register.json'));
		$validator = new Validator();

		foreach ($this->objects->bySchema['literacylesson'] as $lesson) {
			$payload = json_decode((string)json_encode($lesson->getObject()));
			$result = $validator->validate($payload, $register->components->schemas->LiteracyLesson);
			$this->assertTrue($result->isValid(), 'Lesson ' . $lesson->getObject()['slug'] . ' does not match LiteracyLesson.');
			$this->assertStringNotContainsString('—', (string)json_encode($lesson->getObject(), JSON_UNESCAPED_UNICODE));
		}

		$this->course()->answer(uid: 'bob', organisation: 'org-a', slug: 'check-the-sources', locale: 'en', choice: 1);
		$completion = $this->objects->bySchema['literacycompletion'][0];
		$this->assertTrue(
			$validator->validate(json_decode((string)json_encode($completion->getObject())), $register->components->schemas->LiteracyCompletion)->isValid()
		);
		$this->assertSame('org-a', $completion->getOrganisation());
		$this->assertTrue(isset($register->components->schemas->LiteracyCompletion->authorization->read), 'LiteracyCompletion declares its own read rule.');
		$this->assertTrue(isset($register->components->schemas->LiteracyLesson->authorization->read), 'LiteracyLesson declares its own read rule.');

	}//end testPayloadsMatchTheRegisterSchemas()

	/**
	 * A right answer records the lesson for that version; a wrong one explains
	 * why and records nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
	 */
	public function testANewCaseHandlerCompletesALesson(): void {
		$course = $this->course();

		$wrong = $course->answer(uid: 'bob', organisation: 'org-a', slug: 'check-the-sources', locale: 'en', choice: 0);
		$this->assertFalse($wrong['correct']);
		$this->assertSame('Asking again gives you another guess, not a check. Look the figure up where it is kept.', $wrong['explanation']);
		$this->assertArrayNotHasKey('literacycompletion', $this->objects->bySchema);

		$right = $course->answer(uid: 'bob', organisation: 'org-a', slug: 'check-the-sources', locale: 'en', choice: 1);
		$this->assertTrue($right['correct']);

		$page = $course->lessons(uid: 'bob', locale: 'en');
		$this->assertSame(1, $page['done']);
		$this->assertSame(6, $page['total']);
		$done = array_column($page['lessons'], 'done', 'slug');
		$this->assertTrue($done['check-the-sources']);
		$this->assertArrayNotHasKey('checkAnswer', $page['lessons'][0], 'The page never carries the answer.');

		$this->assertSame('Controleer de bronnen voordat je een antwoord gebruikt', array_column($course->lessons(uid: 'bob', locale: 'nl')['lessons'], 'title', 'slug')['check-the-sources']);

		$this->expectException(InvalidArgumentException::class);
		$course->answer(uid: 'bob', organisation: 'org-a', slug: 'no-such-lesson', locale: 'en', choice: 1);

	}//end testANewCaseHandlerCompletesALesson()

	/**
	 * An edit raises the version, and the lesson reads as not done again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
	 */
	public function testAChangedLessonIsAskedAgain(): void {
		$course = $this->course();
		$course->answer(uid: 'bob', organisation: 'org-a', slug: 'what-not-to-type', locale: 'en', choice: 1);

		foreach ($this->objects->bySchema['literacylesson'] as $lesson) {
			$data = $lesson->getObject();
			if ($data['slug'] === 'what-not-to-type' && $data['locale'] === 'en') {
				$lesson->setObject(array_merge($data, ['version' => 2]));
			}
		}

		$done = array_column($course->lessons(uid: 'bob', locale: 'en')['lessons'], 'done', 'slug');
		$this->assertFalse($done['what-not-to-type']);

	}//end testAChangedLessonIsAskedAgain()

	/**
	 * With the requirement on, a person with 3 of 6 done is refused with the
	 * course message; after all six they pass; the organisation owner may switch
	 * it, a stranger may not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	public function testAPersonWhoSkippedTheCourseIsSentToIt(): void {
		$requirement = $this->requirement();
		$requirement->assertMayUseAgents(uid: 'bob');

		$requirement->setRequired(organisation: 'org-a', required: true, actor: $this->user('alice'));
		$this->assertTrue($requirement->isRequired(organisation: 'org-a'));

		$course = $this->course();
		foreach (['what-an-agent-is', 'answers-can-be-wrong', 'check-the-sources'] as $slug) {
			$course->answer(uid: 'bob', organisation: 'org-a', slug: $slug, locale: 'en', choice: 1);
		}

		try {
			$requirement->assertMayUseAgents(uid: 'bob');
			$this->fail('3 of 6 done must be refused');
		} catch (LiteracyRequiredException $e) {
			$this->assertSame('Finish the short course Working with AI first.', $e->getMessage());
			$this->assertSame('ai_literacy_required', LiteracyRequiredException::ERROR_CODE);
		}

		// Lessons read in Dutch count as well: the guard does not ask a language.
		foreach (['what-not-to-type', 'when-a-person-decides', 'rights-and-duties'] as $slug) {
			$course->answer(uid: 'bob', organisation: 'org-a', slug: $slug, locale: 'nl_NL', choice: 1);
		}

		$requirement->assertMayUseAgents(uid: 'bob');
		$this->assertNull($requirement->refusal(uid: 'bob'));
		$this->assertSame('ai_literacy_required', $requirement->refusal(uid: 'carol')['errorCode']);

		$this->expectException(RuntimeException::class);
		$requirement->setRequired(organisation: 'org-a', required: false, actor: $this->user('mallory'));

	}//end testAPersonWhoSkippedTheCourseIsSentToIt()

	/**
	 * The admin report lists each person with their count, and exports as CSV.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	public function testTheAdminSeesCompletionPerPerson(): void {
		$this->completeAll('alice');
		$this->course()->answer(uid: 'bob', organisation: 'org-a', slug: 'check-the-sources', locale: 'en', choice: 1);
		$this->completeAll('carol', 'org-b');

		$report = new LiteracyReport($this->objects, $this->course());
		$rows = array_column($report->overview(organisation: 'org-a'), null, 'userId');

		$this->assertSame(['alice', 'bob'], array_keys($rows));
		$this->assertTrue($rows['alice']['complete']);
		$this->assertSame(1, $rows['bob']['done']);
		$this->assertSame(
			"userId,done,total,complete\nalice,6,6,yes\nbob,1,6,no\n",
			$report->csv(organisation: 'org-a')
		);

	}//end testTheAdminSeesCompletionPerPerson()

	/**
	 * Article 4: every recent agent user complete is satisfied, some is partial
	 * with the counts, none is unevidenced; people who did not use an agent in
	 * the last 90 days do not count.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-the-ai-act-article-4-control-reads-its-status-from-completions-req-ailit-003
	 */
	public function testTheArticle4ControlReadsCompletions(): void {
		$report = new LiteracyReport($this->objects, $this->course());

		$this->assertSame('unevidenced', $report->evidence(organisation: 'org-a')['status']);

		foreach (['alice', 'bob', 'carol', 'dave'] as $uid) {
			$this->objects->put('agentsession', ['userId' => $uid, 'agentId' => 'a1'], 'org-a');
		}

		$this->objects->put('agentsession', ['userId' => 'erin', 'agentId' => 'a1'], 'org-a', null, new DateTime('-120 days'));

		$this->assertSame('unevidenced', $report->evidence(organisation: 'org-a')['status']);

		foreach (['alice', 'bob', 'carol'] as $uid) {
			$this->completeAll($uid);
		}

		$partial = $report->evidence(organisation: 'org-a');
		$this->assertSame('partial', $partial['status']);
		$this->assertSame('3 of 4 people who used an agent in the last 90 days completed the course.', $partial['detail']);

		$this->completeAll('dave');
		$this->assertSame('satisfied', $report->evidence(organisation: 'org-a')['status']);

	}//end testTheArticle4ControlReadsCompletions()

}//end class
