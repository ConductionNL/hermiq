<?php

/**
 * Who may read hermiq's private objects through OpenRegister's object API (hermiq#976).
 *
 * Every hermiq schema used to declare `"read": ["authenticated"]`. OpenRegister
 * grants that pseudo-group to every signed-in user before any group check, so
 * `GET /index.php/apps/openregister/api/objects/hermiq/agent` returned private
 * agents, prompts included, to anyone in the organisation, and the same path
 * returned other people's sessions, turns and memory.
 *
 * This test reads `lib/Settings/hermiq_register.json` and decides each read the
 * way OpenRegister does, for a caller who is NOT an admin:
 *
 * - the object's owner is admitted before any rule
 *   (`PermissionHandler::hasGroupPermission()`, `MagicRbacHandler` owner admits);
 * - a plain string entry grants its group, and `authenticated` is every signed-in user;
 * - a `{group, match}` entry grants when the caller is in the group AND every match
 *   condition holds (`ConditionMatcher::objectMatchesConditions()`): `$userId` is the
 *   caller's uid, `$user.groups` the caller's groups, `$contains` is true when the
 *   object's array holds the operand or any one of them, and a `null` value matches
 *   an unset property;
 * - an action the block does not list is refused.
 *
 * The evaluator is small on purpose and only knows those rules. What OpenRegister
 * actually answers is the live check in the PR: two users, one organisation.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Settings
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
 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-group-sharing-is-enforced-wherever-an-agent-is-read-or-run-req-agshare-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Decides object-API reads against the register's declared read rules.
 *
 * @coversNothing Guards a declarative register file, not a PHP class.
 */
class PrivateSchemaReadRulesTest extends TestCase {

	/**
	 * The eight schemas #976 names, by component key.
	 *
	 * @var array<int, string>
	 */
	private const PRIVATE_SCHEMAS = [
		'Agent',
		'Session',
		'SessionTurn',
		'Memory',
		'UserProfile',
		'Conversation',
		'Message',
		'IntakeConversation',
	];

	/**
	 * The owner of every fixture object.
	 *
	 * @var string
	 */
	private const OWNER = 'alice';

	/**
	 * The caller who is not the owner, in the same organisation.
	 *
	 * @var string
	 */
	private const COLLEAGUE = 'bob';

