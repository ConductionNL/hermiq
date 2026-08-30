<?php

/**
 * Unit tests for DashboardController (the app's default route).
 *
 * Covers the two registered routes — `page()` (`/`) and `catchAll()` (`/{path}`,
 * the Vue history-mode deep link) — and the initial state they hand the SPA.
 *
 * The kill-switch capability is UX gating only; the endpoints remain the real
 * authorization boundary. What is asserted here is that the flag TRACKS the
 * server-side answer rather than being hardcoded on, because a flag stuck at
 * `true` renders a control every user can press and then be refused by.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
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
 * @spec openspec/specs/dashboard-page/spec.md#REQ-DASH-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\DashboardController;
use OCA\Hermiq\Tenant\ManageableOrganisations;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the dashboard page routes and the initial state they provide.
 *
 * @spec openspec/specs/dashboard-page/spec.md#REQ-DASH-001
 */
class DashboardControllerTest extends TestCase {
	/**
	 * The initial state recorded by the last controller built.
	 *
	 * A recording double rather than `expects()->with()`: the assertions here
	 * are about the VALUE handed to the SPA, and reading it back after the call
	 * keeps each test to one question.
	 *
	 * @var array<string, mixed>
	 */
	private array $state = [];

	/**
	 * Build the controller, recording every initial-state key into `$this->state`.
	 *
	 * @param string|null $uid The caller's uid, or null for an anonymous request.
	 * @param bool $isAdmin Whether that uid is an instance admin.
	 * @param array<int, array{id: string, label: string}> $organisations The organisations
	 *   the tenant contract resolves for the caller.
	 * @param bool $opencatalogi Whether the publication leaf is installed.
	 *
	 * @return DashboardController
	 */
	private function controller(
		?string $uid,
		bool $isAdmin = false,
		array $organisations = [],
		bool $opencatalogi = false,
	): DashboardController {
		$this->state = [];

		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(
			function (string $key, $value): void {
				$this->state[$key] = $value;
			}
		);

		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		$tenants = $this->createMock(ManageableOrganisations::class);
		$tenants->method('forUser')->willReturn($organisations);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($opencatalogi);

		return new DashboardController(
			$this->createMock(IRequest::class),
			$initialState,
			$session,
			$groupManager,
			$tenants,
			$appManager
		);

	}//end controller()

	/**
	 * page() renders the SPA template from this app.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dashboard-page/spec.md#REQ-DASH-001
	 */
	public function testPageRendersTheAppTemplate(): void {
		$response = $this->controller('alice')->page();

		$this->assertSame('index', $response->getTemplateName());
		$this->assertSame('hermiq', $response->getApp());

	}//end testPageRendersTheAppTemplate()

	/**
	 * catchAll() renders the same template, so a deep link is not a 404.
	 *
	 * This is the whole reason the route exists: the router is history mode, so
	 * a reload on `/agents/7` reaches the server as a path the app must answer
	 * with the shell rather than with nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dashboard-page/spec.md#REQ-DASH-002
	 */
	public function testCatchAllServesTheSameShellForDeepLinks(): void {
		$response = $this->controller('alice')->catchAll();

		$this->assertSame('index', $response->getTemplateName());
		$this->assertSame('hermiq', $response->getApp());
		$this->assertArrayHasKey('can_manage_killswitch', $this->state);

	}//end testCatchAllServesTheSameShellForDeepLinks()

	/**
	 * An anonymous request still renders, and grants nothing.
	 *
	 * The default route must not depend on a session to produce a start screen;
	 * what it must not do is hand an unauthenticated visitor a kill-switch.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dashboard-page/spec.md#REQ-DASH-001
	 */
	public function testAnonymousRequestRendersWithoutTheKillSwitch(): void {
		$response = $this->controller(null)->page();

		$this->assertSame('index', $response->getTemplateName());
		$this->assertFalse($this->state['can_manage_killswitch']);
		$this->assertSame([], $this->state['managed_organisations']);
		$this->assertFalse($this->state['is_admin']);

	}//end testAnonymousRequestRendersWithoutTheKillSwitch()

	/**
	 * A plain user who owns no organisation is not offered the kill-switch.
	 *
	 * THE NEGATIVE ARM. Without it the two positive cases below would pass just
	 * as happily against a controller that hardcoded the flag to `true`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-ui/tasks.md#task-4-1
	 */
	public function testPlainUserWithNoOrganisationCannotManageTheKillSwitch(): void {
		$this->controller('bob', false, [])->page();

		$this->assertFalse($this->state['can_manage_killswitch']);
		$this->assertSame([], $this->state['managed_organisations']);

	}//end testPlainUserWithNoOrganisationCannotManageTheKillSwitch()

	/**
	 * An organisation owner may manage the kill-switch for the orgs they own.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-ui/tasks.md#task-4-1
	 */
	public function testOrganisationOwnerMayManageTheKillSwitch(): void {
		$owned = [['id' => 'org-a', 'label' => 'Org A']];

		$this->controller('alice', false, $owned)->page();

		$this->assertTrue($this->state['can_manage_killswitch']);
		$this->assertSame($owned, $this->state['managed_organisations']);
		$this->assertFalse($this->state['is_admin']);

	}//end testOrganisationOwnerMayManageTheKillSwitch()

	/**
	 * An instance admin may manage it even with no organisation resolved.
	 *
	 * The empty list is the case worth pinning: an admin on an instance where
	 * OpenRegister is absent still governs the switch, because the contract
	 * answers "no manageable organisation" rather than throwing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-ui/tasks.md#task-4-1
	 */
	public function testInstanceAdminMayManageTheKillSwitchWithNoOrganisations(): void {
		$this->controller('admin', true, [])->page();

		$this->assertTrue($this->state['can_manage_killswitch']);
		$this->assertTrue($this->state['is_admin']);

	}//end testInstanceAdminMayManageTheKillSwitchWithNoOrganisations()

	/**
	 * The publication seam reports whether the OpenCatalogi leaf is installed.
	 *
	 * Both arms, because a flag that is always false hides the publish actions
	 * on an instance that does have the leaf — and one always true offers
	 * actions that cannot work.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dashboard-page/spec.md#REQ-DASH-001
	 */
	public function testPublicationSeamTracksLeafAvailability(): void {
		$this->controller('alice', false, [], true)->page();
		$this->assertTrue($this->state['opencatalogi_available']);

		$this->controller('alice', false, [], false)->page();
		$this->assertFalse($this->state['opencatalogi_available']);

	}//end testPublicationSeamTracksLeafAvailability()
}//end class
