<?php

/**
 * Tests for sharing and installing agent templates through the federated-config engine.
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
 * @spec openspec/changes/store-through-federated-config/specs/agent-template-github-store/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Config;

use OCA\Hermiq\Service\AgentTemplateService;
use OCA\Hermiq\Service\Config\HermiqAgentTemplateShareableConfigType;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * A shared template installs the way a hub template does: quarantined, as the importer.
 *
 * @covers \OCA\Hermiq\Service\Config\HermiqAgentTemplateShareableConfigType
 *
 * @spec openspec/changes/store-through-federated-config/specs/agent-template-github-store/spec.md
 */
final class HermiqAgentTemplateShareableConfigTypeTest extends TestCase {

	/**
	 * The importPackage() calls, as [package, source, createdBy].
	 *
	 * @var list<array{0: string, 1: string, 2: string}>
	 */
	private array $imports = [];

	/**
	 * The templates the service lists, uuid => package.
	 *
	 * @var array<string, string|null>
	 */
	private array $stored = [
		'uuid-a' => '{"name":"Notulist","systemPrompt":"x"}',
		'uuid-b' => '{"name":"Planner"}',
	];

	/**
	 * The id, name and topic keep the identity the schema marker used to give the type.
	 *
	 * @return void
	 */
	public function testTheTypeKeepsTheMarkersIdentity(): void {
		$type = $this->type(who: 'alice');

		$this->assertSame(
			expected: ['hermiq.agent-template', 'Agent template', 'hermiq-agent-template'],
			actual: [$type->getId(), $type->getDisplayName(), $type->getTopic()]
		);
	}//end testTheTypeKeepsTheMarkersIdentity()

	/**
	 * Every package installs through the hub path, so it lands quarantined and scanned, as the importer.
	 *
	 * @return void
	 */
	public function testEachPackageIsImportedThroughTheHubPathAsTheImporter(): void {
		$bundle = ['templates' => ['junk', ['package' => ''], ['package' => 'pkg-a'], ['name' => 'no package'], ['package' => 'pkg-b']]];

		$result = $this->type(who: 'alice')->deserialise(bundle: $bundle);

		$this->assertSame(expected: ['installed' => ['uuid-pkg-a', 'uuid-pkg-b']], actual: $result);
		$this->assertSame(expected: [['pkg-a', 'hub', 'alice'], ['pkg-b', 'hub', 'alice']], actual: $this->imports);
	}//end testEachPackageIsImportedThroughTheHubPathAsTheImporter()

	/**
	 * Without a readable user the import is made by nobody rather than failing.
	 *
	 * @return void
	 */
	public function testWithoutAReadableUserTheImporterIsEmpty(): void {
		foreach ([null, 'throws'] as $who) {
			$this->imports = [];
			$this->type(who: $who)->deserialise(bundle: ['templates' => [['package' => 'pkg-a']]]);
			$this->assertSame(expected: [['pkg-a', 'hub', '']], actual: $this->imports);
		}
	}//end testWithoutAReadableUserTheImporterIsEmpty()

	/**
	 * A bundle with no templates installs nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyBundleInstallsNothing(): void {
		$this->assertSame(expected: ['installed' => []], actual: $this->type(who: 'alice')->deserialise(bundle: []));
		$this->assertSame(expected: [], actual: $this->imports);
	}//end testAnEmptyBundleInstallsNothing()

	/**
	 * An empty selection shares every listed template, each as its exported package.
	 *
	 * @return void
	 */
	public function testAnEmptySelectionSharesEveryTemplate(): void {
		$bundle = $this->type(who: 'alice')->serialise(selection: []);

		$this->assertSame(
			expected: [
				'type' => 'hermiq.agent-template',
				'version' => '1.0',
				'templates' => [
					['name' => 'Notulist', 'package' => '{"name":"Notulist","systemPrompt":"x"}'],
					['name' => 'Planner', 'package' => '{"name":"Planner"}'],
				],
			],
			actual: $bundle
		);
	}//end testAnEmptySelectionSharesEveryTemplate()

	/**
	 * A selection shares only the named templates; one that no longer exists is skipped.
	 *
	 * @return void
	 */
	public function testASelectionSharesOnlyTheNamedTemplatesAndSkipsAStaleOne(): void {
		$bundle = $this->type(who: 'alice')->serialise(selection: ['templateIds' => ['uuid-b', 'uuid-gone']]);

		$this->assertSame(expected: [['name' => 'Planner', 'package' => '{"name":"Planner"}']], actual: $bundle['templates']);
	}//end testASelectionSharesOnlyTheNamedTemplatesAndSkipsAStaleOne()

	/**
	 * A shared bundle installs back through the same type.
	 *
	 * @return void
	 */
	public function testASharedBundleInstallsBackPackageForPackage(): void {
		$type = $this->type(who: 'bob');

		$type->deserialise(bundle: $type->serialise(selection: []));

		$this->assertSame(
			expected: [['{"name":"Notulist","systemPrompt":"x"}', 'hub', 'bob'], ['{"name":"Planner"}', 'hub', 'bob']],
			actual: $this->imports
		);
	}//end testASharedBundleInstallsBackPackageForPackage()

	/**
	 * The type over a recording template service and the given session.
	 *
	 * @param string|null $who The signed-in uid, null for none, or `throws`.
	 *
	 * @return HermiqAgentTemplateShareableConfigType
	 */
	private function type(?string $who): HermiqAgentTemplateShareableConfigType {
		$templates = $this->createMock(AgentTemplateService::class);
		$templates->method('importPackage')->willReturnCallback(
			function (string $package, string $source, string $createdBy): ObjectEntity {
				$this->imports[] = [$package, $source, $createdBy];
				$saved = new ObjectEntity();
				$saved->setUuid('uuid-' . $package);
				return $saved;
			}
		);
		$templates->method('exportTemplate')->willReturnCallback(fn (string $templateId): ?string => ($this->stored[$templateId] ?? null));
		$templates->method('list')->willReturnCallback(
			function (): array {
				$out = [];
				foreach (array_keys($this->stored) as $uuid) {
					$entity = new ObjectEntity();
					$entity->setUuid($uuid);
					$out[] = $entity;
				}

				return $out;
			}
		);

		$session = $this->createMock(IUserSession::class);
		if ($who === 'throws') {
			$session->method('getUser')->willThrowException(new RuntimeException('no session'));
		} else {
			$user = null;
			if ($who !== null) {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($who);
			}

			$session->method('getUser')->willReturn($user);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([[AgentTemplateService::class, $templates], [IUserSession::class, $session]]);

		return new HermiqAgentTemplateShareableConfigType(container: $container);
	}//end type()
}//end class
