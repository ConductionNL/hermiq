<?php

/**
 * Hermiq CredentialScopeResolver unit tests.
 *
 * Covers the personal → organisation → null resolution precedence, and that a
 * credential for a different provider or not allowing `hermiq` is never
 * selected (agent-credentials).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Credential
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
 * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Credential;

use OCA\Hermiq\Service\Credential\CredentialScopeResolver;
use OCA\Hermiq\Service\Credential\PinnedCredentialRefusedException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * CredentialScopeResolver unit tests (agent-credentials).
 *
 * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
 */
class CredentialScopeResolverTest extends TestCase {

	/**
	 * A brokered-credential ObjectEntity: `owner` is the system field
	 * (`ObjectEntity::getOwner()`, mirroring `CredentialBrokerService`'s own
	 * owner guard); `provider`/`scope`/`organisation`/`allowedApps` are
	 * schema data (`getObject()`), mirroring the broker's own
	 * `scopeOf()`/`assertOrganisationMember()`/allowedApps/provider guards.
	 *
	 * @param string $uuid The object uuid.
	 * @param string $provider The provider identifier.
	 * @param string $owner The owning user (personal scope access key).
	 * @param string $scope `personal`|`organisation`.
	 * @param string $organisation The owning organisation (organisation scope only).
	 * @param array<string> $allowedApps App ids permitted to use this credential.
	 *
	 * @return ObjectEntity
	 */
	private function credential(
		string $uuid,
		string $provider,
		string $owner,
		string $scope,
		string $organisation,
		array $allowedApps,
	): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner($owner);
		$entity->setObject(
			[
				'provider' => $provider,
				'scope' => $scope,
				'organisation' => $organisation,
				'allowedApps' => $allowedApps,
			]
		);
		return $entity;
	}//end credential()

	/**
	 * An ObjectService stub returning the given credential objects.
	 *
	 * @param array<int, ObjectEntity> $credentials The stored credentials.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $credentials): ObjectService {
		return new class($credentials) extends ObjectService {
			/**
			 * @param array<int, ObjectEntity> $credentials The stored credentials.
			 */
			public function __construct(
				private array $credentials,
			) {
			}

			public function setRegister(mixed $register): static {
				return $this;
			}

			public function setSchema(mixed $schema): static {
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->credentials;
			}
		};

	}//end objectService()

	/**
	 * The resolver under test.
	 *
	 * @param array<int, ObjectEntity> $credentials The stored credentials.
	 *
	 * @return CredentialScopeResolver
	 */
	private function resolver(array $credentials): CredentialScopeResolver {
		return new CredentialScopeResolver(objectService: $this->objectService($credentials));
	}//end resolver()

	/**
	 * A personal credential is preferred over an organisation credential for
	 * the same provider.
	 *
	 * @return void
	 */
	public function testPersonalCredentialPreferredOverOrganisation(): void {
		$resolver = $this->resolver(
			[
				$this->credential('cred-personal', 'openai', 'alice', 'personal', '', ['hermiq']),
				$this->credential('cred-org', 'openai', 'admin-uid', 'organisation', 'org-a', ['hermiq']),
			]
		);

		$result = $resolver->resolve(provider: 'openai', actingUserId: 'alice', organisation: 'org-a');

		$this->assertSame('cred-personal', $result);

	}//end testPersonalCredentialPreferredOverOrganisation()

	/**
	 * With no personal match, the organisation's credential is used.
	 *
	 * @return void
	 */
	public function testOrganisationCredentialUsedWhenNoPersonalMatch(): void {
		$resolver = $this->resolver(
			[
				$this->credential('cred-org', 'openai', 'admin-uid', 'organisation', 'org-a', ['hermiq']),
			]
		);

		$result = $resolver->resolve(provider: 'openai', actingUserId: 'alice', organisation: 'org-a');

		$this->assertSame('cred-org', $result);

	}//end testOrganisationCredentialUsedWhenNoPersonalMatch()

	/**
	 * With neither a personal nor an organisation match, resolution returns
	 * null (the instance-wide fallback applies unchanged).
	 *
	 * @return void
	 */
	public function testNullWhenNeitherPersonalNorOrganisationMatchExists(): void {
		$resolver = $this->resolver([]);

		$result = $resolver->resolve(provider: 'openai', actingUserId: 'alice', organisation: 'org-a');

		$this->assertNull($result);

	}//end testNullWhenNeitherPersonalNorOrganisationMatchExists()

	/**
	 * A credential for a different provider, or not allowing hermiq, is never
	 * selected — resolution falls through to the organisation, then null,
	 * exactly as if neither existed.
	 *
	 * @return void
	 */
	public function testWrongProviderOrDisallowedCredentialsAreNeverSelected(): void {
		$resolver = $this->resolver(
			[
				// Wrong provider — a personal GitHub credential.
				$this->credential('cred-github', 'github', 'alice', 'personal', '', ['hermiq']),
				// Right provider, but hermiq not allowed.
				$this->credential('cred-fireworks-disallowed', 'fireworks', 'alice', 'personal', '', ['openbuild']),
				// The organisation match that SHOULD win once the above are excluded.
				$this->credential('cred-org-fireworks', 'fireworks', 'admin-uid', 'organisation', 'org-a', ['hermiq']),
			]
		);

		$result = $resolver->resolve(provider: 'fireworks', actingUserId: 'alice', organisation: 'org-a');

		$this->assertSame('cred-org-fireworks', $result);

	}//end testWrongProviderOrDisallowedCredentialsAreNeverSelected()

	/**
	 * An organisation-scope credential belonging to a DIFFERENT organisation
	 * is never selected.
	 *
	 * @return void
	 */
	public function testOrganisationCredentialForADifferentOrganisationIsNeverSelected(): void {
		$resolver = $this->resolver(
			[
				$this->credential('cred-other-org', 'openai', 'admin-uid', 'organisation', 'org-b', ['hermiq']),
			]
		);

		$result = $resolver->resolve(provider: 'openai', actingUserId: 'alice', organisation: 'org-a');

		$this->assertNull($result);

	}//end testOrganisationCredentialForADifferentOrganisationIsNeverSelected()

	/**
	 * When no acting user id is given, the personal branch is skipped
	 * entirely and resolution goes straight to organisation.
	 *
	 * @return void
	 */
	public function testNoActingUserSkipsThePersonalBranch(): void {
		$resolver = $this->resolver(
			[
				$this->credential('cred-org', 'openai', 'admin-uid', 'organisation', 'org-a', ['hermiq']),
			]
		);

		$result = $resolver->resolve(provider: 'openai', actingUserId: null, organisation: 'org-a');

		$this->assertSame('cred-org', $result);

	}//end testNoActingUserSkipsThePersonalBranch()

	/**
	 * When no organisation is given, the organisation branch is skipped
	 * entirely — even if a matching organisation credential exists.
	 *
	 * @return void
	 */
	public function testNoOrganisationSkipsTheOrganisationBranch(): void {
		$resolver = $this->resolver(
			[
				$this->credential('cred-org', 'openai', 'admin-uid', 'organisation', 'org-a', ['hermiq']),
			]
		);

		$result = $resolver->resolve(provider: 'openai', actingUserId: 'alice', organisation: null);

		$this->assertNull($result);

	}//end testNoOrganisationSkipsTheOrganisationBranch()
	/**
	 * A credential pinned for the provider goes before the personal and organisation ones.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-credentials/spec.md#requirement-a-pinned-credential-goes-first-and-is-never-bypassed-req-agcred-002
	 */
	public function testAPinnedCredentialGoesFirst(): void {
		$resolver = $this->resolver(
			[
				$this->credential('cred-personal', 'openai', 'alice', 'personal', '', ['hermiq']),
				$this->credential('cred-digest', 'openai', 'admin-uid', 'organisation', 'org-a', ['hermiq']),
			]
		);

		$result = $resolver->resolve(
			provider: 'openai',
			actingUserId: 'alice',
			organisation: 'org-a',
			pinned: ['openai' => 'cred-digest']
		);

		$this->assertSame('cred-digest', $result);

	}//end testAPinnedCredentialGoesFirst()

	/**
	 * A pin the broker would refuse stops resolution; nothing else is tried.
	 *
	 * Each case would otherwise fall through to alice's own personal key, which is
	 * exactly the wider identity the owner did not choose.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-credentials/spec.md#requirement-a-pinned-credential-goes-first-and-is-never-bypassed-req-agcred-002
	 */
	public function testARefusedPinIsNeverReplacedByAnotherCredential(): void {
		$pins = [
			'no longer allowed for hermiq' => $this->credential('cred-pin', 'openai', 'admin-uid', 'organisation', 'org-a', ['filinq']),
			'another provider' => $this->credential('cred-pin', 'fireworks', 'admin-uid', 'organisation', 'org-a', ['hermiq']),
			'someone else\'s personal key' => $this->credential('cred-pin', 'openai', 'bob', 'personal', '', ['hermiq']),
			'another organisation' => $this->credential('cred-pin', 'openai', 'admin-uid', 'organisation', 'org-b', ['hermiq']),
		];
		$pins['deleted'] = null;

		foreach ($pins as $case => $pin) {
			$credentials = [$this->credential('cred-personal', 'openai', 'alice', 'personal', '', ['hermiq'])];
			if ($pin !== null) {
				$credentials[] = $pin;
			}

			try {
				$this->resolver($credentials)->resolve(
					provider: 'openai',
					actingUserId: 'alice',
					organisation: 'org-a',
					pinned: ['openai' => 'cred-pin']
				);
				$this->fail('A refused pin must stop the turn: ' . $case);
			} catch (PinnedCredentialRefusedException $e) {
				$this->assertSame('The credential pinned to this agent cannot be used for this run.', $e->getMessage(), $case);
			}
		}

	}//end testARefusedPinIsNeverReplacedByAnotherCredential()

	/**
	 * A pin for another provider, or no pin at all, leaves resolution as it was.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-credentials/spec.md#requirement-a-pinned-credential-goes-first-and-is-never-bypassed-req-agcred-002
	 */
	public function testAnUnpinnedProviderResolvesAsBefore(): void {
		$resolver = $this->resolver(
			[$this->credential('cred-personal', 'openai', 'alice', 'personal', '', ['hermiq'])]
		);

		$this->assertSame(
			'cred-personal',
			$resolver->resolve(provider: 'openai', actingUserId: 'alice', organisation: 'org-a', pinned: ['fireworks' => 'cred-fw'])
		);
		$this->assertSame(
			'cred-personal',
			$resolver->resolve(provider: 'openai', actingUserId: 'alice', organisation: 'org-a', pinned: [])
		);
		$this->assertSame(
			'cred-personal',
			$resolver->resolve(provider: 'openai', actingUserId: 'alice', organisation: 'org-a', pinned: ['openai' => '  '])
		);
		$this->assertSame(
			'cred-personal',
			$resolver->resolve(provider: 'openai', actingUserId: 'alice', organisation: 'org-a', pinned: ['openai' => 7])
		);

	}//end testAnUnpinnedProviderResolvesAsBefore()
}//end class
