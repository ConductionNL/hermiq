<?php

/**
 * Runs a registered AI feature on a document the caller names.
 *
 * The generic surface an app calls from its own page, in the signed-in
 * person's session: the document is read in that person's Files, and every
 * pre-call gate (model policy, data use, residency, redaction) runs on that
 * document before any request leaves. A refusal is a 422 naming the gate, so
 * the app can say which step refused and why, rather than "AI error".
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-feature-run-on-a-document/specs/ai-feature-governance/spec.md#requirement-an-app-can-run-an-ai-feature-on-a-document-it-names-and-the-gates-apply-to-that-document
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\AiFeature\DocumentFeatureRun;
use OCA\Hermiq\Service\GuardrailBlockedException;
use OCA\Hermiq\Service\Literacy\LiteracyRequiredException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * POST /api/ai-features/{slug}/run-on-document.
 *
 * @spec openspec/changes/ai-feature-run-on-a-document/specs/ai-feature-governance/spec.md#requirement-an-app-can-run-an-ai-feature-on-a-document-it-names-and-the-gates-apply-to-that-document
 */
class AiFeatureRunController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request     The request.
	 * @param DocumentFeatureRun $run         Runs the feature.
	 * @param IUserSession       $userSession The signed-in person.
	 * @param LoggerInterface    $logger      Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly DocumentFeatureRun $run,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Run one feature on one document, as the signed-in person.
	 *
	 * @param string $slug The feature slug.
	 *
	 * @return JSONResponse `{feature, documentReference, output, notices}`, or an error naming the refusing gate.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/ai-feature-run-on-a-document/specs/ai-feature-governance/spec.md#requirement-an-app-can-run-an-ai-feature-on-a-document-it-names-and-the-gates-apply-to-that-document
	 */
	public function runOnDocument(string $slug): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(
				$this->run->run(
					uid: $user->getUID(),
					featureSlug: $slug,
					documentReference: (string)$this->request->getParam('documentReference', ''),
					instruction: (string)$this->request->getParam('instruction', '')
				)
			);
		} catch (GuardrailBlockedException $e) {
			return $this->refused(gate: 'guardrail', message: $e->getMessage());
		} catch (LiteracyRequiredException $e) {
			return new JSONResponse(
				['error' => $e->getMessage(), 'errorCode' => LiteracyRequiredException::ERROR_CODE, 'courseUrl' => LiteracyRequiredException::COURSE_PATH],
				Http::STATUS_FORBIDDEN
			);
		} catch (RuntimeException $e) {
			// Every pre-call gate (model policy, data use, residency, redaction)
			// refuses with an exception that names its step.
			if (method_exists($e, 'step') === true) {
				return $this->refused(gate: (string)$e->step(), message: $e->getMessage());
			}

			return $this->failure(exception: $e);
		}//end try
	}//end runOnDocument()

	/**
	 * A refusal by one of the pre-call gates.
	 *
	 * @param string $gate    The step that refused.
	 * @param string $message Its sentence.
	 *
	 * @return JSONResponse 422 naming the gate.
	 */
	private function refused(string $gate, string $message): JSONResponse {
		return new JSONResponse(['error' => $message, 'gate' => $gate], Http::STATUS_UNPROCESSABLE_ENTITY);
	}//end refused()

	/**
	 * A refusal or failure the service raised with an HTTP status as its code.
	 *
	 * @param RuntimeException $exception The exception.
	 *
	 * @return JSONResponse The error.
	 */
	private function failure(RuntimeException $exception): JSONResponse {
		$status = (int)$exception->getCode();
		// 503 is a provider that is not configured or not reachable.
		if (in_array($status, [400, 403, 404, 422, 503], true) === true) {
			return new JSONResponse(['error' => $exception->getMessage()], $status);
		}

		$this->logger->error('Hermiq document feature run failed: ' . $exception->getMessage(), ['exception' => $exception]);
		return new JSONResponse(['error' => 'The AI feature could not be run'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end failure()
}//end class
