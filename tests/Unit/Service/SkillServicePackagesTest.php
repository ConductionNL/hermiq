<?php

/**
 * SkillService: importing, exporting, listing and stamping skills, and the
 * agent-side sync when it fails.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\SkillMaturityService;
use OCA\Hermiq\Service\SkillSerializer;
use OCA\Hermiq\Service\SkillService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A skill package is parsed into a stored skill, exported back without its
 * aux files, listed, and stamped with its GitHub publication; a missing skill
 * answers null everywhere; a failing agent-side sync is logged, not thrown.
 *
 * @covers \OCA\Hermiq\Service\SkillService
 * @uses   \OCA\Hermiq\Service\SkillSerializer
 */
class SkillServicePackagesTest extends TestCase {

	private ObjectService&MockObject $objects;

	/**
	 * Every saveObject call, as [object, register, schema, uuid].
	 *
	 * @var array<int, array{0: array, 1: mixed, 2: mixed, 3: ?string}>
	 */
	private array $saved = [];

	/**
	 * The service over a mocked object service.
	 *
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return SkillService
	 */
	private function service(?LoggerInterface $logger = null): SkillService {
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntity {
				$this->saved[] = [$object, $register, $schema, $uuid];
				return self::entity(payload: $object, uuid: ($uuid ?? 'new-uuid'));
			}
		);

