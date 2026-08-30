<?php

/**
 * Unit tests for ActionAuthService.
 *
 * Exercises the ADR-023 action authorization contract:
 *   - Admin always passes (break-glass)
 *   - Default matrix entry is ["admin"] → non-admin blocked
 *   - Non-admin passes only when their groups intersect the matrix entry
 *   - Malformed matrix JSON falls back to default-deny
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\ActionAuthService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ADR-023 action-authorization service.
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */
class ActionAuthServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var ActionAuthService
	 */
	private ActionAuthService $service;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * Mock IGroupManager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * Wire up fresh mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->service = new ActionAuthService(
			appConfig: $this->appConfig,
			groupManager: $this->groupManager,
		);

	}//end setUp()

	/**
	 * Helper to create a mock IUser that reports the given UID.
	 *
	 * @param string $uid The user ID to report.
	 *
	 * @return IUser&MockObject
	 */
	private function mockUser(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end mockUser()

	/**
	 * Set the appConfig to return the given matrix as JSON.
	 *
	 * @param array<string, array<int, string>> $matrix The matrix to serve.
	 *
	 * @return void
	 */
	private function setMatrix(array $matrix): void {
		$this->appConfig->method('getValueString')
			->willReturn(json_encode($matrix));

	}//end setMatrix()

	/**
	 * Admin always passes, regardless of the matrix.
	 *
	 * @return void
	 */
	public function testAdminAlwaysPasses(): void {
		$user = $this->mockUser('sysadmin');
		$this->groupManager->method('isAdmin')->willReturn(true);
		// Matrix explicitly denies everyone including nonexistent action.
		$this->setMatrix(['minutes.generate-draft' => []]);

		$this->service->requireAction($user, 'minutes.generate-draft');
		// No exception → pass.
		$this->assertTrue($this->service->can($user, 'any.action.not.in.matrix'));

	}//end testAdminAlwaysPasses()

	/**
	 * Non-admin is denied when the action is not in the matrix (default ["admin"]).
	 *
	 * @return void
	 */
	public function testNonAdminDeniedByDefaultWhenActionMissing(): void {
		$user = $this->mockUser('jane');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroupIds')->willReturn(['users']);
		$this->setMatrix([]);

		$this->expectException(OCSForbiddenException::class);
		$this->service->requireAction($user, 'minutes.generate-draft');

	}//end testNonAdminDeniedByDefaultWhenActionMissing()

	/**
	 * Non-admin is denied when the matrix entry is ["admin"] only.
	 *
	 * @return void
	 */
	public function testNonAdminDeniedWhenMatrixIsAdminOnly(): void {
		$user = $this->mockUser('jane');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroupIds')->willReturn(['users', 'chairs']);
		$this->setMatrix(['minutes.generate-draft' => ['admin']]);

		$this->expectException(OCSForbiddenException::class);
		$this->service->requireAction($user, 'minutes.generate-draft');

	}//end testNonAdminDeniedWhenMatrixIsAdminOnly()

	/**
	 * Non-admin passes when their group intersects the matrix entry.
	 *
	 * @return void
	 */
	public function testNonAdminPassesWhenGroupMatches(): void {
		$user = $this->mockUser('jane');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroupIds')->willReturn(['chairs', 'users']);
		$this->setMatrix(['minutes.generate-draft' => ['chairs', 'secretaries']]);

		$this->service->requireAction($user, 'minutes.generate-draft');
		$this->assertTrue(true, 'No exception thrown — user is authorized.');

	}//end testNonAdminPassesWhenGroupMatches()

	/**
	 * Non-admin is denied when their groups don't intersect — even if admin is in the entry.
	 *
	 * Admin in the entry is a display hint for the matrix UI, not a real group
	 * membership check for non-admins.
	 *
	 * @return void
	 */
	public function testNonAdminDeniedEvenWithAdminInEntry(): void {
		$user = $this->mockUser('jane');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroupIds')->willReturn(['users']);
		$this->setMatrix(['minutes.generate-draft' => ['admin', 'chairs']]);

		$this->expectException(OCSForbiddenException::class);
		$this->service->requireAction($user, 'minutes.generate-draft');

	}//end testNonAdminDeniedEvenWithAdminInEntry()

	/**
	 * `can()` is the non-throwing variant that returns bool.
	 *
	 * @return void
	 */
	public function testCanReturnsBool(): void {
		$user = $this->mockUser('jane');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroupIds')->willReturn(['users']);
		$this->setMatrix(['minutes.generate-draft' => ['chairs']]);

		$this->assertFalse($this->service->can($user, 'minutes.generate-draft'));

	}//end testCanReturnsBool()

	/**
	 * Malformed matrix JSON falls back to empty matrix (default-deny).
	 *
	 * @return void
	 */
	public function testMalformedMatrixFallsBackToDeny(): void {
		$user = $this->mockUser('jane');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroupIds')->willReturn(['chairs']);
		$this->appConfig->method('getValueString')->willReturn('not valid json {[');

		$this->expectException(OCSForbiddenException::class);
		$this->service->requireAction($user, 'minutes.generate-draft');

	}//end testMalformedMatrixFallsBackToDeny()

	/**
	 * `getAllowedGroups` returns ["admin"] for an unknown action.
	 *
	 * @return void
	 */
	public function testGetAllowedGroupsDefaultsToAdmin(): void {
		$this->setMatrix([]);

		$this->assertSame(['admin'], $this->service->getAllowedGroups('unknown.action'));

	}//end testGetAllowedGroupsDefaultsToAdmin()

}//end class
