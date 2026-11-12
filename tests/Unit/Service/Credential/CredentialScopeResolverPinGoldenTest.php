<?php

/**
 * Hermiq CredentialScopeResolver golden tests for the pinned credential.
 *
 * Pins, for the pinned path of `resolve()`, which pins are admitted and which
 * are refused: the acting user's own personal pin, a personal pin with no
 * acting user, an organisation pin with no organisation, a padded pin, and a
 * pin whose uuid appears twice. Written before `resolve()` was split into
 * named steps, so the split can be shown to keep every outcome.
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
 * @spec openspec/specs/agent-credentials/spec.md#requirement-a-pinned-credential-goes-first-and-is-never-bypassed-req-agcred-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Credential;

use OCA\Hermiq\Service\Credential\CredentialScopeResolver;
use OCA\Hermiq\Service\Credential\PinnedCredentialRefusedException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes of a pinned credential (agent-credentials).
 *
 * @spec openspec/specs/agent-credentials/spec.md#requirement-a-pinned-credential-goes-first-and-is-never-bypassed-req-agcred-002
 */
class CredentialScopeResolverPinGoldenTest extends TestCase {

	/**
	 * A brokered-credential object.
	 *
	 * @param string $uuid The object uuid.
	 * @param array<string, mixed> $fields `provider`, `owner`, `scope`, `organisation`, `allowedApps`.
	 *
	 * @return ObjectEntity
	 */
	private function credential(string $uuid, array $fields): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner($fields['owner'] ?? 'alice');
		$entity->setObject(
			[
				'provider' => $fields['provider'] ?? 'openai',
				'scope' => $fields['scope'] ?? 'personal',
				'organisation' => $fields['organisation'] ?? '',
				'allowedApps' => $fields['allowedApps'] ?? ['hermiq'],
			]
		);
		return $entity;
	}//end credential()

	/**
	 * The resolver over the given stored credentials.
	 *
	 * @param array<int, ObjectEntity> $credentials The stored credentials.
	 *
	 * @return CredentialScopeResolver
	 */
	private function resolver(array $credentials): CredentialScopeResolver {
		$objects = new class($credentials) extends ObjectService {
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

		return new CredentialScopeResolver(objectService: $objects);
	}//end resolver()

	/**
	 * Resolve with a pin for `openai`, returning the uuid or 'REFUSED'.
	 *
	 * @param array<int, ObjectEntity> $credentials The stored credentials.
	 * @param array<string, mixed> $call `user`, `organisation`, `pin`.
	 *
	 * @return string|null The outcome.
	 */
	private function outcome(array $credentials, array $call): ?string {
		$fallback = $this->credential('cred-fallback', ['owner' => 'alice']);
		try {
			return $this->resolver(array_merge($credentials, [$fallback]))->resolve(
				provider: 'openai',
				actingUserId: $call['user'],
				organisation: $call['organisation'],
				pinned: ['openai' => $call['pin']]
			);
		} catch (PinnedCredentialRefusedException $e) {
			$this->assertSame('The credential pinned to this agent cannot be used for this run.', $e->getMessage());
			return 'REFUSED';
		}
	}//end outcome()

	/**
	 * Every pinned case and its outcome.
	 *
	 * @return void
	 */
	public function testPinnedOutcomes(): void {
		$personal = $this->credential('cred-pin', ['owner' => 'alice']);
		$organisation = $this->credential('cred-pin', ['owner' => 'admin', 'scope' => 'organisation', 'organisation' => 'org-a']);
		$blankOrganisation = $this->credential('cred-pin', ['owner' => 'admin', 'scope' => 'organisation', 'organisation' => '']);
		$unknownScope = $this->credential('cred-pin', ['owner' => 'alice', 'scope' => 'team']);

		$cases = [
			'own personal pin' => [[$personal], ['user' => 'alice', 'organisation' => 'org-a', 'pin' => 'cred-pin'], 'cred-pin'],
			'own personal pin, no organisation' => [[$personal], ['user' => 'alice', 'organisation' => null, 'pin' => 'cred-pin'], 'cred-pin'],
			'padded pin is trimmed' => [[$personal], ['user' => 'alice', 'organisation' => null, 'pin' => "  cred-pin\t"], 'cred-pin'],
			'personal pin, no acting user' => [[$personal], ['user' => null, 'organisation' => 'org-a', 'pin' => 'cred-pin'], 'REFUSED'],
			'personal pin, empty acting user' => [[$this->credential('cred-pin', ['owner' => ''])], ['user' => '', 'organisation' => 'org-a', 'pin' => 'cred-pin'], 'REFUSED'],
			'organisation pin, own organisation' => [[$organisation], ['user' => null, 'organisation' => 'org-a', 'pin' => 'cred-pin'], 'cred-pin'],
			'organisation pin, no organisation' => [[$organisation], ['user' => 'admin', 'organisation' => null, 'pin' => 'cred-pin'], 'REFUSED'],
			'organisation pin, both blank' => [[$blankOrganisation], ['user' => 'admin', 'organisation' => '', 'pin' => 'cred-pin'], 'REFUSED'],
			'unknown scope counts as personal' => [[$unknownScope], ['user' => 'alice', 'organisation' => null, 'pin' => 'cred-pin'], 'cred-pin'],
			'first of two equal uuids decides' => [
				[$this->credential('cred-pin', ['provider' => 'fireworks']), $personal],
				['user' => 'alice', 'organisation' => null, 'pin' => 'cred-pin'],
				'REFUSED',
			],
			'null pin falls back' => [[$personal], ['user' => 'alice', 'organisation' => null, 'pin' => null], 'cred-pin'],
		];

		foreach ($cases as $name => [$credentials, $call, $expected]) {
			$this->assertSame($expected, $this->outcome($credentials, $call), $name);
		}

	}//end testPinnedOutcomes()

	/**
	 * Without a usable pin, personal precedes organisation, and blank identities skip their branch.
	 *
	 * @return void
	 */
	public function testUnpinnedOutcomes(): void {
		$credentials = [
			$this->credential('cred-org', ['owner' => 'admin', 'scope' => 'organisation', 'organisation' => 'org-a']),
			$this->credential('cred-alice', ['owner' => 'alice']),
		];

		$this->assertSame('cred-alice', $this->resolver($credentials)->resolve(provider: 'openai', actingUserId: 'alice', organisation: 'org-a'));
		$this->assertSame('cred-org', $this->resolver($credentials)->resolve(provider: 'openai', actingUserId: '', organisation: 'org-a'));
		$this->assertSame('cred-org', $this->resolver($credentials)->resolve(provider: 'openai', actingUserId: 'bob', organisation: 'org-a'));
		$this->assertNull($this->resolver($credentials)->resolve(provider: 'openai', actingUserId: 'bob', organisation: ''));
		$this->assertNull($this->resolver($credentials)->resolve(provider: 'openai', actingUserId: null, organisation: null));
		$this->assertNull($this->resolver($credentials)->resolve(provider: 'fireworks', actingUserId: 'alice', organisation: 'org-a'));

	}//end testUnpinnedOutcomes()
}//end class
