<?php

/**
 * Unit tests for DepartmentControlController: only an administrator switches a department.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\DepartmentControlController;
use OCA\Hermiq\Service\DepartmentEvidence;
use OCA\Hermiq\Service\DepartmentSwitch;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The department toggle's gate and answers, over the real DepartmentSwitch.
 */
class DepartmentControlControllerTest extends TestCase {

	/**
	 * The object service double behind the real switch.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * The audit trail double behind the real evidence.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper&MockObject $auditTrailMapper;

	protected function setUp(): void {
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$department = new ObjectEntity();
		$department->setUuid('d2');
		$department->setObject(['group' => 'sociaal-domein', 'aiOff' => false]);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('findAll')->willReturn([$department]);
	}

	/**
	 * The controller for a signed-in user who is, or is not, an administrator.
	 *
	 * @param bool                $admin  Whether the user is an administrator.
	 * @param array<string,mixed> $params Request parameters.
	 *
	 * @return DepartmentControlController
	 */
	private function controller(bool $admin, array $params = []): DepartmentControlController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('jan')->willReturn($admin);

		return new DepartmentControlController(
			request: $request,
			departments: $this->departmentSwitch(),
			userSession: $session,
			groupManager: $groups,
			logger: $this->createMock(LoggerInterface::class),
			evidence: $this->evidence()
		);
	}//end controller()

	/**
	 * The real switch over the doubles.
	 *
	 * @return DepartmentSwitch
	 */
	private function departmentSwitch(): DepartmentSwitch {
		return new DepartmentSwitch(objectService: $this->objectService, logger: $this->createMock(LoggerInterface::class), auditTrailMapper: $this->auditTrailMapper);
	}//end departmentSwitch()

	/**
	 * The real evidence over the doubles.
	 *
	 * @return DepartmentEvidence
	 */
	private function evidence(): DepartmentEvidence {
		return new DepartmentEvidence(
			departments: $this->departmentSwitch(),
			objectService: $this->objectService,
			auditTrailMapper: $this->auditTrailMapper,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end evidence()

	/**
	 * A user who is not an administrator is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testOnlyAnAdminSwitchesADepartment(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller(admin: false, params: ['off' => 'true'])->toggle(group: 'sociaal-domein');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testOnlyAnAdminSwitchesADepartment()

	/**
	 * Without a signed-in user the answer is 401.
	 *
	 * @return void
	 */
	public function testNoUserIsUnauthenticated(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$controller = new DepartmentControlController(
			request: $this->createMock(IRequest::class),
			departments: $this->departmentSwitch(),
			userSession: $session,
			groupManager: $this->createMock(IGroupManager::class),
			logger: $this->createMock(LoggerInterface::class),
			evidence: $this->evidence()
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->toggle(group: 'sociaal-domein')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->index()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->evidence(group: 'sociaal-domein')->getStatus());
	}//end testNoUserIsUnauthenticated()

	/**
	 * An administrator switches a department off, recorded under their name.
	 *
	 * @return void
	 */
	public function testAnAdminSwitchesADepartmentOff(): void {
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			static function (array|ObjectEntity $object): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setObject($object);
				return $entity;
			}
		);

		$response = $this->controller(admin: true, params: ['off' => 'true', 'reason' => ' audit '])->toggle(group: 'sociaal-domein');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['aiOff']);
		$this->assertSame('jan', $response->getData()['switchedBy']);
		$this->assertSame('audit', $response->getData()['reason']);
	}//end testAnAdminSwitchesADepartmentOff()

	/**
	 * A group that is not a department answers 404.
	 *
	 * @return void
	 */
	public function testAGroupThatIsNotADepartmentIsNotFound(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(admin: true, params: ['off' => true])->toggle(group: 'finance')->getStatus());
	}//end testAGroupThatIsNotADepartmentIsNotFound()

	/**
	 * A user who is not an administrator sees no department list and downloads no evidence.
	 *
	 * @return void
	 */
	public function testOnlyAnAdminSeesTheListAndTheEvidence(): void {
		$this->auditTrailMapper->expects($this->never())->method('findAll');

		$controller = $this->controller(admin: false);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->index()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->evidence(group: 'sociaal-domein')->getStatus());
	}//end testOnlyAnAdminSeesTheListAndTheEvidence()

	/**
	 * An administrator gets the list of departments.
	 *
	 * @return void
	 */
	public function testAnAdminGetsTheDepartmentList(): void {
		$this->auditTrailMapper->method('findAll')->willReturn([]);

		$response = $this->controller(admin: true)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['sociaal-domein'], array_column($response->getData()['results'], 'group'));
	}//end testAnAdminGetsTheDepartmentList()

	/**
	 * The evidence is a download named after the department, with its switch events.
	 *
	 * @return void
	 */
	public function testTheEvidenceIsADownloadNamedAfterTheDepartment(): void {
		$switch = new AuditTrail();
		$switch->setAction('department-switch');
		$switch->setChanged(['aiOff' => true, 'switchedBy' => 'jan', 'switchedAt' => '2026-11-14T14:00:00+00:00', 'reason' => '']);
		$this->auditTrailMapper->method('findAll')->willReturnCallback(
			static fn (?int $limit = null, ?int $offset = null, ?array $filters = []): array => (($filters['action'] ?? '') === 'department-switch') ? [$switch] : []
		);

		$response = $this->controller(admin: true)->evidence(group: 'sociaal-domein');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// getHeaders() materialises Nextcloud's default headers through \OC, which a
		// standalone unit run lacks, so the header the controller set is read directly.
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
		$this->assertSame('attachment; filename="hermiq-department-sociaal-domein-evidence.json"', $headers['Content-Disposition']);
		$this->assertSame('jan', $response->getData()['switches'][0]['by']);
	}//end testTheEvidenceIsADownloadNamedAfterTheDepartment()

	/**
	 * Evidence for a group that is not a department answers 404.
	 *
	 * @return void
	 */
	public function testEvidenceForAGroupThatIsNotADepartmentIsNotFound(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(admin: true)->evidence(group: 'finance')->getStatus());
	}//end testEvidenceForAGroupThatIsNotADepartmentIsNotFound()
}//end class
