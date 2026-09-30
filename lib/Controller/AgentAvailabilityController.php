<?php

/**
 * Hermiq: switch an agent off and on from its page (agents-switch-off-and-stop).
 *
 * GET answers the switch state, whether the caller may switch, and how many
 * schedules the agent has (the delete confirmation names them). POST switches.
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
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Agent\AgentAvailabilityService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * The availability endpoint of one agent.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
 */
class AgentAvailabilityController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request      The request.
	 * @param AgentAvailabilityService $availability The switch.
	 * @param IUserSession             $userSession  The signed-in person.
	 */
	public function __construct(
		IRequest $request,
		private readonly AgentAvailabilityService $availability,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The switch state of an agent the caller may read.
	 *
	 * @param string $id The agent.
	 *
	 * @return JSONResponse The state, 404 when the caller may not read the agent.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-deleting-an-agent-removes-its-schedules-req-agoff-004
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$uid = (string)($this->userSession->getUser()?->getUID() ?? '');
		$agent = $this->availability->readableAgent($id, $uid);
		if ($agent === null) {
			return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(
			$this->shape(agent: $agent) + [
				'canSwitch' => $this->availability->mayModify(agent: $agent, uid: $uid),
				'scheduleCount' => $this->availability->scheduleCount($id),
			]
		);
	}//end show()

	/**
	 * Switch the agent off or on. Body: `active` and, to switch off, `reason`.
	 *
	 * @param string $id The agent.
	 *
	 * @return JSONResponse The new state, or 400, 403 or 404.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
	 */
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		$uid = (string)($this->userSession->getUser()?->getUID() ?? '');
		$active = $this->request->getParam('active');
		try {
			$agent = $this->availability->switchAgent(
				$id,
				($active === true || $active === 'true' || $active === '1' || $active === 1),
				(string)($this->request->getParam('reason') ?? ''),
				$uid
			);
		} catch (RuntimeException $e) {
			$status = (int)$e->getCode();
			if (in_array($status, [Http::STATUS_BAD_REQUEST, Http::STATUS_FORBIDDEN, Http::STATUS_NOT_FOUND], true) === false) {
				$status = Http::STATUS_INTERNAL_SERVER_ERROR;
			}

			return new JSONResponse(['error' => $e->getMessage()], $status);
		}

		return new JSONResponse($this->shape(agent: $agent));
	}//end update()

	/**
	 * The switch state as the page reads it.
	 *
	 * @param ObjectEntity $agent The agent.
	 *
	 * @return array<string, mixed> The state.
	 */
	private function shape(ObjectEntity $agent): array {
		$data = $agent->getObject();

		return [
			'active' => (($data['active'] ?? true) !== false),
			'changedBy' => ($data['availabilityChangedBy'] ?? null),
			'changedAt' => ($data['availabilityChangedAt'] ?? null),
			'reason' => ($data['availabilityReason'] ?? null),
		];
	}//end shape()

}//end class
