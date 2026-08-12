<?php

/**
 * Unit tests for GitHubTemplatePushService (agent-template-github-store).
 *
 * Mirrors OpenBuild's `GitHubPushServiceTest` (verified at HEAD in
 * `openbuild/tests/Unit/Service/GitHubPushServiceTest.php`): this service takes
 * no GitHub token — only a broker `credentialId` — so these tests lock the
 * no-token surface and the fail-closed guards. There is deliberately no
 * happy-path test here: `push()` resolves the broker through `Server::get()`,
 * which needs the Nextcloud container; with no container available in the unit
 * suite, every path that reaches the broker fails closed — which is exactly the
 * behaviour asserted below. The wire surface (create-repo → set-topics →
 * blob/tree/commit → update-ref) is covered where it is actually exercisable:
 * against the live broker on the dev instance.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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
 * @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\GitHubTemplatePushService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for {@see GitHubTemplatePushService} — the no-token contract + fail-closed guards.
 */
final class GitHubTemplatePushServiceTest extends TestCase {
	/**
	 * The core regression: NO method on this service may take a raw token.
	 *
	 * @return void
	 */
	public function testNoMethodAcceptsAToken(): void {
		$reflection = new ReflectionClass(GitHubTemplatePushService::class);

		foreach ($reflection->getMethods() as $method) {
			foreach ($method->getParameters() as $parameter) {
				$name = strtolower($parameter->getName());
				self::assertNotSame(
					'pat',
					$name,
					$method->getName() . '() must NOT take a PAT — GitHub auth belongs to the broker'
				);
				self::assertStringNotContainsString(
					'token',
					$name,
					$method->getName() . '() must NOT take a token — GitHub auth belongs to the broker'
				);
			}
		}
	}//end testNoMethodAcceptsAToken()

	/**
	 * push() names a credential, not a secret — plus the credential owner for the
	 * broker's ownership guard.
	 *
	 * @return void
	 */
	public function testPushTakesACredentialIdAndAnActingUser(): void {
		$reflection = new ReflectionMethod(GitHubTemplatePushService::class, 'push');
		$names = array_map(static fn ($p) => $p->getName(), $reflection->getParameters());

		self::assertContains('credentialId', $names, 'push() must take a broker credential UUID');
		self::assertContains(
			'actingUserId',
			$names,
			'push() must take the credential owner for the broker\'s ownership guard'
		);
	}//end testPushTakesACredentialIdAndAnActingUser()

	/**
	 * The service holds no HTTP client: every GitHub call goes through the broker.
	 *
	 * @return void
	 */
	public function testServiceHoldsNoHttpClient(): void {
		$reflection = new ReflectionClass(GitHubTemplatePushService::class);

		foreach ($reflection->getConstructor()->getParameters() as $parameter) {
			$type = (string)$parameter->getType();
			self::assertStringNotContainsString(
				'IClientService',
				$type,
				'GitHubTemplatePushService must not hold an HTTP client — every call goes through the broker'
			);
		}
	}//end testServiceHoldsNoHttpClient()

	/**
	 * Fail closed when the broker cannot serve the call: no fallback, no publish.
	 *
	 * OpenRegister IS on the unit-test autoloader, so `isBrokerAvailable()` is true
	 * here and `push()` gets as far as the first broker call — which cannot resolve
	 * a real `Server::get()` container in a unit test. It must throw rather than
	 * degrade to any token-bearing path.
	 *
	 * @return void
	 */
	public function testPushFailsClosedWhenTheBrokerCannotServeTheCall(): void {
		$service = new GitHubTemplatePushService(new NullLogger());

		$this->expectException(RuntimeException::class);

		$service->push(
			package: '{"name":"Demo"}',
			owner: 'acme',
			repo: 'demo',
			visibility: 'private',
			credentialId: 'cred-uuid'
		);
	}//end testPushFailsClosedWhenTheBrokerCannotServeTheCall()

