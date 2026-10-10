<?php

/**
 * Hermiq OutsideAgentController.
 *
 * The surface an AI agent outside this instance talks to: what it could call, and
 * the call itself. Both endpoints are ordinary authenticated Nextcloud requests, so
 * an outside agent authenticates as a person, with that person's app password, and
 * every call is authorised by the owning app for that person. There is no separate
 * credential that would grant the agent standing rights of its own, because a second
 * permission model beside Nextcloud's is a second one to keep in step, and the
 * second one is always the one that is out of date.
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
 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#requirement-an-outside-agent-must-reach-declared-tools-through-a-registration
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\OutsideAgent\OutsideAgentGateway;
use OCA\Hermiq\Service\OutsideAgent\OutsideCallRefusedException;
use OCA\Hermiq\Service\OutsideAgent\OutsideToolSurface;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes the outside tool surface and runs one governed call.
 *
 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#requirement-both-gates-must-open-before-a-tool-runs
 */
class OutsideAgentController extends Controller {

	/**
	 * The audit action every outside call is recorded under, beside the internal
	 * agent runs on the same trail.
	 *
	 * @var string
	 */
	public const AUDIT_ACTION = 'outside-agent-call';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param OutsideToolSurface $surface What the owning apps declared reachable.
	 * @param OutsideAgentGateway $gateway The two gates and the response filter.
	 * @param IUserSession $userSession Resolves the calling principal.
	 * @param AuditTrailMapper $auditTrailMapper Records the call.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly OutsideToolSurface $surface,
		private readonly OutsideAgentGateway $gateway,
		private readonly IUserSession $userSession,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * The tools an owning app declared reachable from outside, plus what this
	 * caller's own registration may call of them.
	 *
	 * @return JSONResponse The surface.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#scenario-the-owning-app-declares-hermiq-publishes
	 */
	public function tools(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$declared = $this->surface->declaredTools();
			$registration = $this->gateway->registrationFor(principal: $user->getUID());
		} catch (Throwable $e) {
			$this->logger->error('Hermiq outside tool surface failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not read the tool surface'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$granted = [];
		if ($registration !== null && is_array($registration['tools'] ?? null) === true) {
			$granted = array_map('strval', $registration['tools']);
		}

		return new JSONResponse(
			[
				'tools' => $declared,
				'registered' => ($registration !== null),
				'granted' => $granted,
			]
		);

	}//end tools()

	/**
	 * Run one call for the authenticated principal.
	 *
	 * @return JSONResponse The narrowed response, or a refusal naming the gate.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#requirement-both-gates-must-open-before-a-tool-runs
	 */
	public function call(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$toolId = (string)$this->request->getParam('tool', '');
		$arguments = $this->request->getParam('arguments', []);
		if (is_array($arguments) === false) {
			$arguments = [];
		}

		$principal = $user->getUID();

		try {
			$result = $this->gateway->call(principal: $principal, toolId: $toolId, arguments: $arguments);
		} catch (OutsideCallRefusedException $refusal) {
			$this->record(principal: $principal, toolId: $toolId, outcome: 'refused', gate: $refusal->gate());

			return new JSONResponse(
				['error' => $refusal->getMessage(), 'gate' => $refusal->gate()],
				Http::STATUS_FORBIDDEN
			);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq outside agent call failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'The call could not be completed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$this->record(principal: $principal, toolId: $toolId, outcome: 'ok', gate: '');

		return new JSONResponse($result);
	}//end call()

	/**
	 * Record one outside call on the same audit trail the internal agent runs use,
	 * so there is one place to read who called what.
	 *
	 * Non-fatal by contract: a failed audit write never fails the call, in line
	 * with every other audit write in this app.
	 *
	 * @param string $principal The calling principal.
	 * @param string $toolId The tool that was called.
	 * @param string $outcome `ok` or `refused`.
	 * @param string $gate The gate that refused, when one did.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#requirement-an-outside-agents-calls-must-be-recorded-like-an-internal-agents
	 */
	private function record(string $principal, string $toolId, string $outcome, string $gate): void {
		try {
			$marker = new ObjectEntity();
			$marker->setUuid('outside-agent');

			$this->auditTrailMapper->createAuditTrailEntry(
				object: $marker,
				action: self::AUDIT_ACTION,
				context: [
					'principal' => $principal,
					'tool' => $toolId,
					'outcome' => $outcome,
					'gate' => $gate,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not record an outside-agent call: ' . $e->getMessage(),
				['exception' => $e]
			);
		}

	}//end record()
}//end class
