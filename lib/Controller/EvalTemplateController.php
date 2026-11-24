<?php

/**
 * Hermiq EvalTemplateController.
 *
 * "Use for this agent" (reuse-scheduled-bias-evals, REQ-BIASEVAL-002):
 * `POST /api/evals/{datasetId}/use` with `agentId` copies a seeded eval dataset
 * template into a new dataset the caller owns, linked to that agent.
 *
 * Security (OWASP A01 IDOR): `EvalTemplateCopier::copyForOwner()` reads the
 * template with the caller's rights and requires the caller to act as owner of the
 * agent. Either failing is the same 404, so the endpoint confirms nothing about
 * another person's agents. A dataset that is not a template is a 400: it can be
 * edited directly.
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
 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Service\Eval\EvalTemplateCopier;
use OCA\Hermiq\Kernel\AppIdentity;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Controller;
use OCP\IUserSession;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use Throwable;

/**
 * Copies an eval dataset template for one of the caller's agents.
 *
 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
 */
class EvalTemplateController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request     The request.
	 * @param IUserSession       $userSession The caller.
	 * @param EvalTemplateCopier $copier      Reads the template, checks the agent and stores the copy.
	 * @param LoggerInterface    $logger      Records a failed copy.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly EvalTemplateCopier $copier,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Copy the template for the agent named in the request body.
	 *
	 * @param string $datasetId The template's uuid.
	 *
	 * @return JSONResponse 201 with the new dataset's id and name; 400, 401, 404 or 500 otherwise.
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
	 */
	#[NoAdminRequired]
	public function use(string $datasetId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$copy = $this->copier->copyForOwner(
				datasetId: $datasetId,
				agentId: (string)$this->request->getParam('agentId', ''),
				uid: $user->getUID()
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq eval template copy failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'The test set could not be copied'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($copy === null) {
			// 404 (not 403): neither the template nor the agent is confirmed to exist.
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(
			['id' => (string)$copy->getUuid(), 'name' => (string)($copy->getObject()['name'] ?? '')],
			Http::STATUS_CREATED
		);
	}//end use()
}//end class
