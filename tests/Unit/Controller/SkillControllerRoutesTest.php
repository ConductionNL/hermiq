<?php

/**
 * Unit tests for SkillController's CRUD and install routes.
 *
 * SkillControllerTest covers the GitHub, bundle and IDOR paths. This file pins
 * the plain routes' answers: 401 without a session, 400 on a missing input,
 * 404 when the service finds nothing, 500 (logged) when it throws, and the
 * shape of a successful answer, including which request keys reach an update.
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
 * @spec openspec/specs/skills-catalog/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SkillController;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\GitHubTemplateCatalogService;
use OCA\Hermiq\Service\GitHubTemplatePushService;
use OCA\Hermiq\Service\SeedCustodyService;
use OCA\Hermiq\Service\SkillBundleInstaller;
use OCA\Hermiq\Service\SkillBundleSerializer;
use OCA\Hermiq\Service\SkillMarketplaceService;
use OCA\Hermiq\Service\SkillService;
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
use RuntimeException;

/**
 * The plain CRUD and install routes of SkillController.
 *
 * @covers \OCA\Hermiq\Controller\SkillController
 * @uses   \OCA\Hermiq\Service\AgentAccessService
 * @uses   \OCA\Hermiq\Service\SeedCustodyService
 *
 * @spec openspec/specs/skills-catalog/spec.md
 */
final class SkillControllerRoutesTest extends TestCase {

