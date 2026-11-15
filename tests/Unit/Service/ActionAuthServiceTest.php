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
use OCA\Hermiq\Service\ActionMatrix;
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
	 * The matrix the service reads.
	 *
	 * @var ActionMatrix
	 */
	private ActionMatrix $matrix;

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
		$this->matrix = new ActionMatrix(appConfig: $this->appConfig);
		$this->service = new ActionAuthService(
			actionMatrix: $this->matrix,
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
	 * Naming an agent's acting user is admin-only: seeded so, and so by
	 * default when the stored matrix lacks it (agent-acting-identity-picker 1.1).
	 *
	 * @return void
	 */
	public function testNamingAnActingUserIsAdminOnly(): void {
		$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/actions.seed.json'), true);
		$this->assertSame(['admin'], $seed['actions']['agent.set-acting-user']);

		$user = $this->mockUser('pieter');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroupIds')->willReturn(['users', 'owners']);
		$this->setMatrix([]);

		$this->assertSame(['admin'], $this->matrix->getAllowedGroups('agent.set-acting-user'));
		$this->assertFalse($this->service->can($user, 'agent.set-acting-user'));

	}//end testNamingAnActingUserIsAdminOnly()

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
	 * The stored matrix normalises exactly: unreadable or non-object JSON is empty,
	 * a non-string action or non-list groups entry is dropped, and groups keep their
	 * first occurrence of each non-empty string, in order.
	 *
	 * @return void
	 */
	public function testTheStoredMatrixNormalisesExactly(): void {
		$stored = [
			'{oops',
			'"text"',
			'[["a"]]',
			'{"a.b":["x","","x",3,"y",null,["z"]],"c.d":"admin","e.f":[],"7":["q"]}',
		];
		$read = [];
		foreach ($stored as $json) {
			$config = $this->createMock(originalClassName: IAppConfig::class);
			$config->method('getValueString')->willReturn($json);
			$read[] = (new ActionMatrix(appConfig: $config))->getMatrix();
		}

		$this->assertSame(expected: [[], [], [], ['a.b' => ['x', 'y'], 'e.f' => []]], actual: $read);

	}//end testTheStoredMatrixNormalisesExactly()

	/**
	 * `getAllowedGroups` returns ["admin"] for an unknown action.
	 *
	 * @return void
	 */
	public function testGetAllowedGroupsDefaultsToAdmin(): void {
		$this->setMatrix([]);

		$this->assertSame(['admin'], $this->matrix->getAllowedGroups('unknown.action'));

	}//end testGetAllowedGroupsDefaultsToAdmin()

	/**
	 * Each refusal names the action and why: admin-only (or no entry), or no matching group.
	 *
	 * @return void
	 */
	public function testEachRefusalNamesItsReason(): void {
		$user = $this->mockUser('jane');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroupIds')->willReturn(['staff']);
		$this->setMatrix(['a.admin' => ['admin'], 'a.none' => [], 'a.chairs' => ['admin', 'chairs']]);

		$messages = [];
		foreach (['a.admin', 'a.none', 'a.missing', 'a.chairs'] as $action) {
			try {
				$this->service->requireAction(user: $user, action: $action);
				$messages[] = 'allowed';
			} catch (OCSForbiddenException $e) {
				$messages[] = $e->getMessage();
			}
		}

		$this->assertSame(
			[
				"Action 'a.admin' requires admin rights",
				"Action 'a.none' requires admin rights",
				"Action 'a.missing' requires admin rights",
				"Action 'a.chairs' not allowed for your groups",
			],
			$messages
		);

	}//end testEachRefusalNamesItsReason()

	/**
	 * The matrix is written normalised (empty groups dropped, duplicates removed, reindexed)
	 * under the app's `actions` key, and its actions are its keys.
	 *
	 * @return void
	 */
	public function testTheMatrixIsWrittenNormalisedAndListsItsActions(): void {
		$this->appConfig->expects($this->once())->method('setValueString')
			->with('hermiq', 'actions', '{"a.b":["x","y"],"c.d":[]}');
		$this->setMatrix(['a.b' => ['x'], 'c.d' => []]);

		$this->matrix->setMatrix(['a.b' => ['x', '', 'x', 'y'], 'c.d' => ['']]);

		$this->assertSame(['a.b', 'c.d'], $this->matrix->getActions());

	}//end testTheMatrixIsWrittenNormalisedAndListsItsActions()
}//end class
