<?php

/**
 * Hermiq installTool tool (tools-agent-workspace-and-installed-tools D4).
 *
 * Installs one command line tool for the run's conversation. Before the
 * installer is called, the agent code execution AI feature must be on, the
 * package manager's registry must be on the admin's allowlist, and the package
 * must be on the organisation's package allowlist when it keeps one. The
 * installer answers a tool layer; its hash is checked against its bytes, the
 * layer is stored beside the workspaces by that hash, and the tool is recorded
 * on the conversation's workspace, so a published build pins it. Never throws:
 * every failure is an error envelope.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\CodeSandbox
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\CodeSandbox;

use OCA\Hermiq\Mcp\CodeSandboxToolDescriptors;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspaceRunScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The installTool tool behind its gates.
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) the tool meets each gate (feature, registry,
 * organisation, workspace) and the installer in one place, so none of them can be skipped.
 */
class InstallToolService {

	/**
	 * A PyPI project name or an npm package name, scoped or not.
	 *
	 * @var array<string, string>
	 */
	private const PACKAGE_PATTERNS = [
		'pip' => '/^[A-Za-z0-9](?:[A-Za-z0-9._-]{0,98}[A-Za-z0-9])?$/',
		'npm' => '/^(?:@[a-z0-9][a-z0-9._-]{0,99}\/)?[a-z0-9][a-z0-9._-]{0,113}$/',
	];