	/**
	 * One schema's `authorization` block.
	 *
	 * @param string $schema The component key.
	 *
	 * @return array<string, mixed> The block.
	 */
	private function authorization(string $schema): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'),
			true
		);
		$this->assertIsArray($register, 'The register must be valid JSON.');

		$block = ($register['components']['schemas'][$schema]['authorization'] ?? null);
		$this->assertIsArray($block, $schema . ' must declare an authorization block.');
		$this->assertNotEmpty($block, 'An empty block is evaluated as OPEN by OpenRegister.');

		return $block;
	}//end authorization()

	/**
	 * Decide a non-admin read the way OpenRegister does.
	 *
	 * @param string $schema The component key.
	 * @param array<string, mixed> $object The object's properties.
	 * @param string $caller The caller's uid.
	 * @param array<int, string> $callerGroups The caller's group ids.
	 *
	 * @return bool True when the caller may read the object.
	 */
	private function mayRead(string $schema, array $object, string $caller, array $callerGroups = []): bool {
		if ($caller === self::OWNER) {
			return true;
		}

		$rules = ($this->authorization(schema: $schema)['read'] ?? []);
		foreach ($rules as $rule) {
			if (is_string($rule) === true) {
				if ($rule === 'authenticated' || in_array($rule, $callerGroups, true) === true) {
					return true;
				}

				continue;
			}

			$group = ($rule['group'] ?? null);
			if ($group !== 'authenticated' && in_array($group, $callerGroups, true) === false) {
				continue;
			}

			if ($this->clauseHolds(match: ($rule['match'] ?? []), object: $object, caller: $caller, callerGroups: $callerGroups) === true) {
				return true;
			}
		}

		return false;
	}//end mayRead()

	/**
	 * Whether every condition of a match clause holds.
	 *
	 * @param array<string, mixed> $match The clause.
	 * @param array<string, mixed> $object The object's properties.
	 * @param string $caller The caller's uid.
	 * @param array<int, string> $callerGroups The caller's group ids.
	 *
	 * @return bool True when all conditions hold.
	 */
	private function clauseHolds(array $match, array $object, string $caller, array $callerGroups): bool {
		foreach ($match as $property => $expected) {
			$actual = ($object[$property] ?? null);
			if ($property === '_owner') {
				$actual = self::OWNER;
			}

			$expected = $this->resolve(value: $expected, caller: $caller, callerGroups: $callerGroups);
			if (is_array($expected) === true && array_key_exists('$contains', $expected) === true) {
				$wanted = (array)$expected['$contains'];
				if (is_array($actual) === false || array_intersect($wanted, $actual) === []) {
					return false;
				}

				continue;
			}

			if ($actual !== $expected) {
				return false;
			}
		}

		return true;
	}//end clauseHolds()

	/**
	 * Resolve `$userId` and `$user.groups`, inside operators too.
	 *
	 * @param mixed $value The declared value.
	 * @param string $caller The caller's uid.
	 * @param array<int, string> $callerGroups The caller's group ids.
	 *
	 * @return mixed The resolved value.
	 */
	private function resolve(mixed $value, string $caller, array $callerGroups): mixed {
		if (is_array($value) === true) {
			return array_map(fn ($operand) => $this->resolve(value: $operand, caller: $caller, callerGroups: $callerGroups), $value);
		}

		if ($value === '$userId' || $value === '$user') {
			return $caller;
		}

		if ($value === '$user.groups') {
			return $callerGroups;
		}

		return $value;
	}//end resolve()

	/**
	 * No schema #976 names may grant a read to every signed-in user, or to anyone.
	 *
	 * @return void
	 */
	public function testNoPrivateSchemaGrantsAnUnconditionalRead(): void {
		foreach (self::PRIVATE_SCHEMAS as $schema) {
			foreach (($this->authorization(schema: $schema)['read'] ?? []) as $rule) {
				$this->assertFalse(
					is_string($rule) === true && in_array($rule, ['authenticated', 'public'], true) === true,
					$schema . ' grants read to "' . (is_string($rule) === true ? $rule : '') . '", so every signed-in user reads every object (hermiq#976).'
				);
				$this->assertFalse(
					is_array($rule) === true && empty($rule['match']) === true,
					$schema . ' has a group rule without a match clause, which grants the whole group every object.'
				);
			}
		}
	}//end testNoPrivateSchemaGrantsAnUnconditionalRead()

	/**
	 * A colleague cannot read a private agent, and so cannot read its prompt.
	 *
	 * @return void
	 */
	public function testAColleagueCannotReadAPrivateAgent(): void {
		$agent = ['isPrivate' => true, 'invitedUsers' => [], 'groups' => [], 'prompt' => 'secret'];

		$this->assertFalse($this->mayRead(schema: 'Agent', object: $agent, caller: self::COLLEAGUE, callerGroups: ['staff']));
		$this->assertTrue($this->mayRead(schema: 'Agent', object: $agent, caller: self::OWNER));
	}//end testAColleagueCannotReadAPrivateAgent()

	/**
	 * The Agent rule admits exactly who AgentAccessService admits.
	 *
	 * @return void
	 */
	public function testTheAgentRuleMirrorsTheSharingPredicate(): void {
		$this->assertTrue(
			$this->mayRead(schema: 'Agent', object: ['isPrivate' => false], caller: self::COLLEAGUE),
			'An organisation-wide agent stays readable by colleagues.'
		);
		$this->assertTrue(
			$this->mayRead(schema: 'Agent', object: [], caller: self::COLLEAGUE),
			'An agent with isPrivate unset is shared, as AgentAccessService treats it.'
		);
		$this->assertTrue(
			$this->mayRead(schema: 'Agent', object: ['isPrivate' => true, 'invitedUsers' => [self::COLLEAGUE]], caller: self::COLLEAGUE),
			'An invited user reads the private agent.'
		);
		$this->assertTrue(
			$this->mayRead(schema: 'Agent', object: ['isPrivate' => true, 'groups' => ['planning-desk']], caller: self::COLLEAGUE, callerGroups: ['staff', 'planning-desk']),
			'A member of one of the agent\'s groups reads the private agent (hermiq#951).'
		);
		$this->assertFalse(
			$this->mayRead(schema: 'Agent', object: ['isPrivate' => true, 'groups' => ['planning-desk']], caller: self::COLLEAGUE, callerGroups: ['staff']),
			'A user outside the agent\'s groups does not.'
		);
	}//end testTheAgentRuleMirrorsTheSharingPredicate()

	/**
	 * Somebody else's chat is not readable; a listed participant's is.
	 *
	 * @return void
	 */
	public function testSessionsAreReadByTheirUserAndParticipantsOnly(): void {
		foreach (['Session', 'Conversation'] as $schema) {
			$this->assertFalse(
				$this->mayRead(schema: $schema, object: ['userId' => self::OWNER, 'participants' => []], caller: self::COLLEAGUE),
				$schema . ': a colleague reads another person\'s chat.'
			);
			$this->assertTrue(
				$this->mayRead(schema: $schema, object: ['userId' => self::OWNER, 'participants' => [self::COLLEAGUE]], caller: self::COLLEAGUE),
				$schema . ': a listed participant cannot read the shared session.'
			);
			$this->assertTrue(
				$this->mayRead(schema: $schema, object: ['userId' => self::COLLEAGUE], caller: self::COLLEAGUE),
				$schema . ': the user a session was opened for cannot read it.'
			);
		}
	}//end testSessionsAreReadByTheirUserAndParticipantsOnly()

	/**
	 * Turns, messages, memory and intake conversations are their owner's only.
	 *
	 * @return void
	 */
	public function testOwnerOnlySchemasRefuseAColleague(): void {
		foreach (['SessionTurn', 'Message', 'Memory', 'IntakeConversation'] as $schema) {
			$this->assertFalse(
				$this->mayRead(schema: $schema, object: ['content' => 'private'], caller: self::COLLEAGUE, callerGroups: ['staff']),
				$schema . ' is readable by a colleague.'
			);
			$this->assertTrue($this->mayRead(schema: $schema, object: [], caller: self::OWNER));
		}
	}//end testOwnerOnlySchemasRefuseAColleague()

	/**
	 * A profile is readable by the person it describes, and by nobody else.
	 *
	 * @return void
	 */
	public function testAProfileIsReadByItsSubjectOnly(): void {
		$this->assertTrue($this->mayRead(schema: 'UserProfile', object: ['subjectUid' => self::COLLEAGUE], caller: self::COLLEAGUE));
		$this->assertFalse($this->mayRead(schema: 'UserProfile', object: ['subjectUid' => 'carol'], caller: self::COLLEAGUE));
	}//end testAProfileIsReadByItsSubjectOnly()

	/**
	 * Write actions stay omitted on all eight, which is what keeps writes owner-only.
	 *
	 * @return void
	 */
	public function testWriteActionsStayOmitted(): void {
		foreach (self::PRIVATE_SCHEMAS as $schema) {
			$block = $this->authorization(schema: $schema);
			foreach (['create', 'update', 'delete', 'scope'] as $key) {
				$this->assertArrayNotHasKey($key, $block, $schema . ' must not list ' . $key . '; see AgentAuthorizationTest.');
			}
		}
	}//end testWriteActionsStayOmitted()
}//end class
