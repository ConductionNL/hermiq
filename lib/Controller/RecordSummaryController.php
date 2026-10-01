<?php

/**
 * Hermiq RecordSummaryController.
 *
 * The record summary on the agent leaf (agents-bound-to-their-app, task 5):
 * `GET /api/assistant/summary` tells the leaf what it may show for a record
 * without calling a model, and `POST /api/assistant/summarise` returns the
 * summary, writing it only when the record changed since the stored one.
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
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use Exception;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Assistant\RecordSummaryService;
use OCA\Hermiq\Service\GuardrailBlockedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * RecordSummaryController serves the record summary to the agent leaf.
 *
 * Authorization is per record: the service reads the record as the caller, so
 * a record the caller cannot read answers 404 before any agent or model is
 * involved.
 *
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
 */
class RecordSummaryController extends Controller {

	/**
	 * The refusal codes passed through to the client as they are.
	 *
	 * @var array<int, int>
	 */
	private const KNOWN_REFUSALS = [
		Http::STATUS_BAD_REQUEST,
		Http::STATUS_UNAUTHORIZED,
		Http::STATUS_FORBIDDEN,
		Http::STATUS_NOT_FOUND,
		Http::STATUS_CONFLICT,
		Http::STATUS_UNPROCESSABLE_ENTITY,
		Http::STATUS_SERVICE_UNAVAILABLE,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param RecordSummaryService $summaries Reads, writes and keeps record summaries.
	 * @param IUserSession $userSession The signed-in user.
	 *
	 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
	 */
	public function __construct(
		IRequest $request,
		private readonly RecordSummaryService $summaries,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * What the leaf may show for a record: whether summaries are on, which
	 * agent would write one, and the stored summary while the record is unchanged.
	 *
	 * @return JSONResponse `{enabled, agent, summary}`, or `{error}` with the refusal's status.
	 *
	 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#scenario-the-summary-is-not-rewritten-until-the-record-changes
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		return $this->answer(
			work: fn (string $uid): array => $this->summaries->status(
				userId: $uid,
				register: $this->param(name: 'register'),
				schema: $this->param(name: 'schema'),
				objectId: $this->param(name: 'objectId')
			)
		);
	}//end show()

	/**
	 * The summary of a record, written by the app's assistant when the record
	 * changed since the stored summary.
	 *
	 * @return JSONResponse `{summary, generatedAt, objectVersion, agentId, agentName, cached}`, or `{error}`.
	 *
	 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#scenario-a-case-handler-reads-a-summary-of-a-long-application
	 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#scenario-no-summary-for-a-record-the-user-cannot-read
	 */
	#[NoAdminRequired]
	public function summarise(): JSONResponse {
		return $this->answer(
			work: fn (string $uid): array => $this->summaries->summarise(
				userId: $uid,
				register: $this->param(name: 'register'),
				schema: $this->param(name: 'schema'),
				objectId: $this->param(name: 'objectId')
			)
		);
	}//end summarise()

	/**
	 * Run the work as the signed-in user and map a refusal to its status.
	 *
	 * @param callable(string): array<string, mixed> $work The service call.
	 *
	 * @return JSONResponse
	 *
	 * @spec exclude shared response mapping of show() and summarise(), covered by their tests
	 */
	private function answer(callable $work): JSONResponse {
		$uid = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($uid === '') {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($work($uid));
		} catch (GuardrailBlockedException $e) {
			return new JSONResponse(
				['error' => $e->getMessage(), 'errorCode' => 'guardrail_blocked'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		} catch (Exception $e) {
			$status = (int)$e->getCode();
			if (in_array($status, self::KNOWN_REFUSALS, true) === false) {
				$status = Http::STATUS_INTERNAL_SERVER_ERROR;
			}

			return new JSONResponse(['error' => $e->getMessage()], $status);
		}
	}//end answer()

	/**
	 * One string request parameter, trimmed.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string
	 *
	 * @spec exclude request parsing helper, covered by the endpoint tests
	 */
	private function param(string $name): string {
		$value = $this->request->getParam($name, '');
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end param()
}//end class
