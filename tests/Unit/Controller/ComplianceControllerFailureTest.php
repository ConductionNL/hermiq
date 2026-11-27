<?php

/**
 * ComplianceController when the compliance store or the organisation lookup fails, and who may read a factsheet.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ComplianceController;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\ComplianceService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Controller\ComplianceController
 */
class ComplianceControllerFailureTest extends TestCase {

	/**
	 * @var ComplianceService&MockObject
	 */
	private ComplianceService $compliance;

	/**
	 * @var ActionAuthService&MockObject
	 */
	private ActionAuthService $actionAuth;

	/**
	 * @var OrganisationMapper&MockObject
	 */
	private OrganisationMapper $organisations;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->compliance = $this->createMock(ComplianceService::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
		$this->organisations = $this->createMock(OrganisationMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function controller(): ComplianceController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ComplianceController($this->createMock(IRequest::class), $this->compliance, $session, $this->actionAuth, $this->organisations, $this->logger);
	}//end controller()

	/**
	 * @param string              $owner The agent's owner.
	 * @param array<string,mixed> $data  The agent's data.
	 */
	private static function agent(string $owner, array $data = []): ObjectEntity {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setOwner($owner);
		$agent->setObject($data);
		return $agent;
	}//end agent()

	public function testAFailingDashboardIsA500ThatIsLogged(): void {
		$this->organisations->method('getActiveOrganisationWithFallback')->willReturn('org-1');
		$this->compliance->expects($this->once())->method('dashboard')->with('org-1')->willThrowException(new RuntimeException('store down'));
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('store down'), $this->arrayHasKey('exception'));

		$response = $this->controller()->dashboard();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not load the compliance dashboard'], $response->getData());
	}//end testAFailingDashboardIsA500ThatIsLogged()

	public function testAnUnresolvableOrganisationFallsBackToNoneAndIsLogged(): void {
		$this->organisations->method('getActiveOrganisationWithFallback')->willThrowException(new RuntimeException('no tenant'));
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('no tenant'), $this->arrayHasKey('exception'));
		$this->compliance->expects($this->once())->method('dashboard')->with('')->willReturn(['agents' => 0]);

		$response = $this->controller()->dashboard();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['agents' => 0], $response->getData());
	}//end testAnUnresolvableOrganisationFallsBackToNoneAndIsLogged()

	public function testAFailingExportIsA500(): void {
		$this->organisations->method('getActiveOrganisationWithFallback')->willReturn('org-1');
		$this->compliance->method('auditorPack')->willThrowException(new RuntimeException('store down'));
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('store down'), $this->arrayHasKey('exception'));

		$response = $this->controller()->export();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not produce the compliance export'], $response->getData());
	}//end testAFailingExportIsA500()

	public function testTheActingUserMayReadTheFactsheetWithoutTheAction(): void {
		$this->compliance->method('findAgent')->willReturn(self::agent('alice', ['actingUser' => 'bob']));
		$this->actionAuth->expects($this->never())->method('can');
		$this->compliance->method('factsheet')->with('agent-1')->willReturn(['name' => 'Triage']);

		$response = $this->controller()->factsheet('agent-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['name' => 'Triage'], $response->getData());
	}//end testTheActingUserMayReadTheFactsheetWithoutTheAction()

	public function testSomeoneElseNeedsTheFactsheetAction(): void {
		$this->compliance->method('findAgent')->willReturn(self::agent('alice'));
		$this->actionAuth->expects($this->once())->method('can')->with($this->anything(), 'compliance.view-factsheet')->willReturn(false);
		$this->compliance->expects($this->never())->method('factsheet');

		$response = $this->controller()->factsheet('agent-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testSomeoneElseNeedsTheFactsheetAction()

	public function testAFactsheetThatIsGoneOrFailsIsAnsweredAsSuch(): void {
		$this->compliance->method('findAgent')->willReturn(self::agent('bob'));
		$this->compliance->method('factsheet')->willReturnOnConsecutiveCalls(null, $this->throwException(new RuntimeException('store down')));
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('store down'), $this->arrayHasKey('exception'));

		$gone = $this->controller()->factsheet('agent-1');
		$failed = $this->controller()->factsheet('agent-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $gone->getStatus());
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		$this->assertSame(['error' => 'Could not load the agent factsheet'], $failed->getData());
	}//end testAFactsheetThatIsGoneOrFailsIsAnsweredAsSuch()
}//end class
