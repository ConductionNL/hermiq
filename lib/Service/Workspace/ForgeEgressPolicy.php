<?php

/**
 * Hermiq ForgeEgressPolicy.
 *
 * The forge host is a destination like any other for the egress policy
 * decision point, with one extra rule keyed on the run: it is reachable only
 * for a run whose agent holds a RESOLVING grant for a workspace tool that needs
 * the forge (open or push). The grant is the agent's own tool list, resolved by
 * the same resolver and the same repo-effecting rule that decide what the agent
 * is offered, so there is no second list to drift from it. A denial comes back
 * as `egress_denied`, never as a timeout.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Workspace
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-forge-egress-is-authorised-per-run-by-the-single-policy-source
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Mcp\WorkspaceToolDescriptors;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCP\IAppConfig;
use Throwable;

/**
 * Decides forge egress for one run.
 *
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-the-forge-host-is-denied-without-the-grant
 */
class ForgeEgressPolicy {

	/**
	 * The workspace tools that reach the forge.
	 *
	 * @var array<int, string>
	 */
	public const FORGE_TOOLS = [WorkspaceToolDescriptors::OPEN, WorkspaceToolDescriptors::PUSH];

	/**
	 * Build the policy.
	 *
	 * @param IAppConfig         $appConfig     The forge base URL.
	 * @param ObjectService      $objects       Reads the run's agent.
	 * @param ToolGrantResolver  $grantResolver Resolves the agent's grants.
	 * @param ToolRegistryFacade $registry      The tool catalogue.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectService $objects,
		private readonly ToolGrantResolver $grantResolver,
		private readonly ToolRegistryFacade $registry,
	) {
	}//end __construct()

	/**
	 * Whether a destination is the configured forge host.
	 *
	 * @param string $host The destination host.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-the-forge-host-is-denied-without-the-grant
	 */
	public function isForgeHost(string $host): bool {
		$base = $this->appConfig->getValueString(Application::APP_ID, 'workspace_forge_base_url', ForgeLocator::DEFAULT_BASE_URL);
		$forgeHost = strtolower((string)parse_url($base, PHP_URL_HOST));
		return $forgeHost !== '' && strtolower(rtrim($host, '.')) === $forgeHost;
	}//end isForgeHost()

	/**
	 * Whether this run's agent holds a resolving grant for a tool that needs the forge.
	 *
	 * @param string $agentId The agent UUID from the verified run token.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-the-forge-host-is-denied-without-the-grant
	 */
	public function permits(string $agentId): bool {
		if ($agentId === '') {
			return false;
		}

		try {
			$agent = $this->objects->find(id: $agentId, register: 'hermiq', schema: 'agent', _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return false;
		}

		if (($agent instanceof ObjectEntity) === false) {
			return false;
		}

		$grants = ($agent->getObject()['tools'] ?? []);
		if (is_array($grants) === false || $grants === []) {
			// An empty list means "every tool" elsewhere; it never opens the forge.
			return false;
		}

		$resolved = RepoEffectingGrants::filterIds(
			resolvedIds: $this->grantResolver->resolve(grants: $grants, catalog: $this->registry->listTools(toolWhitelist: [])),
			constraints: $this->grantResolver->argumentConstraints(grants: $grants)
		);

		return array_intersect(self::FORGE_TOOLS, $resolved) !== [];
	}//end permits()
}//end class
