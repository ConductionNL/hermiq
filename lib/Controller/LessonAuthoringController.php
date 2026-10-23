<?php

/**
 * Hermiq LessonAuthoringController (lesson-authoring-ai-delegate).
 *
 * The REST surface learniq calls for AI help while a teacher writes a lesson.
 * Authentication, the input allowlist and the limits live here; the gate, the
 * prompts, the provider call and the call log live in `LessonAuthoringEngine`,
 * mirroring `MessageTranslationController`'s thin auth and HTTP-mapping shell.
 *
 * Only allowlisted request keys are ever read, so a parameter outside the
 * contract (a pupil name, an id) is dropped here and can never reach a prompt.
 * CSRF stays on: the consumer is the teacher's browser, which sends the token
 * through `@nextcloud/axios`.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
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
 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-010-the-endpoints-authenticate-validate-and-rate-limit-before-the-gate
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use InvalidArgumentException;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\LessonAuthoringEngine;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Auth, allowlist and limits over LessonAuthoringEngine.
 *
 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-010-the-endpoints-authenticate-validate-and-rate-limit-before-the-gate
 */
class LessonAuthoringController extends Controller {

	/**
	 * The longest lesson text accepted, in characters after trimming.
	 *
	 * @var int
	 */
	public const MAX_LESSON_TEXT = 20000;

	/**
	 * The most goal titles accepted in one call.
	 *
	 * @var int
	 */
	public const MAX_GOALS = 100;

	/**
	 * The longest goal title accepted, in characters after trimming.
	 *
	 * @var int
	 */
	public const MAX_GOAL_TITLE = 300;

	/**
	 * The language used when the caller names none.
	 *
	 * @var string
	 */
	public const DEFAULT_LANGUAGE = 'nl';

	/**
	 * The question count used when the caller names none.
	 *
	 * @var int
	 */
	public const DEFAULT_QUESTION_COUNT = 5;

	/**
	 * A BCP-47 language tag: a 2 or 3 letter language, up to two subtags.
	 *
	 * @var string
	 */
	private const LANGUAGE_PATTERN = '/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8}){0,2}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param IUserSession $userSession Resolves the requesting user (401 if absent).
	 * @param LessonAuthoringEngine $engine The gated lesson authoring pipeline.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly LessonAuthoringEngine $engine,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Draft a lesson outline from `goalTitles`, optionally building on `lessonText`.
	 *
	 * @return JSONResponse The engine's envelope, or a 400/401/500 error.
	 *
	 * @no-admin-idor-exempt Looks up no caller-supplied id: lesson content in, draft out, no object to authorise.
	 *
	 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-004-draft-a-lesson-outline-from-learning-goals
	 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-010-the-endpoints-authenticate-validate-and-rate-limit-before-the-gate
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function outline(): JSONResponse {
		return $this->handle(
			action: LessonAuthoringEngine::ACTION_OUTLINE,
			read: fn (): array => [
				'goalTitles' => $this->required(value: $this->goalTitles(), field: 'goalTitles'),
				'lessonText' => $this->lessonText(),
				'language' => $this->language(),
			],
			call: fn (array $input, string $userId): array => $this->engine->draftOutline(
				goalTitles: $input['goalTitles'],
				lessonText: $input['lessonText'],
				language: $input['language'],
				userId: $userId
			)
		);
	}//end outline()

