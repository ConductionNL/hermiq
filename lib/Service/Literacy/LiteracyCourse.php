<?php

/**
 * Hermiq course Working with AI (compliance-ai-literacy).
 *
 * The lessons in the reader's language with their done state, the check of an
 * answer, and whether a person finished the current version of every lesson.
 * A lesson counts as done when the person answered it right for a version at
 * least as new as the lesson's current version in that language, so an edit
 * that raises the version asks the lesson again. The right answer never leaves
 * this class.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Literacy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Literacy;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Lessons, answers and completion.
 *
 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
 */
class LiteracyCourse {

	public const REGISTER_SLUG = 'hermiq';

	public const LESSON_SCHEMA = 'literacylesson';

	public const COMPLETION_SCHEMA = 'literacycompletion';

	/**
	 * The languages the course ships in; anything else reads English.
	 *
	 * @var array<int, string>
	 */
	private const LOCALES = ['en', 'nl'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objects OpenRegister's object service.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The lessons in a language, with the person's done state and progress.
	 *
	 * @param string $uid The person.
	 * @param string $locale Their language (e.g. nl, nl_NL, en).
	 *
	 * @return array{lessons: array<int, array<string, mixed>>, done: int, total: int}
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
	 */
	public function lessons(string $uid, string $locale): array {
		$language = $this->language(locale: $locale);
		$completed = $this->completedVersions(uid: $uid, locale: $language);

		$lessons = [];
		foreach ($this->lessonsIn(locale: $language) as $lesson) {
			$slug = (string)$lesson['slug'];
			$lessons[] = [
				'slug' => $slug,
				'order' => (int)($lesson['order'] ?? 0),
				'title' => (string)$lesson['title'],
				'body' => (string)$lesson['body'],
				'checkQuestion' => (string)$lesson['checkQuestion'],
				'checkOptions' => array_values((array)$lesson['checkOptions']),
				'version' => $this->version(lesson: $lesson),
				'done' => ($completed[$slug] ?? 0) >= $this->version(lesson: $lesson),
			];
		}

		$done = count(array_filter(array_column($lessons, 'done')));

		return ['lessons' => $lessons, 'done' => $done, 'total' => count($lessons)];
	}//end lessons()

	/**
	 * Check an answer; a right one records the person's completion of this version.
	 *
	 * @param string $uid The person.
	 * @param string $organisation The organisation they work in.
	 * @param string $slug The lesson.
	 * @param string $locale Their language.
	 * @param int $choice The zero-based index of the chosen option.
	 *
	 * @return array<string, mixed> `correct`, and `explanation` when wrong, plus progress.
	 *
	 * @throws InvalidArgumentException When the lesson does not exist.
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
	 */
	public function answer(string $uid, string $organisation, string $slug, string $locale, int $choice): array {
		$language = $this->language(locale: $locale);
		$lesson = ($this->lessonsIn(locale: $language)[$slug] ?? null);
		if ($lesson === null) {
			throw new InvalidArgumentException("Unknown lesson '{$slug}'.");
		}

		if ($choice !== (int)$lesson['checkAnswer']) {
			return ['correct' => false, 'explanation' => (string)($lesson['explanation'] ?? '')];
		}

		$this->objects->saveObject(
			object: [
				'userId' => $uid,
				'lessonSlug' => $slug,
				'lessonVersion' => $this->version(lesson: $lesson),
				'locale' => $language,
				'completedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
				'@self' => ['organisation' => $organisation, 'owner' => $uid],
			],
			register: self::REGISTER_SLUG,
			schema: self::COMPLETION_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);
		$this->logger->info('[hermiq] AI literacy lesson {slug} completed', ['slug' => $slug]);

		return ['correct' => true];
	}//end answer()

	/**
	 * Whether a person finished the current version of every lesson, in whichever
	 * language they read each one.
	 *
	 * @param string $uid The person.
	 *
	 * @return bool True when all lessons are done.
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	public function isComplete(string $uid): bool {
		$completions = [];
		$objects = $this->objects->setRegister(self::REGISTER_SLUG)->setSchema(self::COMPLETION_SCHEMA)
			->findAll(config: ['filters' => ['userId' => $uid], 'limit' => 1000], _rbac: false, _multitenancy: false);
		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity && ($object->getObject()['userId'] ?? '') === $uid) {
				$completions[] = $object->getObject();
			}
		}

		$total = $this->lessonCount();

		return $total > 0 && $this->doneCount(completions: $completions) === $total;
	}//end isComplete()

	/**
	 * How many current lessons a set of completions covers, per the language each
	 * completion was made in.
	 *
	 * @param array<int, array<string, mixed>> $completions Completion data of one person.
	 *
	 * @return int The number of lessons done.
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-the-ai-act-article-4-control-reads-its-status-from-completions-req-ailit-003
	 */
	public function doneCount(array $completions): int {
		$done = [];
		foreach ($completions as $completion) {
			$locale = $this->language(locale: (string)($completion['locale'] ?? 'en'));
			$lesson = ($this->lessonsIn(locale: $locale)[(string)($completion['lessonSlug'] ?? '')] ?? null);
			if ($lesson !== null && (int)($completion['lessonVersion'] ?? 0) >= $this->version(lesson: $lesson)) {
				$done[(string)$completion['lessonSlug']] = true;
			}
		}

		return count($done);
	}//end doneCount()

	/**
	 * The number of lessons in the course.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-the-ai-act-article-4-control-reads-its-status-from-completions-req-ailit-003
	 */
	public function lessonCount(): int {
		return count($this->lessonsIn(locale: 'en'));
	}//end lessonCount()

	/**
	 * The lessons of one language, keyed by slug, in course order.
	 *
	 * @param string $locale A supported language.
	 *
	 * @return array<string, array<string, mixed>> The lessons.
	 */
	private function lessonsIn(string $locale): array {
		$out = [];
		$objects = $this->objects->setRegister(self::REGISTER_SLUG)->setSchema(self::LESSON_SCHEMA)
			->findAll(config: ['filters' => ['locale' => $locale], 'limit' => 100], _rbac: false, _multitenancy: false);
		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity && ($object->getObject()['locale'] ?? '') === $locale) {
				$out[(string)$object->getObject()['slug']] = $object->getObject();
			}
		}

