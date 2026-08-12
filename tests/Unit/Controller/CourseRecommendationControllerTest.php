<?php

/**
 * Unit tests for CourseRecommendationController (ai-course-recommendations).
 *
 * Asserts the self-scope invariant (spec.md "Recommendation access is self-scoped
 * to the caller's own learner identity"): `learnerId` is ALWAYS resolved from
 * `IUserSession::getUser()->getUID()`, never from request input (this controller
 * takes no route/query parameters at all — there is nothing to inject), and an
 * unauthenticated caller is refused with 401 before the engine is ever invoked.
 * Also covers the happy path (delegates to the engine, returns its payload
 * verbatim) and that an engine failure maps to a 500 rather than an uncaught
 * exception.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-6-2
 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md#requirement-recommendation-access-is-self-scoped-to-the-callers-own-learner-identity
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\CourseRecommendationController;
use OCA\Hermiq\Service\CourseRecommendationEngine;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the self-scoped course-recommendation controller.
 *
 * @spec openspec/changes/ai-course-recommendations/tasks.md#task-6-2
 */
class CourseRecommendationControllerTest extends TestCase {

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
	 * Build the controller with the given collaborators.
	 *
	 * @param IUserSession $session The user session.
	 * @param CourseRecommendationEngine $engine The engine double.
	 *
	 * @return CourseRecommendationController
	 */
	private function controller(IUserSession $session, CourseRecommendationEngine $engine): CourseRecommendationController {
		return new CourseRecommendationController(
			$this->createMock(IRequest::class),
			$session,
			$engine,
			$this->createMock(LoggerInterface::class)
		);

	}//end controller()

	/**
	 * An unauthenticated caller is refused with 401 BEFORE the engine is invoked —
	 * no signal read, no scoring, no LLM call can happen for a request with no
	 * resolvable learner identity at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md#scenario-unauthenticated-callers-are-refused
	 */
	public function testUnauthenticatedCallerIsRefusedBeforeEngineInvocation(): void {
		$engine = $this->createMock(CourseRecommendationEngine::class);
		$engine->expects($this->never())->method('getOrRegenerate');

		$response = $this->controller($this->session(null), $engine)->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testUnauthenticatedCallerIsRefusedBeforeEngineInvocation()

	/**
	 * The engine is called with the ACTING session's own uid — never a
	 * caller-suppliable value, since index() takes no parameters at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-course-recommendations/specs/course-recommendations/spec.md#scenario-a-caller-cannot-request-another-learners-recommendations
	 */
	public function testLearnerIdIsAlwaysTheCallersOwnSessionUid(): void {
		$engine = $this->createMock(CourseRecommendationEngine::class);
		$engine->expects($this->once())
			->method('getOrRegenerate')
			->with($this->equalTo('bob'))
			->willReturn(['learnerId' => 'bob', 'status' => 'fresh']);

		$response = $this->controller($this->session('bob'), $engine)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('bob', $response->getData()['learnerId']);

	}//end testLearnerIdIsAlwaysTheCallersOwnSessionUid()

	/**
	 * The controller returns the engine's payload verbatim (thin shell — no
	 * re-shaping, no re-ranking, no second gate).
	 *
	 * @return void
	 */
	public function testReturnsTheEnginePayloadVerbatim(): void {
		$payload = [
			'learnerId' => 'carol',
			'status' => 'unavailable',
			'recommendations' => [],
		];
		$engine = $this->createMock(CourseRecommendationEngine::class);
		$engine->method('getOrRegenerate')->willReturn($payload);

		$response = $this->controller($this->session('carol'), $engine)->index();

		$this->assertSame($payload, $response->getData());

	}//end testReturnsTheEnginePayloadVerbatim()

	/**
	 * An engine failure is mapped to a 500 JSON error, never an uncaught exception.
	 *
	 * @return void
	 */
	public function testEngineFailureMapsTo500NotAnException(): void {
		$engine = $this->createMock(CourseRecommendationEngine::class);
		$engine->method('getOrRegenerate')->willThrowException(new RuntimeException('scholiq unreachable'));

		$response = $this->controller($this->session('dave'), $engine)->index();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());

	}//end testEngineFailureMapsTo500NotAnException()
}//end class
