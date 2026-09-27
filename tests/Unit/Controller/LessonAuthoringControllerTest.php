<?php

/**
 * Unit tests for LessonAuthoringController (lesson-authoring-ai-delegate).
 *
 * Asserts the auth-first, validate-before-engine shape (spec.md REQ-010): no
 * session is refused 401 before any parameter is read, a missing or
 * out-of-limit field is refused 400 before the engine runs, only allowlisted
 * fields ever reach the engine (REQ-002), a valid request returns the engine's
 * envelope verbatim, and every endpoint carries the rate limit.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
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

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\LessonAuthoringController;
use OCA\Hermiq\Service\LessonAuthoringEngine;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for the lesson-authoring REST endpoints' auth, allowlist and limits.
 *
 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-010-the-endpoints-authenticate-validate-and-rate-limit-before-the-gate
 */
class LessonAuthoringControllerTest extends TestCase {

	/**
	 * Controller method name to the engine method it delegates to.
	 *
	 * @var array<string, string>
	 */
	private const ENGINE_METHOD = [
		'outline' => 'draftOutline',
		'questions' => 'suggestQuestions',
		'simplify' => 'simplify',
		'goalSuggestions' => 'suggestGoals',
	];

	/**
	 * A session with the given (or no) user.
	 *
	 * @param string|null $uid The UID, or null for unauthenticated.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * A request returning the given params for getParam().
	 *
	 * @param array<string, mixed> $params Param name to value.
	 *
	 * @return IRequest
	 */
	private function request(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return array_key_exists($key, $params) ? $params[$key] : $default;
			}
		);

		return $request;
	}//end request()

	/**
	 * An engine double none of whose actions may run.
	 *
	 * @return LessonAuthoringEngine
	 */
	private function untouchedEngine(): LessonAuthoringEngine {
		$engine = $this->createMock(LessonAuthoringEngine::class);
		foreach (self::ENGINE_METHOD as $method) {
			$engine->expects($this->never())->method($method);
		}

		return $engine;
	}//end untouchedEngine()

	/**
	 * The controller under test.
	 *
	 * @param array<string, mixed> $params The request params.
	 * @param LessonAuthoringEngine $engine The engine double.
	 * @param string|null $uid The session user, or null.
	 * @param LoggerInterface|null $logger A logger double.
	 *
	 * @return LessonAuthoringController
	 */
	private function controller(
		array $params,
		LessonAuthoringEngine $engine,
		?string $uid = 'teacher1',
		?LoggerInterface $logger = null
	): LessonAuthoringController {
		return new LessonAuthoringController(
			request: $this->request(params: $params),
			userSession: $this->session(uid: $uid),
			engine: $engine,
			logger: $logger ?? $this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * The four endpoints.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function endpoints(): array {
		return [
			'outline' => ['outline'],
			'questions' => ['questions'],
			'simplify' => ['simplify'],
			'goal-suggestions' => ['goalSuggestions'],
		];
	}//end endpoints()

	/**
	 * No session is refused 401 and the engine never runs.
	 *
	 * @param string $endpoint The controller method.
	 *
	 * @return void
	 */
	#[DataProvider('endpoints')]
	public function testUnauthenticatedIsRefused(string $endpoint): void {
		$controller = $this->controller(
			params: ['lessonText' => 'Les', 'goalTitles' => ['Doel']],
			engine: $this->untouchedEngine(),
			uid: null
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->$endpoint()->getStatus());

	}//end testUnauthenticatedIsRefused()

	/**
	 * Every endpoint is callable without admin rights and carries the per-user rate limit.
	 *
	 * @param string $endpoint The controller method.
	 *
	 * @return void
	 */
	#[DataProvider('endpoints')]
	public function testEveryEndpointIsRateLimitedPerUser(string $endpoint): void {
		$method = new ReflectionMethod(LessonAuthoringController::class, $endpoint);

		$this->assertCount(1, $method->getAttributes(NoAdminRequired::class));

		$limits = $method->getAttributes(UserRateLimit::class);
		$this->assertCount(1, $limits);
		$this->assertSame(['limit' => 30, 'period' => 60], $limits[0]->getArguments());

	}//end testEveryEndpointIsRateLimitedPerUser()

	/**
	 * A missing or malformed field is a 400, and the engine never runs.
	 *
	 * @param string $endpoint The controller method.
	 * @param array<string, mixed> $params The request params.
	 *
	 * @return void
	 */
	#[DataProvider('badRequests')]
	public function testABadFieldIsRefused(string $endpoint, array $params): void {
		$response = $this->controller(params: $params, engine: $this->untouchedEngine())->$endpoint();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertNotSame('', (string)($response->getData()['error'] ?? ''));

	}//end testABadFieldIsRefused()

	/**
	 * Requests that break a required field or a limit.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function badRequests(): array {
		$lesson = 'Breuken vergelijken.';
		return [
			'outline without goals' => ['outline', ['lessonText' => $lesson]],
			'outline with an empty goal list' => ['outline', ['goalTitles' => []]],
			'questions without lesson text' => ['questions', ['goalTitles' => ['Doel']]],
			'questions with blank lesson text' => ['questions', ['lessonText' => "   \n "]],
			'questions with too many' => ['questions', ['lessonText' => $lesson, 'questionCount' => 50]],
			'questions with zero' => ['questions', ['lessonText' => $lesson, 'questionCount' => 0]],
			'questions with a fraction' => ['questions', ['lessonText' => $lesson, 'questionCount' => 2.5]],
			'questions with a bad language' => ['questions', ['lessonText' => $lesson, 'language' => 'Dutch please']],
			'simplify without lesson text' => ['simplify', ['readingLevel' => 'A2']],
			'simplify with an unknown level' => ['simplify', ['lessonText' => $lesson, 'readingLevel' => 'C2']],
			'simplify with lesson text as a list' => ['simplify', ['lessonText' => ['Les']]],
			'simplify with lesson text too long' => ['simplify', ['lessonText' => str_repeat('a', 20001)]],
			'goals without goal titles' => ['goalSuggestions', ['lessonText' => $lesson]],
			'goals without lesson text' => ['goalSuggestions', ['goalTitles' => ['Doel']]],
			'goals as a map' => ['goalSuggestions', ['lessonText' => $lesson, 'goalTitles' => ['a' => 'Doel']]],
			'goals with a number in it' => ['goalSuggestions', ['lessonText' => $lesson, 'goalTitles' => ['Doel', 7]]],
			'goals with a blank title' => ['goalSuggestions', ['lessonText' => $lesson, 'goalTitles' => ['Doel', '  ']]],
			'goals with a title too long' => ['goalSuggestions', ['lessonText' => $lesson, 'goalTitles' => [str_repeat('d', 301)]]],
			'goals with too many titles' => ['goalSuggestions', ['lessonText' => $lesson, 'goalTitles' => array_fill(0, 101, 'Doel')]],
		];
	}//end badRequests()

	/**
	 * A parameter outside the contract never reaches the engine, and defaults are applied.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-002-only-lesson-text-and-goal-titles-reach-the-model
	 */
	public function testAnExtraParameterNeverReachesTheEngine(): void {
		$engine = $this->createMock(LessonAuthoringEngine::class);
		$engine->expects($this->once())
			->method('suggestQuestions')
			->willReturnCallback(
				function (...$args): array {
					$this->assertSame(['Breuken vergelijken.', ['Doel'], 5, 'nl', 'teacher1'], $args);
					$this->assertStringNotContainsString('Kees', json_encode($args, JSON_THROW_ON_ERROR));
					return ['available' => true, 'questions' => ['Vraag?']];
				}
			);

		$response = $this->controller(
			params: [
				'lessonText' => '  Breuken vergelijken.  ',
				'goalTitles' => [' Doel '],
				'learnerName' => 'Kees Jansen',
				'learnerId' => 'pupil-42',
			],
			engine: $engine
		)->questions();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testAnExtraParameterNeverReachesTheEngine()

	/**
	 * Each valid request delegates to its engine method and returns the envelope verbatim.
	 *
	 * @param string $endpoint The controller method.
	 * @param array<string, mixed> $params The request params.
	 * @param array<int, mixed> $expectedArgs The positional arguments the engine must receive.
	 *
	 * @return void
	 */
	#[DataProvider('validRequests')]
	public function testAValidRequestReturnsTheEngineResult(string $endpoint, array $params, array $expectedArgs): void {
		$envelope = ['available' => true, 'action' => $endpoint, 'draft' => true];

		$engine = $this->createMock(LessonAuthoringEngine::class);
		$engine->expects($this->once())
			->method(self::ENGINE_METHOD[$endpoint])
			->with(...$expectedArgs)
			->willReturn($envelope);

		$response = $this->controller(params: $params, engine: $engine)->$endpoint();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($envelope, $response->getData());

	}//end testAValidRequestReturnsTheEngineResult()

	/**
	 * Valid requests and the engine arguments they must produce.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<int, mixed>}>
	 */
	public static function validRequests(): array {
		return [
			'outline with notes and a language' => [
				'outline',
				['goalTitles' => ['Breuken vergelijken'], 'lessonText' => 'Notities', 'language' => 'en-GB'],
				[['Breuken vergelijken'], 'Notities', 'en-GB', 'teacher1'],
			],
			'outline with defaults' => [
				'outline',
				['goalTitles' => ['Breuken vergelijken']],
				[['Breuken vergelijken'], '', 'nl', 'teacher1'],
			],
			'questions with a digit string count' => [
				'questions',
				['lessonText' => 'Les', 'questionCount' => '4', 'language' => 'nl-NL'],
				['Les', [], 4, 'nl-NL', 'teacher1'],
			],
			'simplify with a level' => [
				'simplify',
				['lessonText' => 'Les', 'readingLevel' => '1F'],
				['Les', '1F', 'teacher1'],
			],
			'simplify with the default level' => [
				'simplify',
				['lessonText' => 'Les'],
				['Les', 'B1', 'teacher1'],
			],
			'goal suggestions' => [
				'goalSuggestions',
				['lessonText' => 'Les', 'goalTitles' => ['Een', 'Twee']],
				['Les', ['Een', 'Twee'], 'teacher1'],
			],
		];
	}//end validRequests()

	/**
	 * The outline endpoint answers with the engine's draft envelope, unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-004-draft-a-lesson-outline-from-learning-goals
	 */
	public function testTheOutlineEndpointReturnsTheDraftEnvelope(): void {
		$envelope = [
			'available' => true,
			'action' => 'outline',
			'draft' => true,
			'draftNotice' => 'This is an AI-generated draft. Check and edit it before you use it.',
			'provider' => 'nextcloud',
			'draftText' => "1. Start\n2. Instructie",
		];

		$engine = $this->createMock(LessonAuthoringEngine::class);
		$engine->expects($this->once())
			->method('draftOutline')
			->with(['Breuken vergelijken'], '', 'nl', 'teacher1')
			->willReturn($envelope);

		$response = $this->controller(params: ['goalTitles' => ['Breuken vergelijken']], engine: $engine)->outline();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($envelope, $response->getData());

	}//end testTheOutlineEndpointReturnsTheDraftEnvelope()

	/**
	 * The goal-suggestions endpoint passes an unavailable envelope through as a 200, not an error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-007-suggest-which-given-goals-a-lesson-covers
	 */
	public function testTheGoalSuggestionsEndpointPassesUnavailableThroughAs200(): void {
		$envelope = ['available' => false, 'action' => 'goal-suggestions', 'reason' => 'feature-not-enabled'];

		$engine = $this->createMock(LessonAuthoringEngine::class);
		$engine->expects($this->once())
			->method('suggestGoals')
			->with('Les', ['Een', 'Twee'], 'teacher1')
			->willReturn($envelope);

		$response = $this->controller(
			params: ['lessonText' => 'Les', 'goalTitles' => ['Een', 'Twee']],
			engine: $engine
		)->goalSuggestions();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($envelope, $response->getData());

	}//end testTheGoalSuggestionsEndpointPassesUnavailableThroughAs200()

	/**
	 * An engine exception maps to a 500, and the log line carries no request content.
	 *
	 * @return void
	 */
	public function testAnEngineFailureMapsTo500WithoutLoggingContent(): void {
		$engine = $this->createMock(LessonAuthoringEngine::class);
		$engine->method('simplify')->willThrowException(new RuntimeException('register unavailable'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				$this->logicalAnd(
					$this->stringContains('register unavailable'),
					$this->logicalNot($this->stringContains('Kwartelkoning'))
				),
				$this->logicalNot($this->arrayHasKey('exception'))
			);

		$response = $this->controller(
			params: ['lessonText' => 'Kwartelkoning in de les.'],
			engine: $engine,
			logger: $logger
		)->simplify();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());

	}//end testAnEngineFailureMapsTo500WithoutLoggingContent()
}//end class
