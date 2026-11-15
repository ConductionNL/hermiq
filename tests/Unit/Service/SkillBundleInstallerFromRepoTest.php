<?php

/**
 * Unit tests for SkillBundleInstaller::installFromRepo().
 *
 * Covers: a repository that carries no bundle is refused with a named
 * exception before anything is parsed, a bundle's skills and agents are both
 * installed and summarised (counts, truncation flag passed through as a
 * bool, per-item outcomes), the fetch is made with exactly the caller's
 * coordinates and credential, and a skill whose register cannot be resolved
 * (DoesNotExistException) is recorded as failed without aborting the rest.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\GitHubTemplateCatalogService;
use OCA\Hermiq\Service\NamedObjectRows;
use OCA\Hermiq\Service\SkillBundleInstaller;
use OCA\Hermiq\Service\SkillBundleSerializer;
use OCA\Hermiq\Service\SkillIdentityResolver;
use OCA\Hermiq\Service\SkillMarketplaceService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SkillBundleInstaller::installFromRepo().
 */
#[CoversClass(SkillBundleInstaller::class)]
#[UsesClass(NamedObjectRows::class)]
class SkillBundleInstallerFromRepoTest extends TestCase {

	/**
	 * Mock catalog (the GitHub fetch boundary).
	 *
	 * @var GitHubTemplateCatalogService&MockObject
	 */
	private GitHubTemplateCatalogService&MockObject $catalog;

	/**
	 * Mock bundle serializer.
	 *
	 * @var SkillBundleSerializer&MockObject
	 */
	private SkillBundleSerializer&MockObject $serializer;

	/**
	 * Mock marketplace (the skill write path).
	 *
	 * @var SkillMarketplaceService&MockObject
	 */
	private SkillMarketplaceService&MockObject $marketplace;

	/**
	 * Mock ObjectService (agent writes).
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Agent bodies persisted.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $savedAgents = [];

	/**
	 * Wire fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->catalog     = $this->createMock(GitHubTemplateCatalogService::class);
		$this->serializer  = $this->createMock(SkillBundleSerializer::class);
		$this->marketplace = $this->createMock(SkillMarketplaceService::class);
		$this->logger      = $this->createMock(LoggerInterface::class);

		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->savedAgents[] = $object;
				return new ObjectEntity();
			}
		);

		$this->serializer->method('packageOf')->willReturnCallback(
			static fn (array $skill): string => 'package:'.(string)($skill['name'] ?? '')
		);
	}//end setUp()

	/**
	 * Build the installer.
	 *
	 * @return SkillBundleInstaller
	 */
	private function installer(): SkillBundleInstaller {
		$identity = $this->createMock(SkillIdentityResolver::class);
		$identity->method('canonicalUrl')->willReturnCallback(
			static fn (string $owner, string $repo, string $bundleName): string => 'https://github.com/'.$owner.'/'.$repo.'#'.$bundleName
		);

		return new SkillBundleInstaller(
			catalogService: $this->catalog,
			bundleSerializer: $this->serializer,
			marketplaceService: $this->marketplace,
			identityResolver: $identity,
			objectService: $this->objectService,
			logger: $this->logger,
		);
	}//end installer()

	/**
	 * A repo without a bundle is refused by name and nothing is parsed or written.
	 *
	 * @return void
	 */
	public function testARepoWithoutABundleIsRefused(): void {
		$this->catalog->method('fetchBundle')->willReturn(null);
		$this->serializer->expects($this->never())->method('fromBundle');
		$this->marketplace->expects($this->never())->method('installFromSource');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Hermiq bundle install: "acme/tools" does not carry a skill bundle.');

		$this->installer()->installFromRepo('acme', 'tools');
	}//end testARepoWithoutABundleIsRefused()

