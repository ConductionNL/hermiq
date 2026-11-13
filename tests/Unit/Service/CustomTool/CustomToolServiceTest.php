<?php

/**
 * CustomToolService unit tests.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-a-custom-tool-is-offered-under-a-two-segment-id-and-only-by-exact-grant-req-custool-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\CustomTool;

use InvalidArgumentException;
use OCA\Hermiq\Service\CustomTool\CustomToolDefinition;
use OCA\Hermiq\Service\CustomTool\CustomToolService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Storing custom tools and offering the enabled ones of the caller's organisation.
 */
final class CustomToolServiceTest extends TestCase {

	/** @var list<ObjectEntity> */
	private array $stored = [];

	/** @var list<array{rbac: bool, multitenancy: bool}> */
	private array $reads = [];

	/** @var list<array<string, mixed>> */
	private array $saves = [];

	/**
	 * A stored tool.
	 *
	 * @param string               $uuid         Its uuid.
	 * @param string               $organisation Its organisation.
	 * @param array<string, mixed> $change       Changes to the permit check.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $uuid, string $organisation, array $change=[]): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOrganisation($organisation);
		$entity->setObject(array_replace(CustomToolDefinition::normalise(payload: CustomToolDefinitionTest::vergunningcheck()), $change));

		return $entity;
	}//end entity()

	/**
	 * The service over a faked object service.
	 *
	 * @return CustomToolService
	 */
	private function service(): CustomToolService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(
			function (array $config=[], bool $_rbac=true, bool $_multitenancy=true): array {
				$this->reads[] = ['rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return $this->stored;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend=[], mixed $register=null, mixed $schema=null, ?string $uuid=null, bool $_rbac=true, bool $_multitenancy=true): ObjectEntity {
				$this->saves[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $_rbac];
				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? 'new-uuid');
				$entity->setOrganisation((string)($object['@self']['organisation'] ?? ''));
				$entity->setObject($object);
				return $entity;
			}
		);

		return new CustomToolService(objectService: $objects, logger: new NullLogger());
	}//end service()

	/**
	 * A new tool is written into the named organisation and answered with its tool id.
	 *
	 * @return void
	 */
	public function testANewToolIsWrittenIntoTheOrganisation(): void {
		$tool = $this->service()->save(organisation: 'org-voorbeeld', payload: CustomToolDefinitionTest::vergunningcheck());

		$this->assertSame('hermiq.custom_vergunningcheck', $tool['toolId']);
		$this->assertSame('org-voorbeeld', $tool['organisation']);
		$this->assertSame('customtool', $this->saves[0]['schema']);
		$this->assertSame(['organisation' => 'org-voorbeeld'], $this->saves[0]['object']['@self']);
		$this->assertFalse($this->saves[0]['rbac']);
	}//end testANewToolIsWrittenIntoTheOrganisation()

	/**
	 * A slug is unique on the instance, across organisations; the tool itself may keep it.
	 *
	 * @return void
	 */
	public function testASlugIsUniqueOnTheInstance(): void {
		$this->stored = [self::entity(uuid: 'tool-1', organisation: 'org-other')];

		try {
			$this->service()->save(organisation: 'org-voorbeeld', payload: CustomToolDefinitionTest::vergunningcheck());
			$this->fail('a taken slug was accepted');
		} catch (InvalidArgumentException $e) {
			$this->assertStringStartsWith('slug:', $e->getMessage());
		}

		$this->assertSame([], $this->saves);
		$this->assertFalse($this->reads[0]['multitenancy'], 'uniqueness is checked across organisations');

		$this->service()->save(organisation: 'org-other', payload: CustomToolDefinitionTest::vergunningcheck(), uuid: 'tool-1');
		$this->assertSame('tool-1', $this->saves[0]['uuid']);
	}//end testASlugIsUniqueOnTheInstance()

	/**
	 * The catalogue reads as the acting user and offers only enabled tools.
	 *
	 * @return void
	 */
	public function testTheCatalogueOffersTheEnabledToolsTheUserCanRead(): void {
		$this->stored = [
			self::entity(uuid: 'tool-1', organisation: 'org-voorbeeld'),
			self::entity(uuid: 'tool-2', organisation: 'org-voorbeeld', change: ['slug' => 'oud', 'enabled' => false]),
		];

		$descriptors = $this->service()->descriptors();

		$this->assertSame(['hermiq.custom_vergunningcheck'], array_column($descriptors, 'id'));
		$this->assertSame([['rbac' => true, 'multitenancy' => true]], $this->reads);
	}//end testTheCatalogueOffersTheEnabledToolsTheUserCanRead()

	/**
	 * The list of one organisation leaves out the others.
	 *
	 * @return void
	 */
	public function testTheListOfOneOrganisation(): void {
		$this->stored = [
			self::entity(uuid: 'tool-1', organisation: 'org-voorbeeld'),
			self::entity(uuid: 'tool-2', organisation: 'org-other', change: ['slug' => 'anders']),
		];

		$this->assertSame(['tool-1'], array_column($this->service()->listForOrganisation(organisation: 'org-voorbeeld'), 'id'));
	}//end testTheListOfOneOrganisation()
}//end class
