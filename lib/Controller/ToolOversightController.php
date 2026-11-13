<?php

/**
 * Hermiq ToolOversightController (agent-tool-governance-and-disclosure).
 *
 * Three read/write endpoints over the ADR-063 consumer-side governance layer:
 *
 * - `GET tool-catalog`     — the grant-annotated derived catalog for the grant
 *   editor (design.md §"API Design").
 * - `PUT tool-grants`      — persists `Agent.tools` (owner-gated, single write-path
 *   via `ObjectService`).
 * - `GET tool-invocations` — the per-agent oversight surface (EU AI Act art.12/14),
 *   sourced from OpenRegister's MCP invocation AuditTrail entries when available,
 *   degrading gracefully to the coarser `run`-action entries `run-audit-log`
 *   already reads when the richer shape is absent (LIMITATION, carried from the
 *   cross-repo brief: OR's audit entry records the ambient NC session user, not an
 *   agent principal, so "per-agent" here is CORRELATED via this agent's own owner
 *   + its schedules' owners — not a first-class agent-identity column upstream).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-5-tooloversightcontroller-routes-catalog-grants-invocations
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use DateTimeImmutable;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\Agent\GrantWaiverAudit;
use OCA\Hermiq\Service\ToolAccessDecision;
use OCA\Hermiq\Service\ToolInvocationRows;
use OCA\Hermiq\Service\ToolOversight\ToolOversightServices;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tool-catalog / tool-grants / tool-invocations endpoints.
 *
 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-5-tooloversightcontroller-routes-catalog-grants-invocations
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The collaborators sit behind one
 *   injected bundle; what remains is the OR entity and audit types, the HTTP response
 *   types and the request-decision value objects the endpoints shape their answers with.
 */
class ToolOversightController extends Controller {

