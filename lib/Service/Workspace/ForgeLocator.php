<?php

/**
 * Hermiq ForgeLocator.
 *
 * Turns a forge-relative repository slug into the clone URL, with the host taken
 * from configuration (app config `workspace_forge_base_url`, `https://github.com`
 * by default) and never from the model. A slug that looks like a URL, carries a
 * scheme, a host, `..` or anything but `owner/name` is refused: a URL argument
 * would let the model choose the host, and with a push credential in scope that
 * is an exfiltration channel.
 *
 * Egress to the forge is judged by the same policy the rest of agent egress
 * uses (the web research egress guard with the admin's allow and deny lists),
 * so there is no second allowlist inside the workspace tooling. A policy denial
 * answers `egress_denied` at once, before any network call, so it never reads
 * as the forge being down.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Workspace
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
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-the-repository-is-named-by-slug-never-by-url
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\WebResearch\WebResearchEgressGuard;
use OCA\Hermiq\Service\WebResearch\WebResearchSettingsHandler;
use OCP\IAppConfig;

/**
 * Resolves the forge URL for a slug and asks the egress policy about it.
 *
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-forge-egress-is-authorised-per-run-by-the-single-policy-source
 */
class ForgeLocator {

	public const DEFAULT_BASE_URL = 'https://github.com';

	/**
	 * A forge-relative slug: `owner/name`, nothing else.
	 *
	 * @var string
	 */
	private const SLUG_PATTERN = '#^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}/[A-Za-z0-9_.][A-Za-z0-9_.-]{0,99}$#';

	/**
	 * Build the locator.
	 *
	 * @param IAppConfig                 $appConfig App config (forge base URL).
	 * @param WebResearchEgressGuard     $guard     The agent egress policy.
	 * @param WebResearchSettingsHandler $settings  The admin's allow and deny lists.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly WebResearchEgressGuard $guard,
		private readonly WebResearchSettingsHandler $settings,
	) {
	}//end __construct()

	/**
	 * Validate a slug.
	 *
	 * @param string $repository The slug the model supplied.
	 *
	 * @return string The slug.
	 *
	 * @throws WorkspaceException invalid_argument.
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-the-repository-is-named-by-slug-never-by-url
	 */
	public function slug(string $repository): string {
		$slug = trim($repository);
		if (preg_match(self::SLUG_PATTERN, $slug) !== 1 || str_contains($slug, '..') === true || str_ends_with($slug, '.git') === true) {
			throw new WorkspaceException(
				errorCode: WorkspaceException::INVALID_ARGUMENT,
				message: 'Name the repository as owner/name, not as a URL.'
			);
		}

		return $slug;
	}//end slug()

	/**
	 * The clone URL for a slug, after the egress policy allowed the host.
	 *
	 * @param string $repository The slug.
	 *
	 * @return string The URL. Never returned to the model.
	 *
	 * @throws WorkspaceException invalid_argument or egress_denied.
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-a-policy-denial-is-distinguishable-from-an-outage
	 */
	public function cloneUrl(string $repository): string {
		$slug = $this->slug(repository: $repository);
		$base = rtrim($this->appConfig->getValueString(Application::APP_ID, 'workspace_forge_base_url', self::DEFAULT_BASE_URL), '/');
		if ($base === '') {
			$base = self::DEFAULT_BASE_URL;
		}

		$config = $this->settings->getWebResearchSettingsOnly();
		$verdict = $this->guard->assertSafe(
			url: $base . '/',
			isAdminConfiguredEndpoint: false,
			allowlist: (array)($config['fetchAllowlist'] ?? []),
			denylist: (array)($config['fetchDenylist'] ?? []),
			allowInsecureHttp: false
		);
		if ($verdict['allowed'] !== true) {
			throw new WorkspaceException(
				errorCode: WorkspaceException::EGRESS_DENIED,
				message: 'The egress policy does not allow this run to reach the forge.'
			);
		}

		return $base . '/' . $slug . '.git';
	}//end cloneUrl()
}//end class
