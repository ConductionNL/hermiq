<?php

/**
 * What an install must pass before the installer is called
 * (tools-agent-workspace-and-installed-tools D4).
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * The gates of an install, in order: the tool, the code execution AI feature,
 * the arguments, the admin's registries, and the organisation's package
 * allowlist when it keeps one.
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004
 */
class InstallPolicy {

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
	 * @param RunCodeTool            $runCode       Answers whether the code execution AI feature is on.
	 * @param CodeSandboxSettings    $settings      The admin's registry allowlist.
	 * @param GuardrailPolicyService $guardrails    The organisation's package allowlist.
	 * @param ObjectService          $objectService Reads the run's agent for its organisation.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004
	 */
	public function __construct(
		private readonly RunCodeTool $runCode,
		private readonly CodeSandboxSettings $settings,
		private readonly GuardrailPolicyService $guardrails,
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The validated request, or an error envelope: the tool, the feature, the
	 * arguments and the admin's registry allowlist, in that order.
	 *
	 * @param string               $toolId    The tool id.
	 * @param array<string, mixed> $arguments The call's arguments.
	 *
	 * @return array<string, mixed> `{manager, package, version}` or `['error' => ...]`.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#scenario-a-registry-that-is-not-allowlisted
	 */
	public function request(string $toolId, array $arguments): array {
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
	 * The refusal when the organisation of the run's agent keeps a package
	 * allowlist without this package; null when the package may be installed.
	 *
	 * @param string $agentId The run's agent.
	 * @param string $manager `pip` or `npm`.
	 * @param string $package The package.
	 *
	 * @return array{error: array{code: string, message: string}}|null
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004
	 */
	public function packageRefusal(string $agentId, string $manager, string $package): ?array {
		$policy = $this->guardrails->effectivePolicyFor(organisation: $this->organisationOf(agentId: $agentId));
		if (self::permits(allowlist: (array)($policy['packageAllowlist'] ?? []), manager: $manager, package: $package) === true) {
			return null;
		}

		return self::err(code: 'package_not_allowed', message: $package . ' is not on your organisation\'s package allowlist.');
	}//end packageRefusal()

	/**
	 * Whether a package allowlist lets the package in: an empty list keeps
	 * none; an entry is `manager:package` or a bare package name. pip names
	 * compare as PyPI normalises them.
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
			if (is_string($entry) === true && self::entryName(entry: $entry, manager: $manager) === $wanted) {
				return true;
			}
		}

		return false;
	}//end permits()

	/**
	 * The package an allowlist entry names for this manager, or null when it
	 * names another manager's package.
	 *
	 * @param string $entry   The entry.
	 * @param string $manager `pip` or `npm`.
	 *
	 * @return string|null The normalised name.
	 */
	private static function entryName(string $entry, string $manager): ?string {
		$entry = trim($entry);
		if (str_starts_with($entry, $manager . ':') === true) {
			$entry = substr($entry, strlen($manager) + 1);
		}

		if (str_contains($entry, ':') === true) {
			return null;
		}

		return self::normalName(manager: $manager, package: $entry);
	}//end entryName()

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
	 * The organisation of the run's agent, '' when it cannot be read: the
	 * instance default policy then applies.
	 *
	 * @param string $agentId The agent.
	 *
	 * @return string
	 */
	private function organisationOf(string $agentId): string {
		try {
			$agent = $this->objectService->find(id: $agentId, options: new ObjectFindOptions(register: 'hermiq', schema: 'agent'));
		} catch (Throwable) {
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