	/**
	 * @var SkillService&MockObject
	 */
	private SkillService $skills;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the shared doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->skills = $this->createMock(SkillService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller for a session user (or none) and request parameters.
	 *
	 * @param string|null          $uid    The session user, null for none.
	 * @param array<string, mixed> $params The request parameters.
	 * @param string               $agentOwner The owner of agent-1.
	 *
	 * @return SkillController
	 */
	private function controller(?string $uid, array $params = [], string $agentOwner = 'alice'): SkillController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));
		$request->method('getParams')->willReturn($params);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setOwner($agentOwner);
		$agent->setObject(['isPrivate' => true, 'invitedUsers' => []]);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($agent);

		return new SkillController(
			$request,
			$this->skills,
			$session,
			$this->logger,
			$this->createMock(GitHubTemplateCatalogService::class),
			$this->createMock(SkillMarketplaceService::class),
			$this->createMock(SkillBundleSerializer::class),
			$this->createMock(GitHubTemplatePushService::class),
			$this->createMock(SkillBundleInstaller::class),
			new SeedCustodyService($this->createMock(IGroupManager::class)),
			new AgentAccessService($objects, $this->createMock(LoggerInterface::class), $this->createMock(IGroupManager::class))
		);
	}//end controller()

	/**
	 * A skill entity.
	 *
	 * @param string $uuid  The skill UUID.
	 * @param string $owner The skill owner.
	 *
	 * @return ObjectEntity
	 */
	private function skill(string $uuid = 'skill-1', string $owner = 'alice'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner($owner);
		$entity->setObject(['name' => 'Summarise', 'state' => 'active']);
		return $entity;
	}//end skill()

	/**
	 * Expect exactly one error log starting with the given text.
	 *
	 * @param string $message The full log message.
	 *
	 * @return void
	 */
	private function expectErrorLogged(string $message): void {
		$this->logger->expects($this->once())->method('error')->with($message, $this->arrayHasKey('exception'));
	}//end expectErrorLogged()

	/**
	 * Every route answers 401 without a session and asks the service nothing.
	 *
	 * @return void
	 */
	public function testEveryRouteRefusesAnAnonymousCaller(): void {
		$this->skills->expects($this->never())->method($this->anything());
		$controller = $this->controller(null, ['package' => 'x', 'agentId' => 'agent-1']);

		foreach ([
			$controller->index(),
			$controller->import(),
			$controller->export('skill-1'),
			$controller->update('skill-1'),
			$controller->install('skill-1'),
			$controller->uninstall('skill-1', 'agent-1'),
		] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertSame(['error' => 'Unauthenticated'], $response->getData());
		}
	}//end testEveryRouteRefusesAnAnonymousCaller()

	/**
	 * The index lists every skill with its uuid added.
	 *
	 * @return void
	 */
	public function testIndexListsSkillsWithTheirUuid(): void {
		$this->skills->method('listSkills')->willReturn([$this->skill('s-1'), $this->skill('s-2')]);

		$response = $this->controller('alice')->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['results' => [
				['id' => 's-1', 'name' => 'Summarise', 'state' => 'active', 'uuid' => 's-1'],
				['id' => 's-2', 'name' => 'Summarise', 'state' => 'active', 'uuid' => 's-2'],
			]],
			$response->getData()
		);
	}//end testIndexListsSkillsWithTheirUuid()

	/**
	 * A failing listing answers 500 and logs the cause.
	 *
	 * @return void
	 */
	public function testIndexAnswers500WhenTheListingFails(): void {
		$this->skills->method('listSkills')->willThrowException(new RuntimeException('db down'));
		$this->expectErrorLogged('Hermiq skills list failed: db down');

		$response = $this->controller('alice')->index();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not load skills'], $response->getData());
	}//end testIndexAnswers500WhenTheListingFails()

	/**
	 * Import needs a non-blank package.
	 *
	 * @return void
	 */
	public function testImportRefusesABlankPackage(): void {
		$this->skills->expects($this->never())->method('importSkill');

		$response = $this->controller('alice', ['package' => "  \n"])->import();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'A non-empty package is required'], $response->getData());
	}//end testImportRefusesABlankPackage()

	/**
	 * Import hands the package and the caller to the service and returns the skill.
	 *
	 * @return void
	 */
	public function testImportRecordsTheCallerAsCreator(): void {
		$this->skills->expects($this->once())->method('importSkill')
			->with('---\nname: x\n---', 'alice')
			->willReturn($this->skill('s-new'));

		$response = $this->controller('alice', ['package' => '---\nname: x\n---'])->import();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 's-new', 'name' => 'Summarise', 'state' => 'active', 'uuid' => 's-new'], $response->getData());
	}//end testImportRecordsTheCallerAsCreator()

	/**
	 * A failing import answers 500 and logs the cause.
	 *
	 * @return void
	 */
	public function testImportAnswers500WhenTheServiceFails(): void {
		$this->skills->method('importSkill')->willThrowException(new RuntimeException('bad yaml'));
		$this->expectErrorLogged('Hermiq skill import failed: bad yaml');

		$response = $this->controller('alice', ['package' => 'x'])->import();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Import failed'], $response->getData());
	}//end testImportAnswers500WhenTheServiceFails()

	/**
	 * Export answers the package, 404 when there is none, 500 when it throws.
	 *
	 * @return void
	 */
	public function testExportAnswersThePackageOrNotFoundOrFailure(): void {
		$this->skills->method('exportSkill')->willReturnCallback(
			static function (string $skillId): ?string {
				if ($skillId === 'boom') {
					throw new RuntimeException('disk');
				}

				return ($skillId === 'skill-1') ? 'PACKAGE' : null;
			}
		);
		$this->expectErrorLogged('Hermiq skill export failed: disk');
		$controller = $this->controller('alice');

		$this->assertSame(['package' => 'PACKAGE'], $controller->export('skill-1')->getData());

		$missing = $controller->export('skill-x');
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['error' => 'Not found'], $missing->getData());

		$failed = $controller->export('boom');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		$this->assertSame(['error' => 'Export failed'], $failed->getData());
	}//end testExportAnswersThePackageOrNotFoundOrFailure()

	/**
	 * Update drops the id and route keys and passes everything else on.
	 *
	 * @return void
	 */
	public function testUpdatePassesTheEditableKeysOnly(): void {
		$this->skills->method('getSkill')->willReturn($this->skill());
		$this->skills->expects($this->once())->method('updateSkill')
			->with('skill-1', ['description' => 'Better', 'tags' => ['a']])
			->willReturn($this->skill());

		$response = $this->controller('alice', ['id' => 'other', '_route' => 'hermiq.skill.update', 'description' => 'Better', 'tags' => ['a']])
			->update('skill-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('skill-1', $response->getData()['uuid']);
	}//end testUpdatePassesTheEditableKeysOnly()

	/**
	 * An update the service cannot apply is a 404; a throwing one a logged 500.
	 *
	 * @return void
	 */
	public function testUpdateAnswersNotFoundOrFailureFromTheService(): void {
		$this->skills->method('getSkill')->willReturn($this->skill());
		$this->skills->method('updateSkill')->willReturnOnConsecutiveCalls(null, $this->throwException(new RuntimeException('locked')));
		$this->expectErrorLogged('Hermiq skill update failed: locked');
		$controller = $this->controller('alice', ['description' => 'x']);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->update('skill-1')->getStatus());

		$failed = $controller->update('skill-1');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		$this->assertSame(['error' => 'Update failed'], $failed->getData());
	}//end testUpdateAnswersNotFoundOrFailureFromTheService()

	/**
	 * A skill whose lookup throws is not found, with a warning, and is not updated.
	 *
	 * @return void
	 */
	public function testUpdateOfASkillThatCannotBeLoadedIsNotFound(): void {
		$this->skills->method('getSkill')->willThrowException(new RuntimeException('no such object'));
		$this->skills->expects($this->never())->method('updateSkill');
		$this->logger->expects($this->once())->method('warning')
			->with('Hermiq skill lookup failed for skill-9: no such object', $this->arrayHasKey('exception'));

		$response = $this->controller('alice', ['description' => 'x'])->update('skill-9');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Not found'], $response->getData());
	}//end testUpdateOfASkillThatCannotBeLoadedIsNotFound()

	/**
	 * A lookup that answers no entity is not found.
	 *
	 * @return void
	 */
	public function testUpdateOfAnAbsentSkillIsNotFound(): void {
		$this->skills->method('getSkill')->willReturn(null);
		$this->skills->expects($this->never())->method('updateSkill');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('alice')->update('skill-9')->getStatus());
	}//end testUpdateOfAnAbsentSkillIsNotFound()

	/**
	 * Install needs an agent id, even one of whitespace.
	 *
	 * @return void
	 */
	public function testInstallRefusesAMissingAgentId(): void {
		$this->skills->expects($this->never())->method('installOnAgent');

		foreach ([[], ['agentId' => '   ']] as $params) {
			$response = $this->controller('alice', $params)->install('skill-1');
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
			$this->assertSame(['error' => 'An agentId is required'], $response->getData());
		}
	}//end testInstallRefusesAMissingAgentId()

	/**
	 * Install trims the agent id; null is a 404 and a throw a logged 500.
	 *
	 * @return void
	 */
	public function testInstallAnswersSkillOrNotFoundOrFailure(): void {
		$this->skills->expects($this->exactly(3))->method('installOnAgent')
			->with('skill-1', 'agent-1')
			->willReturnOnConsecutiveCalls($this->skill(), null, $this->throwException(new RuntimeException('quota')));
		$this->expectErrorLogged('Hermiq skill install failed: quota');
		$controller = $this->controller('alice', ['agentId' => ' agent-1 ']);

		$this->assertSame('skill-1', $controller->install('skill-1')->getData()['uuid']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->install('skill-1')->getStatus());

		$failed = $controller->install('skill-1');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		$this->assertSame(['error' => 'Install failed'], $failed->getData());
	}//end testInstallAnswersSkillOrNotFoundOrFailure()

	/**
	 * Uninstall needs a non-blank agent id.
	 *
	 * @return void
	 */
	public function testUninstallRefusesABlankAgentId(): void {
		$this->skills->expects($this->never())->method('uninstallFromAgent');

		$response = $this->controller('alice')->uninstall('skill-1', ' ');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'An agentId is required'], $response->getData());
	}//end testUninstallRefusesABlankAgentId()

	/**
	 * Uninstall answers the skill; null is a 404 and a throw a logged 500.
	 *
	 * @return void
	 */
	public function testUninstallAnswersSkillOrNotFoundOrFailure(): void {
		$this->skills->method('uninstallFromAgent')
			->willReturnOnConsecutiveCalls($this->skill(), null, $this->throwException(new RuntimeException('gone')));
		$this->expectErrorLogged('Hermiq skill uninstall failed: gone');
		$controller = $this->controller('alice');

		$this->assertSame(Http::STATUS_OK, $controller->uninstall('skill-1', 'agent-1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->uninstall('skill-1', 'agent-1')->getStatus());

		$failed = $controller->uninstall('skill-1', 'agent-1');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		$this->assertSame(['error' => 'Uninstall failed'], $failed->getData());
	}//end testUninstallAnswersSkillOrNotFoundOrFailure()
}//end class
