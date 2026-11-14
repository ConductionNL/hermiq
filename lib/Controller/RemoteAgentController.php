<?php

/**
 * Hermiq RemoteAgentController.
 *
 * Admin settings, Remote agents (flows-hand-off-and-wait, REQ-A2A-001): list,
 * register, switch on or off, and remove an agent on another server reached
 * over A2A through an integriq source. Every route is an admin setting.
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
 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\RemoteAgent\RemoteAgentService;
use OCA\Hermiq\Service\RemoteAgent\RemoteAgentUnavailableException;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The admin's remote agents.
 *
 * Access control: every method is `#[AuthorizedAdminSetting]`; Nextcloud refuses a non-admin before the method runs.
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
 */
class RemoteAgentController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request      The request.
	 * @param RemoteAgentService $remoteAgents The remote agents.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
	 */
	public function __construct(
		IRequest $request,
		private readonly RemoteAgentService $remoteAgents,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/remote-agents
	 *
	 * @return JSONResponse 200 {results}
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function index(): JSONResponse {
		return new JSONResponse(['results' => $this->remoteAgents->list()]);
	}//end index()

	/**
	 * POST /api/remote-agents: register one, fetching its card through the source.
	 *
	 * @return JSONResponse 201 the remote agent; 400 for missing fields or a taken handle; 502 when the card cannot be fetched.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function create(): JSONResponse {
		try {
			$saved = $this->remoteAgents->register(
				input: [
					'name'        => $this->request->getParam('name', ''),
					'slug'        => $this->request->getParam('slug', ''),
					'description' => $this->request->getParam('description', ''),
					'source'      => $this->request->getParam('source', ''),
				]
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (RemoteAgentUnavailableException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse($saved, Http::STATUS_CREATED);
	}//end create()

	/**
	 * PUT /api/remote-agents/{id}: change its name, description or switch.
	 *
	 * @param string $id The remote agent.
	 *
	 * @return JSONResponse 200 the remote agent; 404 when it does not exist.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function update(string $id): JSONResponse {
		$input = [];
		foreach (['name', 'description', 'enabled'] as $field) {
			$value = $this->request->getParam($field);
			if ($value !== null) {
				$input[$field] = $value;
			}
		}

		if (array_key_exists('enabled', $input) === true) {
			$input['enabled'] = filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN);
		}

		$saved = $this->remoteAgents->update(id: $id, input: $input);
		if ($saved === null) {
			return new JSONResponse(['message' => 'Remote agent not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($saved);
	}//end update()

	/**
	 * DELETE /api/remote-agents/{id}
	 *
	 * @param string $id The remote agent.
	 *
	 * @return JSONResponse 200; 404 when it does not exist.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function destroy(string $id): JSONResponse {
		if ($this->remoteAgents->delete(id: $id) === false) {
			return new JSONResponse(['message' => 'Remote agent not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse([]);
	}//end destroy()
}//end class
