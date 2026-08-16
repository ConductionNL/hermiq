<?php

/**
 * Tool discovery past the grant, and the request that can widen it.
 *
 * `tool-scope-security-default` made an unconfigured agent tool-less, with no
 * override. That is the right default and it left a hole at the other end: an
 * agent could not discover what it was missing, and had no way to ask.
 *
 * The measured shape of that hole: `ToolSearchService` holds only "this run's
 * resolved (grant-filtered, default-denied) descriptor set", so `searchTools`
 * can never answer "does this capability exist elsewhere?". Asked to do
 * something a sibling app could do, the agent said the tool did not exist —
 * true of its own catalogue, false of the instance — and the operator's only
 * recourse was to guess a grant string. That is the pressure that produces a
 * wildcard grant, which is exactly what the default-deny was installed to stop.
 *
 * ⚠️ THE LINE THIS CLASS DEFENDS: a request is not a grant. `request()` writes a
 * pending record and notifies a human. It never touches `Agent.tools`. Only the
 * agent's OWNER can do that, through ToolAccessApprovalService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://hermiq.conduction.nl
 *
 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Engine\ToolGrantResolver;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Discovery beyond an agent's grants, and the access request that can widen them.
 */
class ToolAccessRequestService {

	/**
	 * Matches returned by a single discovery call.
	 *
	 * Bounded for the same reason `ToolSearchService` bounds its own: an
	 * unbounded list re-inflates the context this programme spent a change
	 * shrinking.
	 *
	 * @var int
	 */
	private const MAX_MATCHES = 25;

	/**
	 * Register and schema holding the request records.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug for a raised request.
	 *
	 * @var string
	 */
	private const REQUEST_SCHEMA = 'ToolAccessRequest';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface     $container    Resolves OpenRegister's object service lazily.
	 * @param ToolGrantResolver      $grantResolver Expands an agent's grants the same way ToolLoop does.
	 * @param INotificationManager   $notifications Tells the owner a decision is waiting.
	 * @param LoggerInterface        $logger        PSR logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ToolGrantResolver $grantResolver,
		private readonly INotificationManager $notifications,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * List tools the acting user can reach, marking which the agent already holds.
	 *
	 * ⚠️ Scoped to the USER, not to the agent's grants and not to the whole
	 * instance. Seeing past the grant is the point of the feature; returning the
	 * unfiltered catalogue would tell whoever can start a chat which apps are
	 * installed and what they do.
	 *
	 * @param string $uid     The acting user.
	 * @param string|null $agentId The calling agent, for the `held` flag.
	 * @param string $query   Optional keyword filter.
	 *
	 * @return array<string, mixed> Tool metadata — never anything dispatchable.
	 */
	public function listAvailable(string $uid, ?string $agentId, string $query = ''): array {
		$catalog = $this->catalog();
		if ($catalog === []) {
			return ['tools' => [], 'note' => 'No tool catalogue is available on this instance.'];
		}

		$held = $this->heldIds(agentId: $agentId, catalog: $catalog);
		$needle = strtolower(trim($query));
		$out = [];

		foreach ($catalog as $descriptor) {
			$id = ($descriptor['mcpId'] ?? ($descriptor['name'] ?? null));
			if (is_string($id) === false || $id === '') {
				continue;
			}

			if ($this->userMaySee(uid: $uid, toolId: $id) === false) {
				continue;
			}

			$description = (string)($descriptor['description'] ?? '');
			$haystack = strtolower($id . ' ' . $description);
			if ($needle !== '' && str_contains($haystack, $needle) === false) {
				continue;
			}

			$out[] = [
				'id' => $id,
				'description' => $description,
				'app' => $this->appOf(toolId: $id),
				// The model needs to know a write is a write BEFORE it argues for
				// it, and so does the human reading the request afterwards.
				'reach' => (($descriptor['scope'] ?? 'read') === 'write' || ($descriptor['readOnlyHint'] ?? false) === false) ? 'write' : 'read',
				'held' => isset($held[$id]),
			];

			if (count($out) >= self::MAX_MATCHES) {
				break;
			}
		}//end foreach

		return [
			'tools' => $out,
			'note' => 'A tool with "held": false CANNOT be called. Use requestToolAccess to ask the owner for it.',
		];
	}//end listAvailable()

