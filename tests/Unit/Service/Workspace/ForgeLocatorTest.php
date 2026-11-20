<?php

/**
 * ForgeLocator: a repository is named by slug, and the clone URL is built from
 * the admin's forge base URL only after the egress policy allowed it.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Workspace
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
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-the-repository-is-named-by-slug-never-by-url
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Workspace;

use OCA\Hermiq\Service\WebResearch\WebResearchEgressGuard;
use OCA\Hermiq\Service\WebResearch\WebResearchSettingsHandler;
use OCA\Hermiq\Service\Workspace\ForgeLocator;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Slug validation, base URL defaulting and the egress verdict.
 *
 * @covers \OCA\Hermiq\Service\Workspace\ForgeLocator
 * @uses   \OCA\Hermiq\Service\Workspace\WorkspaceException
 */
final class ForgeLocatorTest extends TestCase {

	/**
	 * What the egress guard was asked, per call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * An owner/name slug is accepted, trimmed.
	 *
	 * @return void
	 */
	public function testAnOwnerNameSlugIsAccepted(): void {
		$locator = $this->locator(baseUrl: 'https://git.example.org');

		self::assertSame('example-org/example-app', $locator->slug(repository: '  example-org/example-app '));
		self::assertSame('a/.dotfiles', $locator->slug(repository: 'a/.dotfiles'));
		self::assertSame([], $this->asked, 'Validating a slug never consults the egress policy.');
	}//end testAnOwnerNameSlugIsAccepted()

	/**
	 * A URL, a path, a traversal or a .git suffix is refused as a slug.
	 *
	 * @return void
	 */
	public function testAnythingButASlugIsRefused(): void {
		$locator = $this->locator(baseUrl: 'https://git.example.org');
		foreach (['https://github.com/a/b', 'a', 'a/b/c', 'a/..b', 'a/b.git', '', '-a/b', 'a b/c'] as $repository) {
			try {
				$locator->slug(repository: $repository);
				self::fail('Expected a refusal for ' . $repository);
			} catch (WorkspaceException $e) {
				self::assertSame(WorkspaceException::INVALID_ARGUMENT, $e->getErrorCode(), $repository);
				self::assertSame('Name the repository as owner/name, not as a URL.', $e->getMessage());
			}
		}
	}//end testAnythingButASlugIsRefused()

	/**
	 * The clone URL is the configured base, its trailing slash dropped, plus the slug and .git.
	 *
	 * @return void
	 */
	public function testTheCloneUrlUsesTheConfiguredBase(): void {
		$url = $this->locator(baseUrl: 'https://git.example.org/forge///')->cloneUrl(repository: 'example-org/example-app');

		self::assertSame('https://git.example.org/forge/example-org/example-app.git', $url);
		self::assertSame(
			[[
				'url' => 'https://git.example.org/forge/',
				'isAdminConfiguredEndpoint' => false,
				'allowlist' => ['git.example.org'],
				'denylist' => ['evil.example.org'],
				'allowInsecureHttp' => false,
			]],
			$this->asked
		);
	}//end testTheCloneUrlUsesTheConfiguredBase()

	/**
	 * A blank base URL setting falls back to the default forge.
	 *
	 * @return void
	 */
	public function testABlankBaseFallsBackToTheDefaultForge(): void {
		$url = $this->locator(baseUrl: '/')->cloneUrl(repository: 'example-org/example-app');

		self::assertSame(ForgeLocator::DEFAULT_BASE_URL . '/example-org/example-app.git', $url);
		self::assertSame(ForgeLocator::DEFAULT_BASE_URL . '/', $this->asked[0]['url']);
	}//end testABlankBaseFallsBackToTheDefaultForge()

	/**
	 * An unset base URL setting uses the default forge.
	 *
	 * @return void
	 */
	public function testAnUnsetBaseUsesTheDefaultForge(): void {
		self::assertSame('https://github.com/a/b.git', $this->locator(baseUrl: null)->cloneUrl(repository: 'a/b'));
	}//end testAnUnsetBaseUsesTheDefaultForge()

	/**
	 * An egress denial refuses with its own code, and the URL is never returned.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-a-policy-denial-is-distinguishable-from-an-outage
	 */
	public function testAnEgressDenialIsRefused(): void {
		try {
			$this->locator(baseUrl: 'https://git.example.org', allowed: false)->cloneUrl(repository: 'example-org/example-app');
			self::fail('Expected an egress denial.');
		} catch (WorkspaceException $e) {
			self::assertSame(WorkspaceException::EGRESS_DENIED, $e->getErrorCode());
			self::assertSame('The egress policy does not allow this run to reach the forge.', $e->getMessage());
			self::assertStringNotContainsString('git.example.org', $e->getMessage());
		}
	}//end testAnEgressDenialIsRefused()

	/**
	 * A bad slug is refused before the egress policy is asked.
	 *
	 * @return void
	 */
	public function testABadSlugIsRefusedBeforeTheEgressCheck(): void {
		try {
			$this->locator(baseUrl: 'https://git.example.org')->cloneUrl(repository: 'https://evil.example.org/a/b');
			self::fail('Expected a refusal.');
		} catch (WorkspaceException $e) {
			self::assertSame(WorkspaceException::INVALID_ARGUMENT, $e->getErrorCode());
		}

		self::assertSame([], $this->asked);
	}//end testABadSlugIsRefusedBeforeTheEgressCheck()

	/**
	 * The locator under test.
	 *
	 * @param string|null $baseUrl The stored base URL, or null when unset.
	 * @param bool        $allowed The egress verdict.
	 *
	 * @return ForgeLocator
	 */
	private function locator(?string $baseUrl, bool $allowed = true): ForgeLocator {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'workspace_forge_base_url' && $baseUrl !== null ? $baseUrl : $default)
		);

		$guard = $this->createMock(WebResearchEgressGuard::class);
		$guard->method('assertSafe')->willReturnCallback(
			function (string $url, bool $isAdminConfiguredEndpoint, array $allowlist = [], array $denylist = [], bool $allowInsecureHttp = false) use ($allowed): array {
				$this->asked[] = [
					'url' => $url,
					'isAdminConfiguredEndpoint' => $isAdminConfiguredEndpoint,
					'allowlist' => $allowlist,
					'denylist' => $denylist,
					'allowInsecureHttp' => $allowInsecureHttp,
				];
				return ['allowed' => $allowed, 'code' => ($allowed === true ? null : 'denied'), 'message' => null];
			}
		);

		$settings = $this->createMock(WebResearchSettingsHandler::class);
		$settings->method('getWebResearchSettingsOnly')->willReturn(['fetchAllowlist' => ['git.example.org'], 'fetchDenylist' => ['evil.example.org']]);

		return new ForgeLocator(appConfig: $appConfig, guard: $guard, settings: $settings);
	}//end locator()
}//end class