		return new SkillService(
			objectService: $this->objects,
			skillSerializer: new SkillSerializer(logger: new NullLogger()),
			maturityService: $this->createMock(SkillMaturityService::class),
			logger: ($logger ?? new NullLogger())
		);
	}//end service()

	/**
	 * A stored object.
	 *
	 * @param array  $payload The object data.
	 * @param string $uuid    The uuid.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(array $payload, string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * An imported package becomes an active skill with its frontmatter fields and safe aux files.
	 *
	 * @return void
	 */
	public function testAnImportedPackageIsStoredAsAnActiveSkill(): void {
		$service = $this->service();

		$service->importSkill(
			package: "---\nname: Gap report\ndescription: Finds gaps\n---\nRead the register.",
			createdBy: 'alice',
			auxFiles: [
				['name' => 'scripts/run.sh', 'content' => 'echo hi'],
				['name' => 'SKILL.md', 'content' => 'ignored'],
				['name' => '', 'content' => 'nameless'],
				'not a file',
			]
		);

		$this->assertCount(1, $this->saved);
		[$object, $register, $schema, $uuid] = $this->saved[0];
		$this->assertSame(['hermiq', 'agentskill', null], [$register, $schema, $uuid]);
		$this->assertSame(
			[
				'name' => 'Gap report',
				'description' => 'Finds gaps',
				'frontmatter' => "name: Gap report\ndescription: Finds gaps",
				'body' => 'Read the register.',
				'files' => [['name' => 'scripts/run.sh', 'content' => 'echo hi']],
				'state' => 'active',
				'createdBy' => 'alice',
				'installedOn' => [],
			],
			$object
		);
	}//end testAnImportedPackageIsStoredAsAnActiveSkill()

	/**
	 * A package without a name is stored as an untitled skill.
	 *
	 * @return void
	 */
	public function testAPackageWithoutANameIsUntitled(): void {
		$this->service()->importSkill(package: 'Just a body', createdBy: 'bob');

		$this->assertSame('Untitled skill', $this->saved[0][0]['name']);
		$this->assertSame('Just a body', $this->saved[0][0]['body']);
	}//end testAPackageWithoutANameIsUntitled()

	/**
	 * Exporting rebuilds the package from the stored frontmatter and body.
	 *
	 * @return void
	 */
	public function testAnExportRebuildsThePackage(): void {
		$service = $this->service();
		$this->objects->method('find')->willReturnCallback(
			static fn (string $id): ?ObjectEntity => ($id === 'skill-1' ? self::entity(payload: ['frontmatter' => 'name: Gap report', 'body' => 'Body', 'files' => 'broken'], uuid: 'skill-1') : null)
		);

		$this->assertSame("---\nname: Gap report\n---\nBody", $service->exportSkill(skillId: 'skill-1'));
		$this->assertNull($service->exportSkill(skillId: 'missing'));
	}//end testAnExportRebuildsThePackage()

	/**
	 * The publish selection drops entries that are no file or have no name, and is empty for a non-list.
	 *
	 * @return void
	 */
	public function testThePublishSelectionKeepsOnlyNamedFiles(): void {
		$service = $this->service();
		$this->objects->method('find')->willReturnCallback(
			static fn (string $id): ?ObjectEntity => match ($id) {
				'listed' => self::entity(payload: ['files' => [['name' => 'a.md'], ['content' => 'nameless'], 'junk']], uuid: 'listed'),
				'broken' => self::entity(payload: ['files' => 'not a list'], uuid: 'broken'),
				default => null,
			}
		);

		$this->assertSame([['name' => 'a.md', 'content' => '']], $service->publishFileSelection(skillId: 'listed'));
		$this->assertSame([], $service->publishFileSelection(skillId: 'broken'));
		$this->assertNull($service->publishFileSelection(skillId: 'missing'));
	}//end testThePublishSelectionKeepsOnlyNamedFiles()

	/**
	 * A GitHub publication is stamped onto the stored skill, under its own uuid.
	 *
	 * @return void
	 */
	public function testAGithubPublicationIsStamped(): void {
		$service = $this->service();
		$this->objects->method('find')->willReturnCallback(
			static fn (string $id): ?ObjectEntity => ($id === 'skill-1' ? self::entity(payload: ['name' => 'Gap report'], uuid: 'skill-1') : null)
		);

		$stamped = $service->stampGithubPublish(skillId: 'skill-1', owner: 'conduction', repo: 'skills', publishedAt: '2026-11-13T00:00:00+00:00');

		$this->assertSame('skill-1', $stamped?->getUuid());
		$this->assertSame(
			[['id' => 'skill-1', 'name' => 'Gap report', 'githubOwner' => 'conduction', 'githubRepo' => 'skills', 'publishedAt' => '2026-11-13T00:00:00+00:00'], 'hermiq', 'agentskill', 'skill-1'],
			$this->saved[0]
		);
		$this->assertNull($service->stampGithubPublish(skillId: 'missing', owner: 'o', repo: 'r', publishedAt: 'now'));
		$this->assertNull($service->updateSkill(skillId: 'missing', data: ['name' => 'x']));
		$this->assertCount(1, $this->saved);
	}//end testAGithubPublicationIsStamped()

	/**
	 * Listing reads up to 200 skills of the skill schema and keeps only entities.
	 *
	 * @return void
	 */
	public function testListingReadsTheSkillSchema(): void {
		$service = $this->service();
		$this->objects->expects($this->once())->method('setRegister')->with('hermiq')->willReturnSelf();
		$this->objects->expects($this->once())->method('setSchema')->with('agentskill')->willReturnSelf();
		$skill = self::entity(payload: ['name' => 'Gap report'], uuid: 'skill-1');
		$this->objects->expects($this->once())->method('findAll')->with(['limit' => 200])->willReturn([$skill, ['name' => 'raw row']]);

		$this->assertSame([$skill], $service->listSkills());
	}//end testListingReadsTheSkillSchema()

	/**
	 * When the agent side cannot be written, the skill side still stands and the failure is logged.
	 *
	 * @return void
	 */
	public function testAFailingAgentSyncIsLoggedNotThrown(): void {
		$logger  = $this->createMock(LoggerInterface::class);
		$service = $this->service(logger: $logger);
		$this->objects->method('find')->willReturnCallback(
			static function (string $id, ...$rest): ?ObjectEntity {
				if ($id === 'agent-1') {
					throw new RuntimeException('agent store down');
				}

				return self::entity(payload: ['installedOn' => 'not a list'], uuid: 'skill-1');
			}
		);
		$messages = [];
		$logger->expects($this->exactly(2))->method('warning')->willReturnCallback(
			static function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);

		$installed   = $service->installOnAgent(skillId: 'skill-1', agentId: 'agent-1');
		$uninstalled = $service->uninstallFromAgent(skillId: 'skill-1', agentId: 'agent-1');

		$this->assertSame(['agent-1'], $installed?->getObject()['installedOn']);
		$this->assertSame([], $uninstalled?->getObject()['installedOn']);
		$this->assertSame(
			[
				'Hermiq could not sync skillInstalls onto agent agent-1 for skill skill-1: agent store down',
				'Hermiq could not desync skillInstalls on agent agent-1 for skill skill-1: agent store down',
			],
			$messages
		);
	}//end testAFailingAgentSyncIsLoggedNotThrown()
}//end class
