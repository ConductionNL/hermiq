<?php

/**
 * The forge credential of a push: the owner's own personal credential, through
 * the broker's inject path, refused when the run's model credential is somebody
 * else's. Runs the real CredentialScopeResolver over credential objects shaped
 * like the broker's.
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

use OCA\Hermiq\Service\Credential\CredentialScopeResolver;
use OCA\Hermiq\Service\Workspace\ForgeCredentialResolver;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-the-forge-credential-and-the-model-credential-are-separate-and-neither-reaches-the-model
 */
final class ForgeCredentialResolverTest extends TestCase {

	/**
	 * Credential ids the broker was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $injected = [];

	/**
	 * The owner's own personal forge credential is used.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-the-forge-credential-is-absent-from-the-models-container
	 */
	public function testTheOwnersPersonalForgeCredentialIsUsed(): void {
		$resolver = $this->resolver(credentials: [
			$this->credential(uuid: 'forge-alice', provider: 'github', owner: 'alice'),
			$this->credential(uuid: 'forge-bob', provider: 'github', owner: 'bob'),
			$this->credential(uuid: 'model-alice', provider: 'anthropic', owner: 'alice'),
		]);

		$result = $resolver->resolve(ownerUid: 'alice', agent: $this->agent(data: ['provider' => 'anthropic']));

		self::assertSame(['credentialId' => 'forge-alice', 'secret' => 'secret-of-forge-alice'], $result);
		self::assertSame(['forge-alice'], $this->injected);
	}//end testTheOwnersPersonalForgeCredentialIsUsed()

	/**
	 * Model and forge credentials of two different people are refused, not warned about.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-mismatched-credential-owners-are-refused
	 */
	public function testMismatchedCredentialOwnersAreRefused(): void {
		// A pinned model credential that is bob's personal key, on a run alice owns.
		$scopes = $this->createMock(CredentialScopeResolver::class);
		$scopes->method('resolve')->willReturnCallback(
			static fn (string $provider): ?string => ($provider === 'github' ? 'forge-alice' : 'model-bob')
		);
		$scopes->method('scopeOfCredential')->willReturn('personal');
		$scopes->method('ownerOfCredential')->willReturnCallback(
			static fn (string $credentialId): ?string => ($credentialId === 'forge-alice' ? 'alice' : 'bob')
		);

		$this->assertRefused(
			code: WorkspaceException::CREDENTIAL_SCOPE_REFUSED,
			call: fn () => $this->resolver(credentials: [], scopes: $scopes)->resolve(
				ownerUid: 'alice',
				agent: $this->agent(data: ['provider' => 'anthropic'])
			)
		);
		self::assertSame([], $this->injected, 'No secret is fetched for a refused push.');
	}//end testMismatchedCredentialOwnersAreRefused()

	/**
	 * An organisation credential or another person's key is never used for a push.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-commits-are-authored-and-pushes-authorised-as-the-resolved-run-owner
	 */
	public function testOnlyThePersonalCredentialOfTheOwnerCounts(): void {
		$resolver = $this->resolver(credentials: [
			$this->credential(uuid: 'forge-org', provider: 'github', owner: 'admin', scope: 'organisation', organisation: 'org-1'),
			$this->credential(uuid: 'forge-bob', provider: 'github', owner: 'bob'),
		]);

		$this->assertRefused(
			code: WorkspaceException::CREDENTIAL_SCOPE_REFUSED,
			call: fn () => $resolver->resolve(ownerUid: 'alice', agent: $this->agent(data: [], organisation: 'org-1'))
		);
	}//end testOnlyThePersonalCredentialOfTheOwnerCounts()

	/**
	 * An unowned run cannot push.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-an-unowned-run-cannot-author-or-push
	 */
	public function testAnUnownedRunCannotPush(): void {
		$this->assertRefused(
			code: WorkspaceException::OWNER_UNRESOLVABLE,
			call: fn () => $this->resolver(credentials: [])->resolve(ownerUid: '', agent: null)
		);
	}//end testAnUnownedRunCannotPush()

	/**
	 * A proxy-only credential (no injectable secret) cannot push, and says so.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-the-forge-credential-and-the-model-credential-are-separate-and-neither-reaches-the-model
	 */
	public function testAProxyOnlyCredentialCannotPush(): void {
		$resolver = $this->resolver(credentials: [$this->credential(uuid: 'forge-alice', provider: 'github', owner: 'alice')], injectable: false);
		$this->assertRefused(
			code: WorkspaceException::CREDENTIAL_SCOPE_REFUSED,
			call: fn () => $resolver->resolve(ownerUid: 'alice', agent: null)
		);
	}//end testAProxyOnlyCredentialCannotPush()

	/**
	 * Assert a call is refused with a code, and that the message holds no secret.
	 *
	 * @param string   $code The expected error code.
	 * @param callable $call The call.
	 *
	 * @return void
	 */
	private function assertRefused(string $code, callable $call): void {
		try {
			$call();
			self::fail('Expected ' . $code);
		} catch (WorkspaceException $e) {
			self::assertSame($code, $e->getErrorCode());
			self::assertStringNotContainsString('secret-of', $e->getMessage());
		}
	}//end assertRefused()

	/**
	 * The resolver under test.
	 *
	 * @param array<int, ObjectEntity>     $credentials The credential objects.
	 * @param CredentialScopeResolver|null $scopes      A scope resolver double, or null for the real one.
	 * @param bool                         $injectable  Whether the broker returns a secret.
	 *
	 * @return ForgeCredentialResolver
	 */
	private function resolver(array $credentials, ?CredentialScopeResolver $scopes = null, bool $injectable = true): ForgeCredentialResolver {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturn($credentials);

		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('resolveInjectable')->willReturnCallback(
			function (string $credentialId) use ($injectable): ?string {
				$this->injected[] = $credentialId;
				return ($injectable === true ? 'secret-of-' . $credentialId : null);
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($broker);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);

		return new ForgeCredentialResolver(
			scopes: ($scopes ?? new CredentialScopeResolver(objectService: $objects)),
			appConfig: $appConfig,
			container: $container
		);
	}//end resolver()

	/**
	 * A brokered credential object.
	 *
	 * @param string $uuid         The uuid.
	 * @param string $provider     The provider.
	 * @param string $owner        The owner uid.
	 * @param string $scope        personal|organisation.
	 * @param string $organisation The organisation.
	 *
	 * @return ObjectEntity
	 */
	private function credential(string $uuid, string $provider, string $owner, string $scope = 'personal', string $organisation = ''): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner($owner);
		$entity->setObject(['provider' => $provider, 'scope' => $scope, 'organisation' => $organisation, 'allowedApps' => ['hermiq']]);
		return $entity;
	}//end credential()

	/**
	 * An agent object.
	 *
	 * @param array<string, mixed> $data         The agent data.
	 * @param string               $organisation The organisation.
	 *
	 * @return ObjectEntity
	 */
	private function agent(array $data, string $organisation = ''): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('agent-1');
		$entity->setOrganisation($organisation);
		$entity->setObject($data);
		return $entity;
	}//end agent()
}//end class