	/**
	 * Raise an access request. Grants nothing.
	 *
	 * @param string      $uid     The acting user.
	 * @param string|null $agentId The agent asking.
	 * @param string      $toolId  The tool it wants.
	 * @param string      $reason  Why — shown to the human who decides.
	 *
	 * @return array<string, mixed> The pending request, or an error.
	 */
	public function request(string $uid, ?string $agentId, string $toolId, string $reason): array {
		if ($agentId === null || $agentId === '') {
			return ['error' => 'no_agent', 'message' => 'An access request must come from an agent.'];
		}

		if ($toolId === '' || $reason === '') {
			return ['error' => 'invalid_request', 'message' => 'Both toolId and reason are required.'];
		}

		if ($this->knownTool(toolId: $toolId) === false) {
			return ['error' => 'unknown_tool', 'message' => "No tool '{$toolId}' exists on this instance."];
		}

		$existing = $this->findRequest(agentId: $agentId, toolId: $toolId);
		if ($existing !== null) {
			$status = (string)($existing['status'] ?? 'pending');

			// A refusal stands. An agent that can re-ask will re-ask, and a
			// persistent model against a tired human is an approval mechanism
			// with a known outcome.
			return [
				'status' => $status,
				'message' => $status === 'refused'
					? 'This was already refused. Do not ask again — the owner must reopen it.'
					: 'A request for this tool is already pending with the owner.',
			];
		}

		$saved = $this->save([
			'agentId' => $agentId,
			'toolId' => $toolId,
			'reason' => $reason,
			'status' => 'pending',
			'requestedBy' => $uid,
			'requestedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
		]);

		$this->notifyOwner(agentId: $agentId, toolId: $toolId, subject: 'requested');

		return [
			'status' => 'pending',
			'requestId' => ($saved['id'] ?? null),
			'message' => 'Access was requested and is waiting for the owner to approve it. '
				. 'You still cannot use the tool. Tell the user you have asked.',
		];
	}//end request()

	/**
	 * The instance tool catalogue, or [] when OpenRegister is unavailable.
	 *
	 * @return array<int, array<string, mixed>> Descriptors.
	 */
	private function catalog(): array {
		try {
			$facade = $this->container->get('OCA\OpenRegister\Service\Mcp\ToolRegistryFacade');

			return $facade->listTools(toolWhitelist: []);
		} catch (Throwable $e) {
			$this->logger->warning('[ToolAccessRequestService] tool catalogue unavailable: ' . $e->getMessage());

			return [];
		}
	}//end catalog()

	/**
	 * Ids the agent already holds, as a lookup.
	 *
	 * @param string|null $agentId The agent.
	 * @param array<int, array<string, mixed>> $catalog Full catalogue.
	 *
	 * @return array<string, bool> Held ids.
	 */
	private function heldIds(?string $agentId, array $catalog): array {
		$agent = $this->loadAgent(agentId: $agentId);
		$grants = ($agent['tools'] ?? []);
		if (is_array($grants) === false) {
			$grants = [];
		}

		if ($grants === []) {
			return [];
		}

		return array_fill_keys($this->grantResolver->resolve(grants: $grants, catalog: $catalog), true);
	}//end heldIds()

	/**
	 * Whether the acting user may even know this tool exists.
	 *
	 * The owning app must be enabled for them. This is the disclosure bound: not
	 * the agent's grants (seeing past those is the point), not the whole box.
	 *
	 * @param string $uid    The acting user.
	 * @param string $toolId The tool id.
	 *
	 * @return bool True when the user may see it.
	 */
	private function userMaySee(string $uid, string $toolId): bool {
		$app = $this->appOf(toolId: $toolId);
		if ($app === '' || $app === Application::APP_ID) {
			return true;
		}

		try {
			$appManager = $this->container->get(\OCP\App\IAppManager::class);
			$user = $this->container->get(\OCP\IUserManager::class)->get($uid);
			if ($user === null) {
				return false;
			}

			return $appManager->isEnabledForUser($app, $user);
		} catch (Throwable $e) {
			// Fail CLOSED: an unresolvable app is not disclosed.
			return false;
		}
	}//end userMaySee()

