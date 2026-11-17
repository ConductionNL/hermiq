<?php

/**
 * Hermiq AgentActingUserController.
 *
 * Tells the agent edit page whether the account an agent works with still
 * resolves (agent-acting-identity-picker REQ-AGID-003). The answer comes from
 * the server, never from a guess in the browser: the named user must exist
 * and be enabled, the same test AgentActingUserGuardListener applies on a
 * write and the run applies before it starts.
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
 * @spec openspec/changes/agent-acting-identity-picker/specs/agent-identity/spec.md#requirement-the-edit-page-shows-a-declared-account-that-no-longer-resolves-req-agid-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\AgentAccessServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Whether an agent's declared acting user still resolves.
 *
 * @spec openspec/changes/agent-acting-identity-picker/specs/agent-identity/spec.md#requirement-the-edit-page-shows-a-declared-account-that-no-longer-resolves-req-agid-003
 */
class AgentActingUserController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param AgentAccessServiceInterface $agentAccess The one per-agent access predicate.
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param IUserManager $userManager Looks up the named account.
	 *
	 * @spec openspec/changes/agent-acting-identity-picker/specs/agent-identity/spec.md#requirement-the-edit-page-shows-a-declared-account-that-no-longer-resolves-req-agid-003
	 */
	public function __construct(
		IRequest $request,
		private readonly AgentAccessServiceInterface $agentAccess,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The agent's declared acting user and whether it resolves.
	 *
	 * An agent the caller may not read answers 404, so a non-reader cannot
	 * confirm the agent exists or learn anything about the account it names.
	 * An agent with no declared account resolves: it runs as the person who
	 * uses it.
	 *
	 * @param string $id The Agent UUID.
	 *
	 * @return JSONResponse `{actingUser: string, resolves: bool}`, or 404.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/agent-acting-identity-picker/specs/agent-identity/spec.md#requirement-the-edit-page-shows-a-declared-account-that-no-longer-resolves-req-agid-003
	 */
	public function show(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
		}

		$agent = $this->agentAccess->loadAccessibleAgent(agentId: $id, userId: $user->getUID());
		if ($agent === null) {
			return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
		}

		$named = self::actingUserOf(data: $agent->getObject());

		return new JSONResponse(['actingUser' => $named, 'resolves' => $this->resolves(uid: $named)]);
	}//end show()

	/**
	 * Whether a declared acting user exists and is enabled; none declared resolves.
	 *
	 * @param string $uid The declared user id, or ''.
	 *
	 * @return bool True when the agent can run as declared.
	 *
	 * @spec openspec/changes/agent-acting-identity-picker/specs/agent-identity/spec.md#requirement-the-edit-page-shows-a-declared-account-that-no-longer-resolves-req-agid-003
	 */
	private function resolves(string $uid): bool {
		if ($uid === '') {
			return true;
		}

		$account = $this->userManager->get($uid);

		return ($account !== null && $account->isEnabled() === true);
	}//end resolves()

	/**
	 * The trimmed `actingUser` of an agent payload, or '' when absent or not a string.
	 *
	 * @param array<string, mixed> $data The agent payload.
	 *
	 * @return string The declared user id.
	 *
	 * @spec openspec/changes/agent-acting-identity-picker/specs/agent-identity/spec.md#requirement-the-edit-page-shows-a-declared-account-that-no-longer-resolves-req-agid-003
	 */
	private static function actingUserOf(array $data): string {
		$value = ($data['actingUser'] ?? null);
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end actingUserOf()
}//end class
