<?php

/**
 * Hermiq CourseRecommendationController (ai-course-recommendations).
 *
 * The self-scoped REST surface for a learner's own ranked next-best-course
 * recommendations. `learnerId` is resolved EXCLUSIVELY from `IUserSession` — never
 * from request input — so a caller can only ever request their own recommendations
 * (spec.md "Recommendation access is self-scoped"). All gating (AiFeature DPO-ack,
 * tenant kill-switch, Scholiq-installed) and the deterministic-ranking/optional-LLM
 * pipeline live in `CourseRecommendationEngine`; this controller is a thin
 * auth + HTTP-mapping shell, mirroring `AiFeatureController`'s 401-first pattern.
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
 * @spec openspec/changes/archive/2026-07-16-ai-course-recommendations/tasks.md#3-controller-routes-self-scoped-no-action-matrix-gate-needed
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\CourseRecommendationEngineInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Self-scoped course-recommendation read endpoint.
 *
 * @spec openspec/changes/archive/2026-07-16-ai-course-recommendations/tasks.md#task-3-1
 */
class CourseRecommendationController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param IUserSession $userSession Resolves the requesting user (self-scope).
	 * @param CourseRecommendationEngineInterface $engine The gated ranking/explanation pipeline.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly CourseRecommendationEngineInterface $engine,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the caller's current CourseRecommendation, regenerating via the
	 * engine when missing or past `staleAt`. `learnerId` is always the caller's
	 * own uid — never read from `$this->request`.
	 *
	 * @return JSONResponse The recommendation payload, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/archive/2026-07-16-ai-course-recommendations/tasks.md#task-3-1
	 * @spec openspec/specs/course-recommendations/spec.md#requirement-recommendation-access-is-self-scoped-to-the-callers-own-learner-identity
	 */
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->engine->getOrRegenerate(learnerUid: $user->getUID());
			return new JSONResponse($result);
		} catch (Throwable $e) {
			$this->logger->error(
				'Hermiq course-recommendation read failed: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(
				['error' => 'Could not load course recommendations'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

	}//end index()
}//end class
