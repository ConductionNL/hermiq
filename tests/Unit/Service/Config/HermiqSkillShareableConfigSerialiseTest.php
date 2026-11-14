<?php

/**
 * Tests for sharing skills as a config bundle: the type's identity and what it serialises.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Config
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Implements OpenRegister's IShareableConfigType; the canonical spec has its single home in openregister.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Config;

use OCA\Hermiq\Service\Config\HermiqSkillShareableConfigType;
use OCA\Hermiq\Service\SkillSerializer;
use OCA\Hermiq\Service\SkillService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The bundle a skill selection becomes.
 *
 * @covers \OCA\Hermiq\Service\Config\HermiqSkillShareableConfigType
 * @uses   \OCA\Hermiq\Service\SkillSerializer
 *
 * @spec exclude Implements OpenRegister's IShareableConfigType; the canonical spec has its single home in openregister.
 */
class HermiqSkillShareableConfigSerialiseTest extends TestCase {

	/**
	 * The skill service double.
	 *
	 * @var SkillService&MockObject
	 */
	private SkillService $skills;

	/**
	 * The config type under test.
	 *
	 * @var HermiqSkillShareableConfigType
	 */
	private HermiqSkillShareableConfigType $type;

	/**
	 * Wire the type to a skill service double and the real serializer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->skills = $this->createMock(SkillService::class);
		$serializer   = new SkillSerializer();
		$skills       = $this->skills;
		$container    = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => [SkillService::class => $skills, SkillSerializer::class => $serializer][$id]
		);
		$this->type = new HermiqSkillShareableConfigType(container: $container);
	}//end setUp()

	/**
	 * A stored skill.
	 *
	 * @param array<string, mixed> $data The skill object.
	 *
	 * @return ObjectEntity
	 */
	private static function skill(array $data): ObjectEntity {
		$skill = new ObjectEntity();
		$skill->setObject($data);
		return $skill;
	}//end skill()

	/**
	 * The type names itself for the config hub.
	 *
	 * @return void
	 */
	public function testTheTypeNamesItself(): void {
		$this->assertSame('hermiq.skill', $this->type->getId());
		$this->assertSame('Agent skills', $this->type->getDisplayName());
		$this->assertSame('hermiq-skill', $this->type->getTopic());
	}//end testTheTypeNamesItself()

	/**
	 * A selection serialises exactly the skills found, in order, each as its package.
	 *
	 * @return void
	 */
	public function testASelectionSerialisesTheSkillsFound(): void {
		$this->skills->expects($this->never())->method('listSkills');
		$this->skills->expects($this->exactly(3))->method('getSkill')->willReturnCallback(
			static fn (string $skillId): ?ObjectEntity => match ($skillId) {
				'7' => self::skill(['name' => 'Summarise', 'frontmatter' => 'name: Summarise', 'body' => 'Write three lines.']),
				'9' => self::skill(['frontmatter' => '', 'body' => 'Untitled']),
				default => null,
			}
		);

		$this->assertSame(
			[
				'type' => 'hermiq.skill',
				'version' => '1.0',
				'skills' => [
					['name' => 'Summarise', 'package' => "---\nname: Summarise\n---\nWrite three lines."],
					['name' => '', 'package' => "---\n\n---\nUntitled"],
				],
			],
			$this->type->serialise(selection: ['skillIds' => [7, 'gone', '9']])
		);
	}//end testASelectionSerialisesTheSkillsFound()

	/**
	 * Without a selection every skill is serialised, and anything that is no stored skill is skipped.
	 *
	 * @return void
	 */
	public function testWithoutASelectionEverySkillIsSerialised(): void {
		$this->skills->expects($this->never())->method('getSkill');
		$this->skills->expects($this->once())->method('listSkills')->willReturn(
			[self::skill(['name' => 'A', 'body' => 'a']), ['name' => 'not an entity'], self::skill(['name' => 'B', 'body' => 'b'])]
		);

		$bundle = $this->type->serialise(selection: []);

		$this->assertSame(['A', 'B'], array_column($bundle['skills'], 'name'));
		$this->assertSame("---\n\n---\nb", $bundle['skills'][1]['package']);
	}//end testWithoutASelectionEverySkillIsSerialised()
}//end class
