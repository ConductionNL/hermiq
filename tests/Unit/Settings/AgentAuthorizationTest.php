<?php

declare(strict_types=1);

/**
 * Agent schema authorization guard (agent-object-owner-authorization).
 *
 * 🔴 This test exists because the fix LOOKS LIKE AN OVERSIGHT.
 *
 * The Agent schema grants `read` and lists no write action at all. A reader who
 * does not know OpenRegister's rule sees a half-finished block and completes
 * it — and completing it reopens a reproduced vulnerability in which any
 * authenticated user could rewrite any agent's tool grants, prompt, model and
 * delegationAllowlist by PUTing the OpenRegister objects API directly.
 *
 * The rule: OpenRegister fails closed on an action a NON-EMPTY authorization
 * block does not list. `MagicRbacHandler` — "an omitted action yields owner-only
 * rows"; `PermissionHandler` — "if authorization is configured but the action is
 * not granted, access is denied". Owners and admins are admitted before that
 * check, so the owner keeps full control. Omission IS the mechanism.
 *
 * Verified live four ways: non-owner UPDATE 200 -> 403, non-owner READ stays
 * 200, owner UPDATE stays 200, and a refused attack leaves the grants untouched.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

namespace OCA\Hermiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing Guards a declarative register file, not a PHP class.
 */
class AgentAuthorizationTest extends TestCase {

	/**
	 * The Agent schema's authorization block.
	 *
	 * @return array<string,mixed> The block.
	 */
	private function agentAuthorization(): array {
		$path = __DIR__ . '/../../../lib/Settings/hermiq_register.json';
		$this->assertFileExists($path, 'The register file must exist.');

		$register = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($register, 'The register must be valid JSON.');

		$agent = ($register['components']['schemas']['Agent'] ?? null);
		$this->assertIsArray($agent, 'The Agent schema must exist.');

		$authorization = ($agent['authorization'] ?? null);
		$this->assertIsArray(
			$authorization,
			'Agent MUST declare an authorization block. Without one OpenRegister treats '
			. 'the schema as OPEN for update, and any authenticated user can rewrite any '
			. 'agent — reproduced, HTTP 200.'
		);

		return $authorization;
	}//end agentAuthorization()

	/**
	 * Read stays open, so invited users keep seeing shared agents.
	 */
	public function testReadIsGrantedToAuthenticatedUsers(): void {
		$this->assertSame(['authenticated'], ($this->agentAuthorization()['read'] ?? null));

	}//end testReadIsGrantedToAuthenticatedUsers()

	/**
	 * 🔴 The load-bearing assertion. Every write action MUST stay absent.
	 *
	 * If this fails, read the failure message before "fixing" the file.
	 */
	public function testWriteActionsAreOmittedSoTheyStayOwnerOnly(): void {
		$authorization = $this->agentAuthorization();

		foreach (['create', 'update', 'delete'] as $action) {
			$this->assertArrayNotHasKey(
				$action,
				$authorization,
				sprintf(
					'Agent.authorization MUST NOT list "%s". The omission is not an oversight — it '
					. 'is the mechanism: OpenRegister fails closed on an action a non-empty block '
					. 'does not list, which is what makes writes owner-only. Listing it (even as '
					. '["authenticated"]) reopens a reproduced hole where any user could grant any '
					. 'agent the ability to send irreversible external email. Owners and admins are '
					. 'already admitted before this check, so nothing legitimate needs it.',
					$action
				)
			);
		}

	}//end testWriteActionsAreOmittedSoTheyStayOwnerOnly()

	/**
	 * `scope` must stay unused: it is a single key covering EVERY action, so it
	 * would close reads for invited users at the same time as closing writes.
	 */
	public function testScopeIsNotUsed(): void {
		$this->assertArrayNotHasKey(
			'scope',
			$this->agentAuthorization(),
			'scope covers every action at once and would break agent sharing — hermiq shares via '
			. 'its own invitedUsers/groups fields and never projects them into OpenRegister grants.'
		);

	}//end testScopeIsNotUsed()

	/**
	 * A non-empty block is what arms the fail-closed rule; an empty one is
	 * evaluated as OPEN and is exactly the pre-fix state.
	 */
	public function testTheBlockIsNotEmpty(): void {
		$this->assertNotEmpty(
			$this->agentAuthorization(),
			'An EMPTY authorization block is evaluated as OPEN for update — the pre-fix state.'
		);

	}//end testTheBlockIsNotEmpty()
}//end class
