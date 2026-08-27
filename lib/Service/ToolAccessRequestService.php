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
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCP\Notification\IManager as INotificationManager;
use DateTime;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Discovery beyond an agent's grants, and the access request that can widen them.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Two halves of ONE capability
 *   — "an agent's access to a tool it does not have": discovering that the tool
 *   exists, and asking for it. Both halves read the same catalogue through the
 *   same visibility rule, so separating them would either duplicate that access
 *   or need a third class to share it, and it would add a SIXTEENTH constructor
 *   parameter to HermiqToolProvider, which calls both. ⚠️ The split is still the
 *   right move — do it when that provider's constructor is next opened, not by
 *   raising this number again.
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
	 * The app reported for tools with no app segment in their id.
	 *
	 * OpenRegister's own tools (`get_object`, `search_objects`, `list_registers`)
	 * are the register/object surface itself rather than any app's, so they get a
	 * name that says that instead of the first word of the verb.
	 *
	 * @var string
	 */
	private const CORE_TOOL_APP = 'openregister';

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
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md
	 */
	public function listAvailable(string $uid, ?string $agentId, string $query = ''): array {
		$catalog = $this->catalog();
		if ($catalog === []) {
			return ['tools' => [], 'note' => 'No tool catalogue is available on this instance.'];
		}

		$held = $this->heldIds(agentId: $agentId, catalog: $catalog);

		$found = $this->collectMatches(
			catalog: $catalog,
			uid: $uid,
			held: $held,
			tokens: $this->tokenise(query: $query)
		);

		$shown = count($found['tools']);

		return [
			'tools' => $found['tools'],
			'matched' => $found['matched'],
			'shown' => $shown,
			// Distinct tools, not raw catalogue rows — reporting the row count
			// would tell the caller this instance has more capabilities than it does.
			'totalInCatalog' => $found['distinct'],
			'truncated' => ($found['matched'] > $shown),
			'note' => $this->noteFor(
				shown: $shown,
				matched: $found['matched'],
				distinct: $found['distinct']
			),
		];
	}//end listAvailable()

	/**
	 * Split a query into the words a tool is matched on.
	 *
	 * Match on WORDS, not on the whole query as one substring. A model asking for
	 * "CRM sales pipeline leads" is describing a capability, not quoting a tool
	 * id, and `str_contains($haystack, 'crm sales pipeline leads')` can only ever
	 * miss. Any token hitting is enough — recall matters more than precision
	 * here, because the caller is choosing from the result rather than acting on
	 * it.
	 *
	 * @param string $query The raw query.
	 *
	 * @return array<int, string> The tokens, all longer than two characters.
	 */
	private function tokenise(string $query): array {
		$split = preg_split('/[^a-z0-9]+/', strtolower(trim($query)));
		if ($split === false) {
			return [];
		}

		return array_values(array_filter(
			$split,
			static fn (string $t): bool => ($t !== '' && strlen($t) > 2)
		));
	}//end tokenise()

	/**
	 * Walk the catalogue and collect the tools this user may see.
	 *
	 * ⚠️ GATE: the same tool id can arrive MORE THAN ONCE.
	 *
	 * Schema-derived tools are emitted per (register, schema) row, and this
	 * instance has duplicate rows from repeated register imports — measured
	 * 2026-08-17: 406 registers including FOUR DocuDesk ones, and schemas like
	 * `huisstijl` and `generatedDocument` present three times each. The result
	 * was 184 catalogue entries for 160 distinct tools, with eight docudesk tools
	 * appearing exactly four times.
	 *
	 * That is not cosmetic here: duplicates consume the MAX_MATCHES budget, so a
	 * caller asking about correspondence could be shown 25 entries covering
	 * barely six distinct capabilities and conclude the rest do not exist.
	 *
	 * Deduplicating by id is deliberately done HERE rather than only fixing the
	 * data: the derivation will re-emit duplicates for any instance whose rows
	 * are duplicated, and discovery must not degrade because of it. First
	 * descriptor for an id wins; they are copies of one another.
	 *
	 * @param array<int, array<string, mixed>> $catalog The tool catalogue.
	 * @param string $uid The acting user.
	 * @param array<string, mixed> $held The ids the agent already holds.
	 * @param array<int, string> $tokens The query tokens; [] matches everything.
	 *
	 * @return array{tools: array<int, array<string, mixed>>, matched: int, distinct: int}
	 */
	private function collectMatches(array $catalog, string $uid, array $held, array $tokens): array {
		$out = [];
		$matched = 0;
		$seen = [];

		foreach ($catalog as $descriptor) {
			$id = ($descriptor['mcpId'] ?? ($descriptor['name'] ?? null));
			if (is_string($id) === false || $id === '' || isset($seen[$id]) === true) {
				continue;
			}

			$seen[$id] = true;

			if ($this->userMaySee(uid: $uid, toolId: $id) === false) {
				continue;
			}

			$description = (string)($descriptor['description'] ?? '');
			if ($this->matchesTokens(haystack: strtolower($id . ' ' . $description), tokens: $tokens) === false) {
				continue;
			}

			// Count every match BEFORE the cap, so the caller can be told how much
			// it is not seeing.
			$matched++;
			if (count($out) >= self::MAX_MATCHES) {
				continue;
			}

			$out[] = [
				'id' => $id,
				'description' => $description,
				'app' => $this->appOf(toolId: $id),
				'reach' => $this->reachOf(descriptor: $descriptor),
				'held' => isset($held[$id]),
			];
		}//end foreach

		return ['tools' => $out, 'matched' => $matched, 'distinct' => count($seen)];
	}//end collectMatches()

	/**
	 * Whether a tool's searchable text hits any query token.
	 *
	 * @param string $haystack The lower-cased id and description.
	 * @param array<int, string> $tokens The query tokens; [] matches everything.
	 *
	 * @return bool True when the tool should be listed.
	 */
	private function matchesTokens(string $haystack, array $tokens): bool {
		if ($tokens === []) {
			return true;
		}

		foreach ($tokens as $token) {
			if (str_contains($haystack, $token) === true) {
				return true;
			}
		}

		return false;
	}//end matchesTokens()

	/**
	 * Whether a descriptor describes a read or a write.
	 *
	 * The model needs to know a write is a write BEFORE it argues for it, and so
	 * does the human reading the request afterwards.
	 *
	 * @param array<string, mixed> $descriptor The tool descriptor.
	 *
	 * @return string `read` or `write`.
	 */
	private function reachOf(array $descriptor): string {
		if (($descriptor['scope'] ?? 'read') === 'write' || ($descriptor['readOnlyHint'] ?? false) === false) {
			return 'write';
		}

		return 'read';
	}//end reachOf()

	/**
	 * The note that travels with a result, warning when it is truncated.
	 *
	 * ⚠️ A TRUNCATED LIST THAT DOES NOT SAY SO READS AS THE WHOLE CATALOGUE.
	 * This capped at 25 and `break`ed, so an unfiltered call returned the first
	 * 25 tools in registration order and looked complete. Measured 2026-08-17:
	 * 184 tools on this instance, of which the first 25 are cms/register/object
	 * tools — so an agent asked for a lead reported "there is no CRM or leads
	 * tool on this instance. I searched." and requested Deck and file tools
	 * instead, while `pipelinq.lead.search` and `pipelinq.lead.get` sat
	 * undisplayed. The model was reasoning correctly from a list that lied.
	 *
	 * @param int $shown How many tools were returned.
	 * @param int $matched How many matched before the cap.
	 * @param int $distinct How many distinct tools exist on the instance.
	 *
	 * @return string The note.
	 */
	private function noteFor(int $shown, int $matched, int $distinct): string {
		$note = 'A tool with "held": false CANNOT be called. Use requestToolAccess to ask the owner for it.';
		if ($matched <= $shown) {
			return $note;
		}

		return $note . sprintf(
			' ⚠️ SHOWING %d OF %d MATCHING TOOLS (%d total on this instance). This list is'
			. ' INCOMPLETE — do NOT conclude a capability is absent from it. Call this tool'
			. ' again with a narrower `query` (e.g. an app name or a noun like "lead",'
			. ' "client", "invoice") before concluding anything does not exist.',
			$shown,
			$matched,
			$distinct
		);
	}//end noteFor()

	/**
	 * Raise an access request. Grants nothing.
	 *
	 * @param string      $uid     The acting user.
	 * @param string|null $agentId The agent asking.
	 * @param string      $toolId  The tool it wants.
	 * @param string      $reason  Why — shown to the human who decides.
	 *
	 * @return array<string, mixed> The pending request, or an error.
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md
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
			$message = 'A request for this tool is already pending with the owner.';
			if ($status === 'refused') {
				$message = 'This was already refused. Do not ask again — the owner must reopen it.';
			}

			return [
				'status' => $status,
				'message' => $message,
			];
		}

		$saved = $this->save(data: [
			'agentId' => $agentId,
			'toolId' => $toolId,
			'reason' => $reason,
			'status' => 'pending',
			'requestedBy' => $uid,
			'requestedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
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
		// ⚠️ Only a DOTTED id names its app. `pipelinq.lead.get` is app-prefixed;
		// `get_object` and `search_objects` are not — they are OpenRegister's own
		// unprefixed tools, and splitting them on `_` reported apps called "get",
		// "create", "update" and "delete" (five tools each, measured 2026-08-17).
		//
		// That lands in the one field meant to tell a model WHERE a capability
		// lives, so a wrong answer here is worse than no answer: an agent looking
		// for "which app handles objects" was being told "the get app".
		$position = strpos($toolId, '.');
		if ($position !== false && $position > 0) {
			return substr($toolId, 0, $position);
		}

		// An underscored id is the alias form of a dotted one, so the app is
		// recoverable only when the catalogue also carries the dotted id. It does
		// not for OpenRegister's core tools, which genuinely have no app segment —
		// they are the register/object surface itself.
		if (str_contains($toolId, '_') === true) {
			return self::CORE_TOOL_APP;
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
	 * An OpenRegister result as a plain array, whichever shape it arrived in.
	 *
	 * `ObjectService` returns an `ObjectEntity` on some paths and an already-
	 * decoded array on others, and four call sites in this class were each
	 * open-coding the same `is_array(...) ? ... : ->jsonSerialize()` ternary. One
	 * helper, so a fifth caller cannot pick a fifth spelling — and so the
	 * question "what does this return" is answered once.
	 *
	 * @param mixed $value The object service result.
	 *
	 * @return array<string, mixed> The decoded record.
	 */
	private function asRecord(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			return $value->jsonSerialize();
		}

		return [];
	}//end asRecord()

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

			return $this->asRecord(value: $object);
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
				return $this->asRecord(value: $row);
			}
		} catch (Throwable $e) {
			$this->logger->warning('[ToolAccessRequestService] request lookup failed: ' . $e->getMessage());
		}

		return null;
	}//end findRequest()

	/**
	 * The approvals an agent is currently waiting on, for the chat to show.
	 *
	 * Shaped as a GENERIC approval, not as a tool-access request: each item
	 * carries its own `kind` and its own `resolveUrl`, so the chat posts a
	 * decision where it is told to rather than knowing how any particular
	 * approval is resolved. A future approval — fetching a URL, editing a file
	 * that is not open — supplies its own two fields and needs no change in the
	 * client at all.
	 *
	 * Read-only and safe to call on every turn: it neither creates nor resolves
	 * anything.
	 *
	 * @param string|null $agentId The agent whose pending requests to list.
	 *
	 * @return array<int, array<string, mixed>> Pending approvals, oldest first.
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md
	 */
	public function pendingApprovals(?string $agentId): array {
		if ($agentId === null || $agentId === '') {
			return [];
		}

		// Every request record lives in an OpenRegister register, so without
		// OpenRegister there is nothing pending — not an error. Asked here
		// rather than answered by the catch below, because this method is
		// called on EVERY turn: a per-turn exception is an expensive way to
		// learn a fact that does not change (ADR-083 rule 1 — the reach is
		// optional, so availability is established before reaching).
		if ($this->openRegisterAvailable() === false) {
			return [];
		}

		$out = [];
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$results = $objectService->findAll([
				'filters' => [
					'register' => self::REGISTER_SLUG,
					'schema' => self::REQUEST_SCHEMA,
					'agentId' => $agentId,
					'status' => 'pending',
				],
			]);

			foreach ($results as $row) {
				$record = $this->asRecord(value: $row);
				$id = (string)($record['id'] ?? ($record['@self']['id'] ?? ''));
				$toolId = (string)($record['toolId'] ?? '');
				if ($id === '' || $toolId === '') {
					continue;
				}

				$out[] = [
					'id' => $id,
					'kind' => 'tool-access',
					// What the owner is deciding, in their words rather than the
					// model's: the tool's identity and reach are facts, the
					// reason beside them is agent-authored.
					'title' => $toolId,
					'app' => $this->appOf(toolId: $toolId),
					'reach' => (string)($record['reach'] ?? 'read'),
					'reason' => (string)($record['reason'] ?? ''),
					'agentId' => $agentId,
					'resolveUrl' => '/index.php/apps/hermiq/api/agents/'
						. rawurlencode($agentId) . '/tool-access-requests/' . rawurlencode($id),
				];
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ToolAccessRequestService] pending approvals lookup failed: ' . $e->getMessage()
			);
		}//end try

		return $out;

	}//end pendingApprovals()

	/**
	 * Whether OpenRegister is installed on this instance.
	 *
	 * The dependency is reached through the container rather than injected on
	 * purpose: injecting it would make this whole service unconstructable on an
	 * instance without OpenRegister, turning a clean empty result into a 500 —
	 * which is the failure ADR-083 rule 3 exists to prevent. Establishing
	 * availability first is what makes the deferred lookup the correct shape
	 * rather than a hidden hard dependency.
	 *
	 * @return bool True when OpenRegister is installed.
	 */
	private function openRegisterAvailable(): bool {
		try {
			$appManager = $this->container->get(\OCP\App\IAppManager::class);
			return $appManager->isInstalled('openregister');
		} catch (Throwable $e) {
			// Cannot establish availability ⇒ treat it as absent. Reporting
			// "nothing pending" is correct here; guessing "available" would
			// only move the failure one line down.
			unset($e);
			return false;
		}//end try

	}//end openRegisterAvailable()

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

			return $this->asRecord(value: $saved);
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
	 *
	 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md
	 */
	public function notifyOwner(string $agentId, string $toolId, string $subject): void {
		$agent = $this->loadAgent(agentId: $agentId);
		$owner = (string)($agent['owner'] ?? ($agent['@self']['owner'] ?? ''));
		if ($owner === '') {
			$this->logger->warning('[ToolAccessRequestService] agent has no owner; nobody to notify', ['agent' => $agentId]);

			return;
		}

		$subjectKey = 'tool_access_requested';
		if ($subject === 'granted') {
			$subjectKey = 'tool_access_granted';
		}

		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($owner)
				->setDateTime(new DateTime())
				->setObject('toolaccess', $agentId . ':' . $toolId)
				->setSubject($subjectKey, [
					'agent' => (string)($agent['name'] ?? $agentId),
					'tool' => $toolId,
				]);
			$this->notifications->notify($notification);
		} catch (Throwable $e) {
			$this->logger->warning('[ToolAccessRequestService] notification failed: ' . $e->getMessage());
		}
	}//end notifyOwner()

}//end class
