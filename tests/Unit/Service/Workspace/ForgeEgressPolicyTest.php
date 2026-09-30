<?php

/**
 * The forge host is reachable only for a run whose agent holds a resolving
 * grant for a workspace tool that needs it; the grants run through the real
 * OpenRegister resolver and the repo-effecting rule.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Workspace
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Workspace;

use OCA\Hermiq\Mcp\WorkspaceToolDescriptors;
use OCA\Hermiq\Service\Workspace\ForgeEgressPolicy;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-forge-egress-is-authorised-per-run-by-the-single-policy-source
 */
final class ForgeEgressPolicyTest extends TestCase {

	/**
	 * Only an agent with a resolving open or scoped push grant reaches the forge.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-the-forge-host-is-denied-without-the-grant
	 */
	public function testOnlyAResolvingForgeGrantOpensTheForge(): void {
		self::assertTrue($this->policy(tools: [WorkspaceToolDescriptors::OPEN])->permits(agentId: 'agent-1'));
		self::assertTrue(
			$this->policy(tools: [WorkspaceToolDescriptors::PUSH . '?repository=example-org/example-app&branch=feature-a'])->permits(agentId: 'agent-1')
		);
		self::assertFalse($this->policy(tools: [WorkspaceToolDescriptors::PUSH])->permits(agentId: 'agent-1'), 'A bare push grant does not resolve.');
		self::assertFalse($this->policy(tools: [WorkspaceToolDescriptors::READ_FILE])->permits(agentId: 'agent-1'));
		self::assertFalse($this->policy(tools: [])->permits(agentId: 'agent-1'), 'An empty list never opens the forge.');
		self::assertFalse($this->policy(tools: null)->permits(agentId: 'agent-1'), 'An unreadable agent never opens the forge.');
		self::assertFalse($this->policy(tools: [WorkspaceToolDescriptors::OPEN])->permits(agentId: ''));
	}//end testOnlyAResolvingForgeGrantOpensTheForge()

	/**
	 * The forge host is the host of the admin's base URL, nothing else.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-the-forge-host-is-denied-without-the-grant
	 */
	public function testTheForgeHostIsTheConfiguredOne(): void {
		$policy = $this->policy(tools: []);
		self::assertTrue($policy->isForgeHost(host: 'git.example.org'));
		self::assertTrue($policy->isForgeHost(host: 'GIT.example.org.'));
		self::assertFalse($policy->isForgeHost(host: 'github.com'));
		self::assertFalse($policy->isForgeHost(host: 'evil-git.example.org'));
	}//end testTheForgeHostIsTheConfiguredOne()

	/**
	 * The policy for an agent with these tools (null: the agent cannot be read).
	 *
	 * @param array<int, string>|null $tools The Agent.tools entries.
	 *
	 * @return ForgeEgressPolicy
	 */
	private function policy(?array $tools): ForgeEgressPolicy {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'workspace_forge_base_url' ? 'https://git.example.org' : $default)
		);
		$objects = $this->createMock(ObjectService::class);
		if ($tools === null) {
			$objects->method('find')->willThrowException(new \RuntimeException('not found'));
		} else {
			$agent = new ObjectEntity();
			$agent->setUuid('agent-1');
			$agent->setObject(['tools' => $tools]);
			$objects->method('find')->willReturn($agent);
		}

		$catalog = [];
		foreach (WorkspaceToolDescriptors::ALL as $descriptor) {
			$catalog[] = $descriptor + ['mcpId' => $descriptor['id']];
		}

		$registry = $this->createMock(ToolRegistryFacade::class);
		$registry->method('listTools')->willReturn($catalog);

		return new ForgeEgressPolicy(appConfig: $appConfig, objects: $objects, grantResolver: new ToolGrantResolver(), registry: $registry);
	}//end policy()
}//end class