	/**
	 * Skills and agents are installed from the fetched files and summarised.
	 *
	 * @return void
	 */
	public function testInstallsSkillsAndAgentsAndSummarisesTheResult(): void {
		$files = ['skills/a/SKILL.md' => 'a', 'agents/triage.json' => '{}'];
		$this->catalog->expects($this->once())->method('fetchBundle')
			->with('acme', 'tools', 'v2', 'alice', 'cred-9')
			->willReturn(['files' => $files, 'truncated' => true]);
		$this->serializer->expects($this->once())->method('fromBundle')->with($files)
			->willReturn([['bundleName' => 'alpha', 'name' => 'Alpha', 'files' => ['x.md' => 'x']], ['name' => 'beta']]);
		$this->serializer->expects($this->once())->method('agentsFromBundle')->with($files)
			->willReturn([['name' => 'triage', 'bundleName' => 'triage', 'prompt' => 'sort']]);

		$calls = [];
		$this->marketplace->method('installFromSource')->willReturnCallback(
			static function (string $package, string $source, string $createdBy, array $auxFiles, string $sourceUrl) use (&$calls): ObjectEntity {
				$calls[] = [$package, $source, $createdBy, $auxFiles, $sourceUrl];
				$entity = new ObjectEntity();
				$entity->setObject(['state' => 'published', 'scanReport' => ['severity' => 'low']]);
				return $entity;
			}
		);

		$result = $this->installer()->installFromRepo('acme', 'tools', 'v2', 'alice', 'cred-9');

		$this->assertSame(
			[
				['package:Alpha', 'hub', 'alice', ['x.md' => 'x'], 'https://github.com/acme/tools#alpha'],
				['package:beta', 'hub', 'alice', [], 'https://github.com/acme/tools#beta'],
			],
			$calls
		);

		$this->assertSame(2, $result['installed']);
		$this->assertSame(0, $result['updated']);
		$this->assertSame(0, $result['unchanged']);
		$this->assertSame(0, $result['skipped']);
		$this->assertSame(0, $result['failed']);
		$this->assertTrue($result['truncated']);
		$this->assertSame(['alpha', 'beta'], array_column($result['skills'], 'name'));
		$this->assertSame('installed', $result['skills'][0]['outcome']);
		$this->assertSame('published', $result['skills'][0]['state']);
		$this->assertSame('low', $result['skills'][0]['severity']);
		$this->assertSame([['name' => 'triage', 'outcome' => 'installed']], $result['agents']);
		$this->assertSame([['name' => 'triage', 'prompt' => 'sort']], $this->savedAgents);
	}//end testInstallsSkillsAndAgentsAndSummarisesTheResult()

	/**
	 * An unresolvable register fails that skill only; the next still installs.
	 *
	 * @return void
	 */
	public function testAnUnresolvableRegisterFailsOnlyThatSkill(): void {
		$this->catalog->method('fetchBundle')->willReturn(['files' => [], 'truncated' => false]);
		$this->serializer->method('fromBundle')->willReturn([['name' => 'broken'], ['name' => 'fine']]);
		$this->serializer->method('agentsFromBundle')->willReturn([]);
		$this->marketplace->method('installFromSource')->willReturnCallback(
			static function (string $package): ObjectEntity {
				if ($package === 'package:broken') {
					throw new DoesNotExistException('register hermiq not found');
				}

				return new ObjectEntity();
			}
		);
		$this->logger->expects($this->once())->method('error')->with(
			$this->equalTo('Hermiq bundle install: skill "broken" could not be persisted: register hermiq not found'),
			$this->callback(static fn (array $ctx): bool => $ctx['exception'] instanceof DoesNotExistException)
		);

		$result = $this->installer()->installFromRepo('acme', 'tools');

		$this->assertSame(1, $result['failed']);
		$this->assertSame(1, $result['installed']);
		$this->assertFalse($result['truncated']);
		$this->assertSame(['name' => 'broken', 'outcome' => 'failed'], $result['skills'][0]);
		$this->assertSame('fine', $result['skills'][1]['name']);
		$this->assertSame([], $result['agents']);
		$this->assertSame([], $this->savedAgents);
	}//end testAnUnresolvableRegisterFailsOnlyThatSkill()
}//end class
