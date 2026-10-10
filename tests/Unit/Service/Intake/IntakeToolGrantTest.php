<?php

/**
 * Hermiq IntakeToolGrant unit tests.
 *
 * Covers how the intake surface recognises a tool (decision 177): the owning app's
 * `citizenIntake` mark plus a declared `scope: create` AND `action: create`, read
 * off the dotted `mcpId` rather than the last segment of the id.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/conversational-intake/spec.md#requirement-an-intake-tool-is-recognised-by-its-mark-and-its-declared-create-taxonomy
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Intake;

use OCA\Hermiq\Service\Intake\IntakeRefusedException;
use OCA\Hermiq\Service\Intake\IntakeToolGrant;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Unit tests for IntakeToolGrant.
 */
class IntakeToolGrantTest extends TestCase {

	/**
	 * The tool ids the facade double was asked to invoke.
	 *
	 * @var list<string>
	 */
	private array $invoked = [];

	/**
	 * The catalogue as ToolRegistryFacade::listTools() hands it over: an LLM-safe
	 * `name` and the dotted `mcpId` beside it.
	 *
	 * @return array<int, array<string, mixed>> The catalogue.
	 */
	private function catalog(): array {
		return [
			// A curated two-part tool, marked and declaring create twice over.
			[
				'name' => 'dossiq_fileCase',
				'mcpId' => 'dossiq.fileCase',
				'scope' => 'create',
				'action' => 'create',
				'annotations' => ['citizenIntake' => true],
			],
			// Its id ends in `.create`, which the old rule trusted; it declares no
			// create scope or action, so it is not an intake tool.
			[
				'name' => 'dossiq_melding_create',
				'mcpId' => 'dossiq.melding.create',
				'annotations' => ['citizenIntake' => true],
			],
			// Marked, create scope, but the app names another action.
			[
				'name' => 'dossiq_reopenCase',
				'mcpId' => 'dossiq.reopenCase',
				'scope' => 'create',
				'action' => 'reopen',
				'annotations' => ['citizenIntake' => true],
			],
			// Declares create but carries no mark.
			[
				'name' => 'dossiq_createNote',
				'mcpId' => 'dossiq.createNote',
				'scope' => 'create',
				'action' => 'create',
			],
			// Create taxonomy and the mark, but OpenRegister classifies it read-only.
			[
				'name' => 'dossiq_previewCase',
				'mcpId' => 'dossiq.previewCase',
				'scope' => 'create',
				'action' => 'create',
				'readOnlyHint' => true,
				'annotations' => ['citizenIntake' => true],
			],
		];
	}//end catalog()

	/**
	 * Build the grant over a facade double.
	 *
	 * @param bool $catalogueFails Whether listTools throws.
	 * @param array<string, mixed>|null $invokeAnswer What invokeTool answers.
	 *
	 * @return IntakeToolGrant The grant.
	 */
	private function grant(bool $catalogueFails = false, ?array $invokeAnswer = null): IntakeToolGrant {
		$this->invoked = [];
		$facade = $this->createMock(ToolRegistryFacade::class);
		if ($catalogueFails === true) {
			$facade->method('listTools')->willThrowException(new RuntimeException('catalogue down'));
		} else {
			$facade->method('listTools')->willReturn($this->catalog());
		}

		$facade->method('invokeTool')->willReturnCallback(
			function (string $toolId, array $arguments) use ($invokeAnswer): array {
				$this->invoked[] = $toolId;
				return ($invokeAnswer ?? ['result' => ['id' => 'ZAAK-1'], 'isError' => false]);
			}
		);

		return new IntakeToolGrant($facade, new NullLogger());
	}//end grant()

	public function testOnlyAMarkedToolDeclaringCreateTwiceIsAnIntakeTool(): void {
		$ids = array_map(
			static fn (array $descriptor): string => IntakeToolGrant::idOf(descriptor: $descriptor),
			$this->grant()->intakeTools()
		);

		$this->assertSame(['dossiq.fileCase'], $ids);
	}//end testOnlyAMarkedToolDeclaringCreateTwiceIsAnIntakeTool()

	public function testTheDottedIdAndTheSafeAliasAreBothPermitted(): void {
		$grant = $this->grant();

		$this->assertTrue($grant->permits(toolId: 'dossiq.fileCase'));
		$this->assertTrue($grant->permits(toolId: 'dossiq_fileCase'));
		$this->assertFalse($grant->permits(toolId: 'dossiq.melding.create'));
		$this->assertFalse($grant->permits(toolId: 'dossiq.reopenCase'));
		$this->assertFalse($grant->permits(toolId: ''));
	}//end testTheDottedIdAndTheSafeAliasAreBothPermitted()

	public function testAnUnqualifiedToolIsRefusedBeforeTheAppIsCalled(): void {
		$grant = $this->grant();

		try {
			$grant->call(toolId: 'dossiq.melding.create', arguments: []);
			$this->fail('an id ending in .create without the declared taxonomy must be refused');
		} catch (IntakeRefusedException $e) {
			$this->assertSame([], $this->invoked);
		}

		$answer = $grant->call(toolId: 'dossiq.fileCase', arguments: ['type' => 'melding']);
		$this->assertSame(['dossiq.fileCase'], $this->invoked);
		$this->assertFalse($answer['isError']);
	}//end testAnUnqualifiedToolIsRefusedBeforeTheAppIsCalled()

	public function testAnUnreadableCatalogueGrantsNothing(): void {
		$grant = $this->grant(catalogueFails: true);

		$this->assertSame([], $grant->intakeTools());
		$this->assertFalse($grant->permits(toolId: 'dossiq.fileCase'));
	}//end testAnUnreadableCatalogueGrantsNothing()

	public function testIdOfPrefersTheDottedId(): void {
		$this->assertSame('a.b', IntakeToolGrant::idOf(descriptor: ['name' => 'a_b', 'mcpId' => 'a.b', 'id' => 'x']));
		$this->assertSame('a_b', IntakeToolGrant::idOf(descriptor: ['name' => 'a_b']));
		$this->assertSame('x.y', IntakeToolGrant::idOf(descriptor: ['id' => 'x.y']));
		$this->assertSame('', IntakeToolGrant::idOf(descriptor: ['mcpId' => '']));
	}//end testIdOfPrefersTheDottedId()

	public function testARefusalFoldedIntoTheResultIsStillARefusal(): void {
		// What OpenRegister's bridge hands back when the owning app's tool throws:
		// the facade's own flag says success, the result says otherwise.
		$grant = $this->grant(
			invokeAnswer: [
				'result' => ['isError' => true, 'error' => 'internal_error', 'message' => 'dossiq has no published case type "x".'],
				'isError' => false,
			]
		);

		$answer = $grant->call(toolId: 'dossiq.fileCase', arguments: ['type' => 'x']);

		$this->assertTrue($answer['isError']);
	}//end testARefusalFoldedIntoTheResultIsStillARefusal()
}//end class