	/**
	 * Suggest `questionCount` questions about `lessonText`, optionally focused on `goalTitles`.
	 *
	 * @return JSONResponse The engine's envelope, or a 400/401/500 error.
	 *
	 * @no-admin-idor-exempt Looks up no caller-supplied id: lesson content in, draft out, no object to authorise.
	 *
	 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-005-suggest-questions-for-a-lesson
	 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-010-the-endpoints-authenticate-validate-and-rate-limit-before-the-gate
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function questions(): JSONResponse {
		return $this->handle(
			action: LessonAuthoringEngine::ACTION_QUESTIONS,
			read: fn (): array => [
				'lessonText' => $this->required(value: $this->lessonText(), field: 'lessonText'),
				'goalTitles' => $this->goalTitles(),
				'questionCount' => $this->questionCount(),
				'language' => $this->language(),
			],
			call: fn (array $input, string $userId): array => $this->engine->suggestQuestions(
				lessonText: $input['lessonText'],
				goalTitles: $input['goalTitles'],
				questionCount: $input['questionCount'],
				language: $input['language'],
				userId: $userId
			)
		);
	}//end questions()

	/**
	 * Rewrite `lessonText` at `readingLevel`, keeping its language.
	 *
	 * @return JSONResponse The engine's envelope, or a 400/401/500 error.
	 *
	 * @no-admin-idor-exempt Looks up no caller-supplied id: lesson content in, draft out, no object to authorise.
	 *
	 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-006-rewrite-a-text-at-a-lower-reading-level
	 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-010-the-endpoints-authenticate-validate-and-rate-limit-before-the-gate
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function simplify(): JSONResponse {
		return $this->handle(
			action: LessonAuthoringEngine::ACTION_SIMPLIFY,
			read: fn (): array => [
				'lessonText' => $this->required(value: $this->lessonText(), field: 'lessonText'),
				'readingLevel' => $this->readingLevel(),
			],
			call: fn (array $input, string $userId): array => $this->engine->simplify(
				lessonText: $input['lessonText'],
				readingLevel: $input['readingLevel'],
				userId: $userId
			)
		);
	}//end simplify()

	/**
	 * Suggest which of `goalTitles` the `lessonText` covers, by 0-based position.
	 *
	 * @return JSONResponse The engine's envelope, or a 400/401/500 error.
	 *
	 * @no-admin-idor-exempt Looks up no caller-supplied id: lesson content in, draft out, no object to authorise.
	 *
	 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-007-suggest-which-given-goals-a-lesson-covers
	 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-010-the-endpoints-authenticate-validate-and-rate-limit-before-the-gate
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function goalSuggestions(): JSONResponse {
		return $this->handle(
			action: LessonAuthoringEngine::ACTION_GOAL_SUGGESTIONS,
			read: fn (): array => [
				'lessonText' => $this->required(value: $this->lessonText(), field: 'lessonText'),
				'goalTitles' => $this->required(value: $this->goalTitles(), field: 'goalTitles'),
			],
			call: fn (array $input, string $userId): array => $this->engine->suggestGoals(
				lessonText: $input['lessonText'],
				goalTitles: $input['goalTitles'],
				userId: $userId
			)
		);
	}//end goalSuggestions()

	/**
	 * The requesting user's id, or null when there is no session.
	 *
	 * @return string|null
	 */
	private function currentUserId(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end currentUserId()

	/**
	 * Refuse a missing value: an empty string or an empty list.
	 *
	 * @param T $value The read value.
	 * @param string $field The request field it came from.
	 *
	 * @return T The value, unchanged.
	 *
	 * @throws InvalidArgumentException When the value is empty.
	 *
	 * @template T of string|array
	 */
	private function required(string|array $value, string $field): string|array {
		if ($value === '' || $value === []) {
			throw new InvalidArgumentException($field . ' is required');
		}

		return $value;
	}//end required()

	/**
	 * `lessonText`, trimmed; '' when absent or blank.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When it is not a string or is too long.
	 */
	private function lessonText(): string {
		$value = $this->request->getParam('lessonText', '');
		if ($value === null) {
			return '';
		}

		if (is_string($value) === false) {
			throw new InvalidArgumentException('lessonText must be a string');
		}

		$text = trim($value);
		if (mb_strlen($text) > self::MAX_LESSON_TEXT) {
			throw new InvalidArgumentException('lessonText must be at most ' . self::MAX_LESSON_TEXT . ' characters');
		}

		return $text;
	}//end lessonText()

	/**
	 * `goalTitles`, each trimmed; [] when absent.
	 *
	 * @return array<int, string>
	 *
	 * @throws InvalidArgumentException When it is not a list of non-empty strings within the limits.
	 */
	private function goalTitles(): array {
		$value = $this->request->getParam('goalTitles', []);
		if ($value === null) {
			return [];
		}

		if (is_array($value) === false || array_is_list($value) === false) {
			throw new InvalidArgumentException('goalTitles must be a list of strings');
		}

		if (count($value) > self::MAX_GOALS) {
			throw new InvalidArgumentException('goalTitles must hold at most ' . self::MAX_GOALS . ' items');
		}

		$titles = [];
		foreach ($value as $title) {
			if (is_string($title) === false || trim($title) === '') {
				throw new InvalidArgumentException('goalTitles must hold non-empty strings only');
			}

			if (mb_strlen(trim($title)) > self::MAX_GOAL_TITLE) {
				throw new InvalidArgumentException('each goal title must be at most ' . self::MAX_GOAL_TITLE . ' characters');
			}

			$titles[] = trim($title);
		}

		return $titles;
	}//end goalTitles()

	/**
	 * `language`, a BCP-47 tag; DEFAULT_LANGUAGE when absent.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When it is not a BCP-47 tag.
	 */
	private function language(): string {
		$value = $this->request->getParam('language', self::DEFAULT_LANGUAGE);
		if ($value === null || $value === '') {
			return self::DEFAULT_LANGUAGE;
		}

		if (is_string($value) === false || preg_match(self::LANGUAGE_PATTERN, $value) !== 1) {
			throw new InvalidArgumentException('language must be a BCP-47 language tag, for example "nl" or "en"');
		}

		return $value;
	}//end language()

	/**
	 * `questionCount`, 1 to MAX_QUESTIONS; DEFAULT_QUESTION_COUNT when absent.
	 *
	 * @return int
	 *
	 * @throws InvalidArgumentException When it is not a whole number in range.
	 */
	private function questionCount(): int {
		$value = $this->request->getParam('questionCount', self::DEFAULT_QUESTION_COUNT);
		if ($value === null || $value === '') {
			return self::DEFAULT_QUESTION_COUNT;
		}

		if (is_string($value) === true && ctype_digit($value) === true) {
			$value = (int)$value;
		}

		if (is_int($value) === false
			|| $value < LessonAuthoringEngine::MIN_QUESTIONS
			|| $value > LessonAuthoringEngine::MAX_QUESTIONS
		) {
			throw new InvalidArgumentException(
				'questionCount must be a whole number from ' . LessonAuthoringEngine::MIN_QUESTIONS
				. ' to ' . LessonAuthoringEngine::MAX_QUESTIONS
			);
		}

		return $value;
	}//end questionCount()

	/**
	 * `readingLevel`, one of the engine's levels; the engine default when absent.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When it is not a known level.
	 */
	private function readingLevel(): string {
		$value = $this->request->getParam('readingLevel', LessonAuthoringEngine::DEFAULT_READING_LEVEL);
		if ($value === null || $value === '') {
			return LessonAuthoringEngine::DEFAULT_READING_LEVEL;
		}

		if (is_string($value) === false || array_key_exists($value, LessonAuthoringEngine::READING_LEVELS) === false) {
			throw new InvalidArgumentException(
				'readingLevel must be one of ' . implode(', ', array_keys(LessonAuthoringEngine::READING_LEVELS))
			);
		}

		return $value;
	}//end readingLevel()

	/**
	 * Authenticate, read the allowlisted input, run the engine call, map the outcome.
	 *
	 * The user is resolved before any parameter is read, so an unauthenticated
	 * caller is refused 401 without touching the body. A validation failure is a
	 * 400 naming the field. The engine already turns provider failures into
	 * `provider-error`, so the 500 only covers what escapes it; that log line
	 * carries the exception class and message, never the request content.
	 *
	 * @param string $action The action, for the log line.
	 * @param callable(): array<string, mixed> $read Reads and validates the allowlisted fields.
	 * @param callable(array<string, mixed>, string): array<string, mixed> $call The engine call.
	 *
	 * @return JSONResponse The engine's envelope with 200, or a 400/401/500 error.
	 */
	private function handle(string $action, callable $read, callable $call): JSONResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$input = $read();
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		try {
			return new JSONResponse($call($input, $userId));
		} catch (Throwable $e) {
			$this->logger->error(
				message: 'Hermiq lesson-authoring ' . $action . ' request failed: ' . $e->getMessage(),
				context: ['exceptionClass' => get_class($e), 'file' => $e->getFile(), 'line' => $e->getLine()]
			);
			return new JSONResponse(
				['error' => 'Could not run the lesson authoring action'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end handle()
}//end class