	/**
	 * The broker-availability check is a real `class_exists` on OpenRegister's broker,
	 * so an instance without OpenRegister cannot reach the push path at all.
	 *
	 * @return void
	 */
	public function testBrokerAvailabilityIsCheckedAgainstOpenRegister(): void {
		$service = new GitHubTemplatePushService(new NullLogger());

		self::assertTrue(
			$service->isBrokerAvailable(),
			'OpenRegister is on the test autoloader, so the broker must resolve'
		);
	}//end testBrokerAvailabilityIsCheckedAgainstOpenRegister()

	/**
	 * An empty credential is refused before any broker call is attempted.
	 *
	 * @return void
	 */
	public function testPushRefusesAnEmptyCredential(): void {
		$service = new GitHubTemplatePushService(new NullLogger());

		$this->expectException(RuntimeException::class);

		$service->push(
			package: '{"name":"Demo"}',
			owner: 'acme',
			repo: 'demo',
			visibility: 'private',
			credentialId: ''
		);
	}//end testPushRefusesAnEmptyCredential()

	/**
	 * An invalid owner/repo is refused before any broker call is attempted.
	 *
	 * @return void
	 */
	public function testPushRefusesInvalidRepo(): void {
		$service = new GitHubTemplatePushService(new NullLogger());

		$this->expectException(RuntimeException::class);

		$service->push(
			package: '{"name":"Demo"}',
			owner: '../evil',
			repo: 'demo',
			visibility: 'private',
			credentialId: 'cred-uuid'
		);
	}//end testPushRefusesInvalidRepo()

	/**
	 * push() carries a `$kind` seam defaulting to `KIND_AGENT_TEMPLATE`
	 * (hermiq-github-store) — every existing caller that omits `$kind` gets
	 * EXACTLY the prior agent-template-only behaviour.
	 *
	 * @return void
	 */
	public function testPushKindParameterDefaultsToAgentTemplate(): void {
		$reflection = new ReflectionMethod(GitHubTemplatePushService::class, 'push');
		$kind = null;
		foreach ($reflection->getParameters() as $parameter) {
			if ($parameter->getName() === 'kind') {
				$kind = $parameter;
			}
		}

		self::assertNotNull($kind, 'push() must carry a kind parameter (hermiq-github-store)');
		self::assertTrue($kind->isDefaultValueAvailable(), 'kind must default (regression-safe for every existing caller)');
		self::assertSame(GitHubTemplatePushService::KIND_AGENT_TEMPLATE, $kind->getDefaultValue());
	}//end testPushKindParameterDefaultsToAgentTemplate()

	/**
	 * The two publish kinds are distinct, stable string constants
	 * (hermiq-github-store) — `GitHubTemplateCatalogService`'s matching
	 * constants must agree with these on the wire (kind-tagged cards / install
	 * dispatch).
	 *
	 * @return void
	 */
	public function testKindConstantsAreDistinctStrings(): void {
		self::assertSame('agent-template', GitHubTemplatePushService::KIND_AGENT_TEMPLATE);
		self::assertSame('skill', GitHubTemplatePushService::KIND_SKILL);
		self::assertNotSame(GitHubTemplatePushService::KIND_AGENT_TEMPLATE, GitHubTemplatePushService::KIND_SKILL);
	}//end testKindConstantsAreDistinctStrings()

	/**
	 * Passing `kind: KIND_SKILL` does not bypass the pre-broker guards — an
	 * invalid repo is still refused before any broker call, exactly as for the
	 * default agent-template kind (hermiq-github-store regression: the kind
	 * seam only changes topic/package-file selection, never the guard order).
	 *
	 * @return void
	 */
	public function testPushRefusesInvalidRepoForSkillKindToo(): void {
		$service = new GitHubTemplatePushService(new NullLogger());

		$this->expectException(RuntimeException::class);

		$service->push(
			package: "---\nname: Demo\n---\nBody.",
			owner: '../evil',
			repo: 'demo-skill',
			visibility: 'private',
			credentialId: 'cred-uuid',
			actingUserId: null,
			kind: GitHubTemplatePushService::KIND_SKILL
		);
	}//end testPushRefusesInvalidRepoForSkillKindToo()
}//end class