	/**
	 * One exact version.
	 *
	 * @var string
	 */
	private const VERSION_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9.+!_-]{0,62}$/';

	/**
	 * Constructor.
	 *
	 * @param RunCodeTool                 $runCode       Answers whether the code execution AI feature is on.
	 * @param CodeSandboxClient           $client        Calls the installer.
	 * @param CodeSandboxSettings         $settings      The admin's registry allowlist.
	 * @param GuardrailPolicyService      $guardrails    The organisation's package allowlist.
	 * @param WorkspaceRunScope           $scope         The verified run, for its conversation's workspace.
	 * @param ServerSideWorkspaceProvider $workspaces    Stores the layer and records the tool.
	 * @param ObjectService               $objectService Reads the run's agent for its organisation.
	 * @param LoggerInterface             $logger        Records failures, never package content.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004
	 */
	public function __construct(
		private readonly RunCodeTool $runCode,
		private readonly CodeSandboxClient $client,
		private readonly CodeSandboxSettings $settings,
		private readonly GuardrailPolicyService $guardrails,
		private readonly WorkspaceRunScope $scope,
		private readonly ServerSideWorkspaceProvider $workspaces,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Install the call's package for the run's conversation.
	 *
	 * @param string               $uid       The acting user.
	 * @param string               $toolId    The tool id; only installTool is served.
	 * @param array<string, mixed> $arguments manager, package, version.
	 *
	 * @return array<string, mixed> manager, package, version, versions, binaries, sha256 and the layer as
	 *   artefact; or `['error' => ['code', 'message']]`.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#scenario-an-agent-installs-csvkit
	 */
	public function invoke(string $uid, string $toolId, array $arguments): array {
		$request = $this->request(toolId: $toolId, arguments: $arguments);
		if (isset($request['error']) === true) {
			return $request;
		}

		try {
			$binding = $this->scope->current();
			$this->workspaces->root(runKey: $binding['workspaceKey']);
		} catch (WorkspaceException $e) {
			return self::err(code: WorkspaceException::WORKSPACE_ABSENT, message: 'Tools are installed into the agent\'s workspace, and this run has none.');
		}

		$policy = $this->guardrails->effectivePolicyFor(organisation: $this->organisationOf(agentId: $binding['agentId']));
		$allowlist = (array)($policy['packageAllowlist'] ?? []);
		if (self::permits(allowlist: $allowlist, manager: $request['manager'], package: $request['package']) === false) {
			return self::err(code: 'package_not_allowed', message: $request['package'] . ' is not on your organisation\'s package allowlist.');
		}

		try {
			$installed = $this->client->install(manager: $request['manager'], package: $request['package'], version: $request['version'], uid: $uid);
			$tool = $this->keep(workspaceKey: $binding['workspaceKey'], request: $request, installed: $installed);
		} catch (CodeSandboxUnavailableException $e) {
			return self::err(code: 'code_sandbox_unavailable', message: $e->getMessage());
		} catch (RuntimeException $e) {
			$this->logger->warning('[InstallToolService] the install failed', ['reason' => $e->getMessage()]);
			return self::err(code: 'install_failed', message: $e->getMessage());
		}

		return $tool + [
			'versions' => $installed['versions'],
			'artefact' => ['type' => 'toolLayer', 'id' => $tool['sha256']],
		];
	}//end invoke()

	/**
	 * The validated request, or an error envelope: the tool, the feature, the
	 * arguments and the admin's registry allowlist, in that order.
	 *
	 * @param string               $toolId    The tool id.
	 * @param array<string, mixed> $arguments The call's arguments.
	 *
	 * @return array<string, mixed> `{manager, package, version}` or `['error' => ...]`.
	 */
	private function request(string $toolId, array $arguments): array {
		if ($toolId !== CodeSandboxToolDescriptors::INSTALL_TOOL) {
			return self::err(code: 'unknown_tool', message: "Unknown tool id '" . $toolId . "'.");
		}

		if ($this->runCode->featureEnabled() === false) {
			return self::err(code: 'code_execution_not_enabled', message: RunCodeTool::NOT_ENABLED);
		}

		$request = self::validArguments(arguments: $arguments);
		if (isset($request['error']) === false && in_array($request['manager'], $this->settings->installManagers(), true) === false) {
			return self::err(code: 'registry_not_allowed', message: $request['manager'] . ' is not an allowed registry on this instance.');
		}

		return $request;
	}//end request()

	/**
	 * The manager, package and version, or an error envelope for an argument
	 * that is not one exact package of pip or npm.
	 *
	 * @param array<string, mixed> $arguments The call's arguments.
	 *
	 * @return array<string, mixed> `{manager, package, version}` or `['error' => ...]`.
	 */
	private static function validArguments(array $arguments): array {
		$manager = ($arguments['manager'] ?? null);
		if (in_array($manager, CodeSandboxSettings::INSTALL_MANAGERS, true) === false) {
			return self::err(code: 'invalid_argument', message: 'manager is pip or npm.');
		}

		$package = ($arguments['package'] ?? null);
		if (is_string($package) === false || preg_match(self::PACKAGE_PATTERNS[$manager], $package) !== 1) {
			return self::err(code: 'invalid_argument', message: 'package is one ' . $manager . ' package name.');
		}

		$version = ($arguments['version'] ?? null);
		if ($version === '') {
			$version = null;
		}

		if ($version !== null && (is_string($version) === false || preg_match(self::VERSION_PATTERN, $version) !== 1)) {
			return self::err(code: 'invalid_argument', message: 'version is one exact version, such as 2.1.0.');
		}

		return ['manager' => $manager, 'package' => $package, 'version' => $version];
	}//end validArguments()

	/**
	 * Whether the organisation's package allowlist lets the package in: an
	 * empty list keeps none; an entry is `manager:package` or a bare package
	 * name. pip names compare as PyPI normalises them.
	 *
	 * @param array<int|string, mixed> $allowlist The organisation's list.
	 * @param string                   $manager   `pip` or `npm`.
	 * @param string                   $package   The package.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004
	 */
	public static function permits(array $allowlist, string $manager, string $package): bool {
		if ($allowlist === []) {
			return true;
		}

		$wanted = self::normalName(manager: $manager, package: $package);
		foreach ($allowlist as $entry) {
			if (is_string($entry) === false) {
				continue;
			}

			$entry = trim($entry);
			$prefix = $manager . ':';
			if (str_starts_with($entry, $prefix) === true) {
				$entry = substr($entry, strlen($prefix));
			}

			if (str_contains($entry, ':') === false && self::normalName(manager: $manager, package: $entry) === $wanted) {
				return true;
			}
		}

		return false;
	}//end permits()

	/**
	 * A package name as its registry compares it.
	 *
	 * @param string $manager `pip` or `npm`.
	 * @param string $package The package.
	 *
	 * @return string
	 */
	private static function normalName(string $manager, string $package): string {
		if ($manager === 'pip') {
			return (string)preg_replace('/[-_.]+/', '-', strtolower($package));
		}

		return strtolower($package);
	}//end normalName()

	/**
	 * Check the layer against its hash, store it by that hash and record the
	 * tool on the conversation's workspace.
	 *
	 * @param string               $workspaceKey The conversation's workspace.
	 * @param array<string, mixed> $request      `{manager, package, version}`.
	 * @param array<string, mixed> $installed    The installer's answer.
	 *
	 * @return array{manager: string, package: string, version: string, sha256: string, binaries: array<int, string>}
	 *
	 * @throws RuntimeException When the layer does not match its hash or cannot be stored.
	 */
	private function keep(string $workspaceKey, array $request, array $installed): array {
		$bytes = base64_decode((string)$installed['layer']['archive'], true);
		$sha256 = (string)$installed['layer']['sha256'];
		if ($bytes === false || hash('sha256', $bytes) !== $sha256) {
			throw new RuntimeException('The installed tool\'s layer does not match its hash; nothing was kept.');
		}

		$store = $this->workspaces->toolLayerStore();
		if (is_dir($store) === false && mkdir($store, 0700, true) === false && is_dir($store) === false) {
			throw new RuntimeException('The tool layer could not be stored.');
		}

		$path = $store . '/' . $sha256 . '.tar.gz';
		if (is_file($path) === false && file_put_contents($path, $bytes) !== strlen($bytes)) {
			throw new RuntimeException('The tool layer could not be stored.');
		}

		$version = (string)$installed['version'];
		if ($version === '') {
			$version = (string)$request['version'];
		}

		$tool = [
			'manager' => $request['manager'],
			'package' => $request['package'],
			'version' => $version,
			'sha256' => $sha256,
			'binaries' => $installed['binaries'],
		];

		try {
			$this->workspaces->recordInstalledTool(runKey: $workspaceKey, tool: $tool);
		} catch (WorkspaceException $e) {
			throw new RuntimeException('The tool was installed, but could not be recorded on the workspace: ' . $e->getMessage());
		}

		return $tool;
	}//end keep()

	/**
	 * The organisation of the run's agent, '' when it cannot be read.
	 *
	 * @param string $agentId The agent.
	 *
	 * @return string
	 */
	private function organisationOf(string $agentId): string {
		try {
			$agent = $this->objectService->find(id: $agentId, options: new ObjectFindOptions(register: 'hermiq', schema: 'agent'));
		} catch (Throwable $e) {
			$this->logger->warning('[InstallToolService] the run\'s agent could not be read', ['reason' => $e->getMessage()]);
			return '';
		}

		if (($agent instanceof ObjectEntity) === false) {
			return '';
		}

		return (string)($agent->getOrganisation() ?? '');
	}//end organisationOf()

	/**
	 * An error envelope.
	 *
	 * @param string $code    The machine code.
	 * @param string $message The message.
	 *
	 * @return array{error: array{code: string, message: string}}
	 */
	private static function err(string $code, string $message): array {
		return ['error' => ['code' => $code, 'message' => $message]];
	}//end err()
}//end class
