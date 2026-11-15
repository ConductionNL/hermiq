<?php

/**
 * OrganisationControlController's edges: no session, no stored kill-switch, an empty organisation, and a failing service.
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

use OCA\Hermiq\Controller\OrganisationControlController;
use OCA\Hermiq\Service\OrganisationControlService;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Controller\OrganisationControlController
 */
class OrganisationControlControllerFailureTest extends TestCase {

	/**
	 * @var OrganisationControlService&MockObject
	 */
	private OrganisationControlService $controls;

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
		$this->controls = $this->createMock(OrganisationControlService::class);
		$this->organisations = $this->createMock(OrganisationMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * @param bool                $signedIn Whether a user is signed in.
	 * @param array<string,mixed> $params   The request's parameters.
	 */
	private function controller(bool $signedIn = true, array $params = []): OrganisationControlController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('root');
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('root')->willReturn(true);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));

		return new OrganisationControlController($request, $this->controls, $session, $groups, $this->organisations, $this->logger);
	}//end controller()

	public function testShowWithoutASessionIs401(): void {
		$this->controls->expects($this->never())->method('getForOrganisation');

		$response = $this->controller(signedIn: false)->show('org-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testShowWithoutASessionIs401()

	public function testAnOrganisationWithoutAStoredSwitchReadsAsDisengaged(): void {
		$this->controls->method('getForOrganisation')->with('org-1')->willReturn(null);

		$response = $this->controller()->show('org-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['organisation' => 'org-1', 'engaged' => false, 'reason' => null, 'engagedBy' => null, 'engagedAt' => null],
			$response->getData()
		);
	}//end testAnOrganisationWithoutAStoredSwitchReadsAsDisengaged()

	public function testAnEmptyOrganisationIsNotFoundEvenForAnAdmin(): void {
		$this->controls->expects($this->never())->method('getForOrganisation');

		$response = $this->controller()->show('');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnEmptyOrganisationIsNotFoundEvenForAnAdmin()

	public function testAFailingReadIsA500ThatIsLogged(): void {
		$this->controls->method('getForOrganisation')->willThrowException(new RuntimeException('store down'));
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('store down'), $this->arrayHasKey('exception'));

		$response = $this->controller()->show('org-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not load kill-switch'], $response->getData());
	}//end testAFailingReadIsA500ThatIsLogged()

	public function testAFailingToggleIsA500AndPassesTheEngagementAndReason(): void {
		$this->controls->expects($this->once())->method('toggle')
			->with('org-1', true, 'incident 42', 'root')
			->willThrowException(new RuntimeException('store down'));
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('store down'), $this->arrayHasKey('exception'));

		$response = $this->controller(params: ['engaged' => 'true', 'reason' => 'incident 42'])->toggle('org-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Toggle failed'], $response->getData());
	}//end testAFailingToggleIsA500AndPassesTheEngagementAndReason()
}//end class