	/**
	 * AuditTrail action recorded when an approval waiver is added or removed.
	 *
	 * Its OWN action, never folded into a general grant-change record: this is
	 * the one grant edit that takes a human out of the loop, and it is a single
	 * fragment away from the un-waived entry in any textual diff.
	 *
	 * @var string
	 */
	public const WAIVER_AUDIT_ACTION = GrantWaiverAudit::ACTION;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param ToolOversightServices $services The agent lookup, catalogue, invocation log and grant writes.
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-5-tooloversightcontroller-routes-catalog-grants-invocations
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ToolOversightServices $services,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The grant-annotated derived catalog for the grant editor.
	 *
	 * @param string $agentId Agent UUID.
	 *
	 * @return JSONResponse The catalog payload, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/design.md#api-design
	 * @spec openspec/specs/agent-capability-reach/spec.md#scenario-the-reach-of-every-catalogue-entry-is-readable-through-the-tool-catalogue-api
	 */
	public function toolCatalog(string $agentId): JSONResponse {
		$agent = $this->viewableAgent(agentId: $agentId);
		if ($agent instanceof JSONResponse) {
			return $agent;
		}

		try {
			return new JSONResponse(['agentId' => $agentId] + $this->services->catalog->forAgent(agent: $agent));
		} catch (Throwable $e) {
			$this->logger->error('Hermiq tool-catalog read failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not load tool catalog'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

	}//end toolCatalog()

	/**
	 * Resolve a pending tool-access request an agent raised.
	 *
	 * ⚠️ ONLY THE AGENT'S OWNER. Not admins — an admin can already edit
	 * `Agent.tools` directly and does not need this path; routing requests to
	 * them would make them a queue of decisions about agents they never created.
	 * The refusal deliberately does not reveal whether the request exists.
	 *
	 * Granting appends the tool to `Agent.tools` — the SAME field, resolved by
	 * the SAME `ToolGrantResolver`, so this adds a governed route to changing a
	 * grant and changes nothing about how a grant is enforced.
	 *
	 * @param string $agentId   The agent the request belongs to.
	 * @param string $requestId The request object's uuid.
	 *
	 * @return JSONResponse The resolved request.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-11-12-tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
	 *
	 * @psalm-suppress TypeDoesNotContainType OCP 35 types getUID() as
	 *   non-empty-string; Nextcloud 32-34 can still return '', so the guard stays.
	 */
	public function resolveToolAccessRequest(string $agentId, string $requestId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$agent = $this->services->agents->find(agentId: $agentId);

		// One refusal for "no such agent", "not yours" and "no such request":
		// a distinct 404 would let a non-owner probe which requests exist.
		// An EMPTY uid never owns anything, even an agent whose stored owner is
		// also empty: otherwise the two compare equal and an unowned agent
		// becomes everyone's.
		if ($agent === null || $user->getUID() === '' || $agent->getOwner() !== $user->getUID()) {
			return $this->requestNotFound();
		}

		$resolution = new ToolAccessResolution(agentId: $agentId, requestId: $requestId, agent: $agent, decidedBy: $user->getUID());
		$refusal = $this->decisionRefusal(resolution: $resolution);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->writeDecision(resolution: $resolution);

	}//end resolveToolAccessRequest()

	/**
	 * Read the decision and the stored request onto the resolution, or the refusal:
	 * 400 for an unknown decision, the shared 404 for no such request, 409 when the
	 * request is no longer the one the owner was shown.
	 *
	 * @param ToolAccessResolution $resolution The resolution, past the owner check.
	 *
	 * @return JSONResponse|null
	 */
	private function decisionRefusal(ToolAccessResolution $resolution): ?JSONResponse {
		$resolution->decision = (string)$this->request->getParam('decision', '');
		if (in_array($resolution->decision, ['granted', 'refused'], true) === false) {
			return new JSONResponse(['error' => "decision must be 'granted' or 'refused'"], Http::STATUS_BAD_REQUEST);
		}

		$data = $this->services->grants->findAccessRequest(requestId: $resolution->requestId, agentId: $resolution->agentId);
		if ($data === null) {
			return $this->requestNotFound();
		}

		$resolution->data = $data;
		$conflict = (new ToolAccessDecision())->conflict(stored: $data, shownToolId: (string)$this->request->getParam('toolId', ''));
		if ($conflict !== null) {
			return new JSONResponse(['error' => $conflict], Http::STATUS_CONFLICT);
		}

		return null;
	}//end decisionRefusal()

	/**
	 * Write the decision: grant the requested tool (when granted) and record the
	 * decided request.
	 *
	 * From here the resolution WRITES. Every call below reaches storage or the
	 * notification bus, and an escaping Throwable would leave the caller an HTML
	 * error page in place of the JSON its client parses — so the write half is
	 * translated, while the guards before it deliberately are not.
	 *
	 * @param ToolAccessResolution $resolution The resolution, past every guard.
	 *
	 * @return JSONResponse
	 */
	private function writeDecision(ToolAccessResolution $resolution): JSONResponse {
		$data = $resolution->data;
		$toolId = (string)($data['toolId'] ?? '');

		try {
			$data['status'] = $resolution->decision;
			$data['decidedBy'] = $resolution->decidedBy;
			$data['decidedAt'] = (new DateTimeImmutable())->format(DATE_ATOM);

			$this->services->grants->grantRequestedTool(agent: $resolution->agent, data: $data);
			$this->services->grants->saveDecision(data: $data, requestId: $resolution->requestId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Hermiq tool-access request resolution failed: ' . $e->getMessage(),
				['exception' => $e, 'agentId' => $resolution->agentId, 'requestId' => $resolution->requestId]
			);
			return new JSONResponse(
				['error' => 'Could not resolve the tool-access request'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		return new JSONResponse(['status' => $resolution->decision, 'toolId' => $toolId, 'agentId' => $resolution->agentId]);
	}//end writeDecision()

	/**
	 * The one refusal for "no such agent", "not yours" and "no such request".
	 *
	 * @return JSONResponse
	 */
	private function requestNotFound(): JSONResponse {
		return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
	}//end requestNotFound()

	/**
	 * Persist the `Agent.tools` grant array (owner-only, single write-path).
	 *
	 * Deliberately NOT `@NoCSRFRequired` — unlike the two read endpoints, this one
	 * MUTATES an authorization boundary (which tools an agent may call), so the CSRF
	 * guard stays ON. `@nextcloud/axios` sends the requesttoken on every request, so
	 * `src/api/toolOversight.js`'s PUT works unchanged; a cross-site forger cannot
	 * silently widen an agent's grants.
	 *
	 * @param string $agentId Agent UUID.
	 *
	 * @return JSONResponse The updated grant array, or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/design.md#put-api-agents-agentid-tool-grants
	 */
	public function updateToolGrants(string $agentId): JSONResponse {
		$agent = $this->modifiableAgent(agentId: $agentId);
		if ($agent instanceof JSONResponse) {
			return $agent;
		}

		$rawGrants = $this->request->getParam('grants');
		if (is_array($rawGrants) === false) {
			return new JSONResponse(['error' => 'grants must be an array or a grant structure'], Http::STATUS_BAD_REQUEST);
		}

		return $this->saveGrants(
			agent: $agent,
			update: new ToolGrantUpdate(agentId: $agentId, grants: $this->services->grants->storableGrants(rawGrants: $rawGrants))
		);

	}//end updateToolGrants()

	/**
	 * Write the agent's new grants, translating a scope mismatch to 404 and any other
	 * failure to 500.
	 *
	 * @param ObjectEntity $agent The agent, owned by the caller.
	 * @param ToolGrantUpdate $update The agent in the path and its storable grants.
	 *
	 * @return JSONResponse
	 */
	private function saveGrants(ObjectEntity $agent, ToolGrantUpdate $update): JSONResponse {
		try {
			$savedTools = $this->services->grants->saveAgent(
				payload: $this->services->grants->grantWritePayload(agent: $agent, grants: $update->grants),
				agentId: $update->agentId
			);

			return new JSONResponse(['agentId' => $update->agentId, 'tools' => $savedTools]);
		} catch (DoesNotExistException $e) {
			// ObjectService::saveObject() re-throws DoesNotExistException on a
			// tenant/scope mismatch — translate to 404 (never a raw 500 on the
			// defended path; gate-49 / opencatalogi#86 lesson).
			return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq tool-grants update failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not update tool grants'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end saveGrants()

	/**
	 * The agent when the signed-in user owns it, otherwise the refusal: 401 with no
	 * user, 404 for no such agent, 403 when it is not theirs to modify.
	 *
	 * @param string $agentId Agent UUID.
	 *
	 * @return ObjectEntity|JSONResponse
	 *
	 * @psalm-suppress TypeDoesNotContainType OCP 35 types getUID() as
	 *   non-empty-string; Nextcloud 32-34 can still return '', so the guard stays.
	 */
	private function modifiableAgent(string $agentId): ObjectEntity|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$agent = $this->services->agents->find(agentId: $agentId);
		if ($agent === null) {
			return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
		}

		// Owner-only modification guard (gate-7 no-admin-idor), mirroring
		// AgentsController::canUserModifyAgent().
		if ($agent->getOwner() !== $user->getUID() || $user->getUID() === '') {
			return new JSONResponse(
				['error' => 'You do not have permission to modify this agent'],
				Http::STATUS_FORBIDDEN
			);
		}

		return $agent;
	}//end modifiableAgent()

	/**
	 * The per-agent oversight read (EU AI Act art.12/14): recorded tool
	 * invocations, newest first, tenant-scoped, with a retention note and a
	 * CSV/JSON export.
	 *
	 * @param string $agentId Agent UUID.
	 * @param string $format `json` (default) or `csv`.
	 * @param string $from Optional ISO 8601 lower bound (inclusive).
	 * @param string $to Optional ISO 8601 upper bound (inclusive).
	 *
	 * @return JSONResponse|DataDownloadResponse The oversight payload, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-per-agent-tool-invocation-oversight-surface-ai-act-art-12-14
	 */
	public function toolInvocations(string $agentId, string $format = 'json', string $from = '', string $to = ''): JSONResponse|DataDownloadResponse {
		$agent = $this->viewableAgent(agentId: $agentId);
		if ($agent instanceof JSONResponse) {
			return $agent;
		}

		$export = new ToolInvocationExport(agentId: $agentId, format: $format, from: $from, to: $to);

		try {
			$payload = $this->invocationsPayload(agent: $agent, export: $export);

			if ($export->format === 'csv') {
				return new DataDownloadResponse(
					data: $this->services->invocations->toCsv(rows: $payload['rows']),
					filename: 'hermiq-tool-invocations-' . $agentId . '.csv',
					contentType: 'text/csv'
				);
			}

			return new JSONResponse($payload);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq tool-invocations read failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not load tool invocations'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

	}//end toolInvocations()

	/**
	 * The oversight payload: the rich MCP-invocation audit when this OpenRegister
	 * records it, otherwise the run audit log, over the requested window.
	 *
	 * @param ObjectEntity $agent The agent, visible to the caller.
	 * @param ToolInvocationExport $export The request.
	 *
	 * @return array<string, mixed>
	 */
	private function invocationsPayload(ObjectEntity $agent, ToolInvocationExport $export): array {
		$available = $this->richAuditAvailable();
		$ownerUids = $this->services->invocations->correlatedOwnerUids(agentId: $export->agentId, agent: $agent);

		$window = (new ToolInvocationRows())->window(from: $export->from, to: $export->to);

		$source = 'run-audit-log';
		$rows = $this->services->invocations->degradedRows(agentId: $export->agentId, window: $window);
		if ($available === true) {
			$source = 'or-mcp-invocation-audit';
			$rows = $this->services->invocations->richRows(ownerUids: $ownerUids, window: $window);
		}

		return [
			'agentId' => $export->agentId,
			'available' => $available,
			'source' => $source,
			'retention' => "Retention follows OpenRegister's AuditTrail policy.",
			'rows' => $rows,
		];
	}//end invocationsPayload()

	/**
	 * The agent when the signed-in user may see it, otherwise the refusal: 401 with no
	 * user, 404 for no such agent, 403 when the agent is not theirs to see.
	 *
	 * @param string $agentId Agent UUID.
	 *
	 * @return ObjectEntity|JSONResponse
	 */
	private function viewableAgent(string $agentId): ObjectEntity|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$agent = $this->services->agents->find(agentId: $agentId);
		if ($agent === null) {
			return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
		}

		if ($this->services->agents->canAccess(agent: $agent, userId: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Access denied to this agent'], Http::STATUS_FORBIDDEN);
		}

		return $agent;
	}//end viewableAgent()

	/**
	 * Whether OpenRegister's richer per-invocation MCP audit shape
	 * (`AuditTrailMapper::createToolInvocationEntry()`) is present — detected by
	 * the DECLARED `toolId` property existing on the installed `AuditTrail`
	 * entity class (real accessors are Nextcloud `Entity::__call()` magic, so a
	 * `method_exists()` check would be unreliable; the declared property is not).
	 * False in standalone unit runs (the test stub predates this chain) and on
	 * any OpenRegister install that has not yet shipped it (Risk 4 fallback).
	 *
	 * `protected`, not `private`: a class-shape check like this cannot be
	 * per-test-mocked any other way (it is not behind an injected collaborator),
	 * so `ToolOversightControllerTest` exercises both branches via an anonymous
	 * subclass overriding this one method — the rest of the controller is
	 * exercised unchanged.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-5-tooloversightcontroller-routes-catalog-grants-invocations
	 */
	protected function richAuditAvailable(): bool {
		return property_exists(AuditTrail::class, 'toolId');
	}//end richAuditAvailable()

}//end class
