<?php

/**
 * Hermiq SkillBundleInstallerTest
 *
 * Covers the AGENTS channel of a bundle install. The rule this exercises is not
 * a formatting one: an agent carries real operational authority (tools, approval
 * gates), so re-importing a bundle must never overwrite a live-tuned agent's
 * prompt or tool grants. "Existing agents are left alone" is a claim about what
 * does NOT happen, and the only way to hold it is to assert that no write was
 * attempted — which is what these tests do.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-bundle-may-additionally-carry-agent-definitions
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\GitHubTemplateCatalogService;
use OCA\Hermiq\Service\SkillBundleInstaller;
use OCA\Hermiq\Service\SkillBundleSerializer;
use OCA\Hermiq\Service\SkillIdentityResolver;
use OCA\Hermiq\Service\SkillMarketplaceService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for {@see SkillBundleInstaller}.
 *
 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-bundle-may-additionally-carry-agent-definitions
 */
class SkillBundleInstallerTest extends TestCase {
	/**
	 * Payloads handed to ObjectService::saveObject() during a test.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Reset the recorder between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved = [];
	}//end setUp()

	/**
	 * An ObjectEntity carrying the given object payload.
	 *
	 * @param array<string, mixed> $object The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $object): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($object);
		return $entity;
	}//end entity()

	/**
	 * An ObjectService whose findAll() returns the given existing agents and
	 * whose saveObject() records what it was asked to persist.
	 *
	 * @param array<int, mixed> $existing The objects findAll() should return.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $existing = []): ObjectService {
		$service = $this->createMock(ObjectService::class);
		$service->method('setRegister')->willReturnSelf();
		$service->method('setSchema')->willReturnSelf();
		$service->method('findAll')->willReturn($existing);
		$service->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object) {
				$this->saved[] = (is_array($object) === true ? $object : $object->getObject());
				return new ObjectEntity();
			}
		);

		return $service;
	}//end objectService()

	/**
	 * The installer, with every non-agent collaborator inert.
	 *
	 * @param ObjectService $objectService The object service under test.
	 *
	 * @return SkillBundleInstaller
	 */
	private function installer(ObjectService $objectService): SkillBundleInstaller {
		return new SkillBundleInstaller(
			$this->createMock(GitHubTemplateCatalogService::class),
			$this->createMock(SkillBundleSerializer::class),
			$this->createMock(SkillMarketplaceService::class),
			$this->createMock(SkillIdentityResolver::class),
			$objectService,
			new NullLogger()
		);
	}//end installer()

	/**
	 * A new agent is created, and the OpenRegister envelope fields are stripped
	 * so it takes a fresh identity on this instance rather than colliding with
	 * whatever already holds that uuid here.
	 *
	 * @return void
	 */
	public function testInstallAgentsCreatesANewAgent(): void {
		$result = $this->installer($this->objectService())->installAgents([
			['name' => 'triage', 'prompt' => 'sort the inbox', 'bundleName' => 'triage'],
		]);

		$this->assertSame([['name' => 'triage', 'outcome' => 'installed']], $result['outcomes']);
		$this->assertSame(1, $result['counts']['installed']);
		$this->assertSame(0, $result['counts']['unchanged']);
		$this->assertSame(0, $result['counts']['failed']);

		$this->assertCount(1, $this->saved);
		$this->assertSame('triage', $this->saved[0]['name']);
		$this->assertSame('sort the inbox', $this->saved[0]['prompt']);
		$this->assertArrayNotHasKey('bundleName', $this->saved[0]);
	}//end testInstallAgentsCreatesANewAgent()

	/**
	 * An agent that already exists by name is reported `unchanged` AND NEVER
	 * WRITTEN. The count alone would pass with a silent overwrite behind it, so
	 * the assertion that matters is the empty write log.
	 *
	 * @return void
	 */
	public function testInstallAgentsNeverOverwritesAnExistingAgent(): void {
		$service = $this->objectService([$this->entity(['name' => 'triage', 'prompt' => 'HAND TUNED'])]);

		$result = $this->installer($service)->installAgents([
			['name' => 'triage', 'prompt' => 'from the bundle'],
		]);

		$this->assertSame([['name' => 'triage', 'outcome' => 'unchanged']], $result['outcomes']);
		$this->assertSame(1, $result['counts']['unchanged']);
		$this->assertSame(0, $result['counts']['installed']);
		$this->assertSame([], $this->saved, 'an existing agent must not be written at all');
	}//end testInstallAgentsNeverOverwritesAnExistingAgent()

	/**
	 * The existence check matches on the object's `name` PROPERTY, not merely on
	 * the filter having returned rows. A backend that answers a filter loosely
	 * would otherwise make every install a no-op.
	 *
	 * @return void
	 */
	public function testInstallAgentsIgnoresNearMissesFromTheNameFilter(): void {
		$service = $this->objectService([$this->entity(['name' => 'triage-v2'])]);

		$result = $this->installer($service)->installAgents([['name' => 'triage']]);

		$this->assertSame(1, $result['counts']['installed']);
		$this->assertCount(1, $this->saved);
	}//end testInstallAgentsIgnoresNearMissesFromTheNameFilter()

