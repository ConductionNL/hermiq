<?php

/**
 * Hermiq ToolCatalogView.
 *
 * The grant-annotated derived tool catalogue the grant editor reads: every
 * catalogue entry with its scope, reach and whether this agent holds it.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\ToolOversight
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
 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\ToolOversight;

use OCA\Hermiq\Service\Workspace\RepoEffectingGrants;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\Capability\ToolReachResolver;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCP\IAppConfig;

/**
 * Builds the tool catalogue for one agent.
 *
 * @psalm-api
 *
 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/design.md#api-design
 */
class ToolCatalogView {

	/**
	 * Default progressive-disclosure threshold, mirrors `ToolLoop`'s default.
	 *
	 * @var int
	 */
	private const DEFAULT_DISCLOSURE_THRESHOLD = 30;

	/**
	 * Constructor.
	 *
	 * @param ToolRegistryFacade $toolRegistry OR's public tool read surface (gate-27 contract).
	 * @param ToolGrantResolver $grantResolver Schema-scoped grant expansion + default-deny.
	 * @param IAppConfig $appConfig Reads `hermiq.tools.disclosureThreshold`.
	 */
	public function __construct(
		private readonly ToolRegistryFacade $toolRegistry,
		private readonly ToolGrantResolver $grantResolver,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The catalogue for one agent, with the progressive-disclosure numbers.
	 *
	 * @param ObjectEntity $agent The agent.
	 *
	 * @return array{disclosureThreshold: int, resolvedCount: int, disclosureActive: bool, tools: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/design.md#api-design
	 * @spec openspec/specs/agent-capability-reach/spec.md#scenario-the-reach-of-every-catalogue-entry-is-readable-through-the-tool-catalogue-api
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) RepoEffectingGrants::filterIds is a pure static
	 *   filter over the resolved ids.
	 */
	public function forAgent(ObjectEntity $agent): array {
		$grants = $this->agentGrants(agent: $agent);
		$catalog = $this->toolRegistry->listTools();
		$resolvedIds = RepoEffectingGrants::filterIds(
			resolvedIds: $this->grantResolver->resolve(grants: $grants, catalog: $catalog),
			constraints: $this->grantResolver->argumentConstraints(grants: $grants)
		);
		$tools = $this->catalogEntries(
			catalog: $catalog,
			grantState: ['grants' => $grants, 'resolved' => array_flip($resolvedIds)]
		);

		$threshold = $this->disclosureThreshold();
		$resolvedCount = count($resolvedIds);

		return [
			'disclosureThreshold' => $threshold,
			'resolvedCount' => $resolvedCount,
			'disclosureActive' => ($resolvedCount > $threshold),
			'tools' => $tools,
		];
	}//end forAgent()

	/**
	 * The catalog descriptors as grant-annotated editor rows, nameless ones left out.
	 *
	 * @param array<int, mixed> $catalog The registry's tool descriptors.
	 * @param array{grants: array<int, string>, resolved: array<string, int>} $grantState The agent's raw grants and its resolved id set.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function catalogEntries(array $catalog, array $grantState): array {
		$tools = [];
		foreach ($catalog as $descriptor) {
			$entry = $this->catalogEntry(descriptor: $descriptor, grantState: $grantState);
			if ($entry !== null) {
				$tools[] = $entry;
			}
		}

		return $tools;
	}//end catalogEntries()

	/**
	 * One descriptor as a grant-annotated editor row, or null when it has no id.
	 *
	 * @param mixed $descriptor One registry tool descriptor.
	 * @param array{grants: array<int, string>, resolved: array<string, int>} $grantState The agent's raw grants and its resolved id set.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ToolGrantResolver and ToolReachResolver
	 *   are pure static classification predicates — the same ones the engine's tool
	 *   loop uses, called statically by design so the two cannot drift.
	 */
	private function catalogEntry(mixed $descriptor, array $grantState): ?array {
		$id = ($descriptor['mcpId'] ?? ($descriptor['name'] ?? null));
		if (is_string($id) === false || $id === '') {
			return null;
		}

		// 🔴 The descriptor is threaded through here. It was not before:
		// this call was id-only, so every 2-segment native id fell to
		// the fail-closed rule and the oversight UI showed `listFiles`
		// and `readFile` as WRITE tools — the exact opposite of what
		// their descriptors declare, and of what the engine's resolver
		// concluded about the same tools in toolCatalog(). An operator
		// deciding what to grant was reading a classification the
		// runtime did not share.
		$hints = null;
		if (is_array($descriptor) === true) {
			$hints = $descriptor;
		}

		$isWrite = ToolGrantResolver::isWriteOrDestructive(id: $id, descriptor: $hints);
		$granted = isset($grantState['resolved'][$id]);

		$scope = 'read';
		if ($isWrite === true) {
			$scope = 'write';
		}

		return [
			'id' => $id,
			'name' => (string)($descriptor['name'] ?? $id),
			'description' => (string)($descriptor['description'] ?? ''),
			'scope' => $scope,
			'reach' => ToolReachResolver::resolve(toolId: $id, descriptor: $hints),
			'destructiveHint' => $isWrite,
			'granted' => $granted,
			'grantedBy' => $this->grantedBy(id: $id, grantState: $grantState),
			// The UNION, not `$isWrite` — a read-scoped tool whose reach
			// is `instance` or beyond needs naming just as explicitly.
			'requiresExplicitGrant' => (
				ToolGrantResolver::requiresGrant(id: $id, descriptor: $hints) === true
				&& $granted === false
			),
		];
	}//end catalogEntry()

	/**
	 * The agent's raw `Agent.tools` grant strings, sanitized.
	 *
	 * @param ObjectEntity $agent Agent object.
	 *
	 * @return array<int, string>
	 */
	private function agentGrants(ObjectEntity $agent): array {
		$tools = ($agent->getObject()['tools'] ?? []);
		if (is_array($tools) === false) {
			return [];
		}

		$clean = [];
		foreach ($tools as $tool) {
			if (is_string($tool) === true && $tool !== '') {
				$clean[] = $tool;
			}
		}

		return $clean;
	}//end agentGrants()

	/**
	 * Best-effort "which grant entry produced this id" for the editor's UX —
	 * informational only, never load-bearing for the grant/deny decision itself.
	 *
	 * @param string                                                         $id         The resolved tool id.
	 * @param array{grants: array<int, string>, resolved: array<string, int>} $grantState The agent's raw grant strings and the resolved ids.
	 *
	 * @return string|null
	 */
	private function grantedBy(string $id, array $grantState): ?string {
		if (isset($grantState['resolved'][$id]) === false) {
			return null;
		}

		$grants = $grantState['grants'];
		if (in_array($id, $grants, true) === true) {
			return $id;
		}

		return $this->wildcardGrant(id: $id, grants: $grants) ?? $this->defaultGrant(grants: $grants);
	}//end grantedBy()

	/**
	 * The `<app>.<area>.*` (or `.*:write`) grant covering a three-segment id, first match first.
	 *
	 * @param string             $id     The resolved tool id.
	 * @param array<int, string> $grants The agent's raw grant strings.
	 *
	 * @return string|null The covering grant, or null.
	 */
	private function wildcardGrant(string $id, array $grants): ?string {
		$parts = explode('.', $id);
		if (count($parts) !== 3) {
			return null;
		}

		$prefix = $parts[0] . '.' . $parts[1];
		foreach ($grants as $grant) {
			if ($grant === ($prefix . '.*') || $grant === ($prefix . '.*:write')) {
				return $grant;
			}
		}

		return null;
	}//end wildcardGrant()

	/**
	 * The label of an empty grant list, which grants every discovered tool.
	 *
	 * @param array<int, string> $grants The agent's raw grant strings.
	 *
	 * @return string|null The label, or null when the agent has grants.
	 */
	private function defaultGrant(array $grants): ?string {
		if ($grants === []) {
			return '(default: all discovered tools)';
		}

		return null;
	}//end defaultGrant()

	/**
	 * The configured progressive-disclosure threshold.
	 *
	 * @return int
	 */
	private function disclosureThreshold(): int {
		return $this->appConfig->getValueInt('hermiq', 'tools.disclosureThreshold', self::DEFAULT_DISCLOSURE_THRESHOLD);
	}//end disclosureThreshold()
}//end class