		uasort($out, static fn (array $a, array $b): int => (int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0));

		return $out;
	}//end lessonsIn()

	/**
	 * The highest version a person completed, per lesson, in one language.
	 *
	 * @param string $uid The person.
	 * @param string $locale A supported language.
	 *
	 * @return array<string, int> Version by lesson slug.
	 */
	private function completedVersions(string $uid, string $locale): array {
		$versions = [];
		$objects = $this->objects->setRegister(self::REGISTER_SLUG)->setSchema(self::COMPLETION_SCHEMA)
			->findAll(config: ['filters' => ['userId' => $uid], 'limit' => 1000], _rbac: false, _multitenancy: false);
		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			$data = $object->getObject();
			if (($data['userId'] ?? '') !== $uid || ($data['locale'] ?? '') !== $locale) {
				continue;
			}

			$slug = (string)($data['lessonSlug'] ?? '');
			$versions[$slug] = max(($versions[$slug] ?? 0), (int)($data['lessonVersion'] ?? 0));
		}

		return $versions;
	}//end completedVersions()

	/**
	 * A lesson's version, 1 when unset.
	 *
	 * @param array<string, mixed> $lesson The lesson data.
	 *
	 * @return int The version.
	 */
	private function version(array $lesson): int {
		return max(1, (int)($lesson['version'] ?? 1));
	}//end version()

	/**
	 * The supported language for a locale, English when unsupported.
	 *
	 * @param string $locale A locale such as nl_NL.
	 *
	 * @return string en or nl.
	 */
	private function language(string $locale): string {
		$language = strtolower(substr($locale, 0, 2));
		if (in_array($language, self::LOCALES, true) === true) {
			return $language;
		}

		return 'en';
	}//end language()

}//end class