	/**
	 * The app a tool id belongs to.
	 *
	 * @param string $toolId Dotted or underscored id.
	 *
	 * @return string App id, or '' when undeterminable.
	 */
	private function appOf(string $toolId): string {
		foreach (['.', '_'] as $separator) {
			$position = strpos($toolId, $separator);
			if ($position !== false && $position > 0) {
				return substr($toolId, 0, $position);
			}
		}

		return '';
	}//end appOf()

	/**
	 * Whether a tool id exists in the catalogue at all.
	 *
	 * @param string $toolId The id.
	 *
	 * @return bool True when known.
	 */
	private function knownTool(string $toolId): bool {
		foreach ($this->catalog() as $descriptor) {
			$id = ($descriptor['mcpId'] ?? ($descriptor['name'] ?? null));
			if ($id === $toolId) {
				return true;
			}
		}

		return false;
	}//end knownTool()

	/**
	 * Load an agent object, or [] when unavailable.
	 *
	 * @param string|null $agentId The agent uuid.
	 *
	 * @return array<string, mixed> Agent data.
	 */
	private function loadAgent(?string $agentId): array {
		if ($agentId === null || $agentId === '') {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find(id: $agentId, register: self::REGISTER_SLUG, schema: 'Agent');
			if ($object === null) {
				return [];
			}

			return is_array($object) ? $object : $object->jsonSerialize();
		} catch (Throwable $e) {
			return [];
		}
	}//end loadAgent()

	/**
	 * Find an existing request for this agent and tool.
	 *
	 * @param string $agentId The agent.
	 * @param string $toolId  The tool.
	 *
	 * @return array<string, mixed>|null The request, or null.
	 */
	private function findRequest(string $agentId, string $toolId): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$results = $objectService->findAll([
				'filters' => [
					'register' => self::REGISTER_SLUG,
					'schema' => self::REQUEST_SCHEMA,
					'agentId' => $agentId,
					'toolId' => $toolId,
				],
			]);

			foreach ($results as $row) {
				return is_array($row) ? $row : $row->jsonSerialize();
			}
		} catch (Throwable $e) {
			$this->logger->warning('[ToolAccessRequestService] request lookup failed: ' . $e->getMessage());
		}

		return null;
	}//end findRequest()

	/**
	 * Persist a request record.
	 *
	 * @param array<string, mixed> $data The record.
	 *
	 * @return array<string, mixed> The saved object.
	 */
	private function save(array $data): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$saved = $objectService->saveObject(
				object: $data,
				register: self::REGISTER_SLUG,
				schema: self::REQUEST_SCHEMA
			);

			return is_array($saved) ? $saved : $saved->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->error('[ToolAccessRequestService] could not save request: ' . $e->getMessage());

			return [];
		}
	}//end save()

	/**
	 * Notify the agent's owner.
	 *
	 * A grant visible only by re-reading `Agent.tools` is how an agent's
	 * capability drifts from what its owner believes it has — measured: 89% of
	 * agents were receiving the whole catalogue and nothing on the agent showed
	 * it.
	 *
	 * @param string $agentId The agent.
	 * @param string $toolId  The tool.
	 * @param string $subject 'requested' or 'granted'.
	 *
	 * @return void
	 */
	public function notifyOwner(string $agentId, string $toolId, string $subject): void {
		$agent = $this->loadAgent(agentId: $agentId);
		$owner = (string)($agent['owner'] ?? ($agent['@self']['owner'] ?? ''));
		if ($owner === '') {
			$this->logger->warning('[ToolAccessRequestService] agent has no owner; nobody to notify', ['agent' => $agentId]);

			return;
		}

		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($owner)
				->setDateTime(new \DateTime())
				->setObject('toolaccess', $agentId . ':' . $toolId)
				->setSubject($subject === 'granted' ? 'tool_access_granted' : 'tool_access_requested', [
					'agent' => (string)($agent['name'] ?? $agentId),
					'tool' => $toolId,
				]);
			$this->notifications->notify($notification);
		} catch (Throwable $e) {
			$this->logger->warning('[ToolAccessRequestService] notification failed: ' . $e->getMessage());
		}
	}//end notifyOwner()

}//end class