	/**
	 * Rows that are not ObjectEntity instances are skipped rather than assumed
	 * to be a match.
	 *
	 * @return void
	 */
	public function testInstallAgentsSkipsNonEntityRowsWhenCheckingExistence(): void {
		$service = $this->objectService([['name' => 'triage'], null, 'triage']);

		$result = $this->installer($service)->installAgents([['name' => 'triage']]);

		$this->assertSame(1, $result['counts']['installed']);
	}//end testInstallAgentsSkipsNonEntityRowsWhenCheckingExistence()

	/**
	 * An agent with no usable name is reported failed rather than persisted
	 * under an empty name.
	 *
	 * @return void
	 */
	public function testInstallAgentsFailsAnAgentWithNoName(): void {
		$result = $this->installer($this->objectService())->installAgents([['prompt' => 'nameless']]);

		$this->assertSame([['name' => '', 'outcome' => 'failed']], $result['outcomes']);
		$this->assertSame(1, $result['counts']['failed']);
		$this->assertSame([], $this->saved);
	}//end testInstallAgentsFailsAnAgentWithNoName()

	/**
	 * `bundleName` is the fallback identity when the payload itself carries no
	 * `name` — a bundle whose agent file was named correctly still installs.
	 *
	 * @return void
	 */
	public function testInstallAgentsFallsBackToBundleName(): void {
		$result = $this->installer($this->objectService())->installAgents([
			['bundleName' => 'triage', 'prompt' => 'sort'],
		]);

		$this->assertSame(1, $result['counts']['installed']);
		$this->assertSame([['name' => 'triage', 'outcome' => 'installed']], $result['outcomes']);
	}//end testInstallAgentsFallsBackToBundleName()

	/**
	 * A persistence failure is contained to its own agent: it is reported failed
	 * and the rest of the set still installs. Installing 2 of 3 is a materially
	 * different result from installing none.
	 *
	 * @return void
	 */
	public function testInstallAgentsIsPerAgentBestEffort(): void {
		$service = $this->createMock(ObjectService::class);
		$service->method('setRegister')->willReturnSelf();
		$service->method('setSchema')->willReturnSelf();
		$service->method('findAll')->willReturn([]);
		$service->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object) {
				$payload = (is_array($object) === true ? $object : $object->getObject());
				if (($payload['name'] ?? '') === 'boom') {
					throw new RuntimeException('backend refused');
				}

				$this->saved[] = $payload;
				return new ObjectEntity();
			}
		);

		$result = $this->installer($service)->installAgents([
			['name' => 'alpha'],
			['name' => 'boom'],
			['name' => 'omega'],
		]);

		$this->assertSame(2, $result['counts']['installed']);
		$this->assertSame(1, $result['counts']['failed']);
		$this->assertSame(
			[
				['name' => 'alpha', 'outcome' => 'installed'],
				['name' => 'boom', 'outcome' => 'failed'],
				['name' => 'omega', 'outcome' => 'installed'],
			],
			$result['outcomes']
		);
	}//end testInstallAgentsIsPerAgentBestEffort()

	/**
	 * A lookup that itself throws fails only that agent, rather than aborting
	 * the whole install.
	 *
	 * @return void
	 */
	public function testInstallAgentsContainsAFailingExistenceLookup(): void {
		$service = $this->createMock(ObjectService::class);
		$service->method('setRegister')->willReturnSelf();
		$service->method('setSchema')->willReturnSelf();
		$service->method('findAll')->willThrowException(new RuntimeException('register unavailable'));

		$result = $this->installer($service)->installAgents([['name' => 'triage']]);

		$this->assertSame(1, $result['counts']['failed']);
		$this->assertSame([['name' => 'triage', 'outcome' => 'failed']], $result['outcomes']);
	}//end testInstallAgentsContainsAFailingExistenceLookup()

	/**
	 * A bundle declaring no agents is a normal, valid skills-only bundle: zero
	 * counts and no write, not a failure.
	 *
	 * @return void
	 */
	public function testInstallAgentsWithNoAgentsIsANoOp(): void {
		$result = $this->installer($this->objectService())->installAgents([]);

		$this->assertSame([], $result['outcomes']);
		$this->assertSame(['installed' => 0, 'unchanged' => 0, 'failed' => 0], $result['counts']);
		$this->assertSame([], $this->saved);
	}//end testInstallAgentsWithNoAgentsIsANoOp()

	/**
	 * installFromRepo() refuses a repository that carries no bundle, rather than
	 * reporting a clean "0 installed" for a fetch that did not happen.
	 *
	 * @return void
	 */
	public function testInstallFromRepoThrowsWhenTheRepoCarriesNoBundle(): void {
		$catalog = $this->createMock(GitHubTemplateCatalogService::class);
		$catalog->method('fetchBundle')->willReturn(null);

		$installer = new SkillBundleInstaller(
			$catalog,
			$this->createMock(SkillBundleSerializer::class),
			$this->createMock(SkillMarketplaceService::class),
			$this->createMock(SkillIdentityResolver::class),
			$this->objectService(),
			new NullLogger()
		);

		$this->expectException(RuntimeException::class);
		$installer->installFromRepo(owner: 'acme', repo: 'demo');
	}//end testInstallFromRepoThrowsWhenTheRepoCarriesNoBundle()
}//end class
