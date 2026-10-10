<?php

/**
 * Hermiq AssistantPromptLibrary unit tests.
 *
 * Covers what an administrator is promised: the text sent is the text on screen, the
 * order is theirs and is not re-sorted, a scope decides where a prompt appears,
 * disabling everything is one recorded act with no bulk counterpart, and an edit
 * outlives the app that shipped the prompt.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Assistant
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
 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Assistant;

use OCA\Hermiq\Service\Assistant\AssistantPromptLibrary;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * AssistantPromptLibrary unit tests.
 *
 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md
 */
class AssistantPromptLibraryTest extends TestCase {

	/**
	 * The prompts the ObjectService double holds, keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * The audit entries the mapper double was asked to write.
	 *
	 * @var array<int, array{action: string, context: array<string, mixed>}>
	 */
	private array $audits = [];

	/**
	 * An ObjectService double holding the prompts in memory.
	 *
	 * @param array<string, array<string, mixed>> $initial The stored prompts, by uuid.
	 *
	 * @return ObjectService The double.
	 */
	private function objectService(array $initial = []): ObjectService {
		$this->stored = $initial;

		$service = $this->createMock(ObjectService::class);
		$service->method('setRegister')->willReturnSelf();
		$service->method('setSchema')->willReturnSelf();
		$service->method('findAll')->willReturnCallback(
			function (): array {
				$out = [];
				foreach ($this->stored as $uuid => $data) {
					$entity = new ObjectEntity();
					$entity->setUuid((string)$uuid);
					$entity->setObject($data);
					$out[] = $entity;
				}

				return $out;
			}
		);
		$service->method('find')->willReturnCallback(
			function (mixed $id, mixed ...$rest): ?ObjectEntity {
				if (isset($this->stored[(string)$id]) === false) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject($this->stored[(string)$id]);
				return $entity;
			}
		);
		$service->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$key = ($uuid ?? ('prompt-' . (count($this->stored) + 1)));
				$this->stored[$key] = (array)$object;

				$entity = new ObjectEntity();
				$entity->setUuid($key);
				$entity->setObject($this->stored[$key]);
				return $entity;
			}
		);

		return $service;
	}//end objectService()

	/**
	 * An AuditTrailMapper double recording what it was asked to write.
	 *
	 * @return AuditTrailMapper The double.
	 */
	private function auditTrailMapper(): AuditTrailMapper {
		$this->audits = [];

		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context = []): AuditTrail {
				$this->audits[] = ['action' => $action, 'context' => $context];

				$entry = new AuditTrail();
				$entry->setAction($action);
				$entry->setChanged($context);
				return $entry;
			}
		);

		return $mapper;
	}//end auditTrailMapper()

	/**
	 * Build the library over the given stored prompts.
	 *
	 * @param array<string, array<string, mixed>> $initial The stored prompts.
	 *
	 * @return AssistantPromptLibrary The library.
	 */
	private function library(array $initial = []): AssistantPromptLibrary {
		return new AssistantPromptLibrary(
			$this->objectService($initial),
			$this->auditTrailMapper(),
			new NullLogger()
		);
	}//end library()

	/**
	 * The text that will be sent is the text the object carries: the library hands
	 * back what was written, with nothing appended, templated or improved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#scenario-the-text-that-will-be-sent-is-the-text-on-screen
	 */
	public function testTheTextSentIsTheTextTheObjectCarries(): void {
		$text = 'Vat deze zaak samen in maximaal vijf zinnen, in het Nederlands, zonder persoonsgegevens te herhalen.';
		$library = $this->library();

		$library->upsert(id: null, payload: ['label' => 'Samenvatten', 'prompt' => $text]);

		$offered = $library->forScope(usageScope: 'zaak');

		$this->assertCount(1, $offered);
		$this->assertSame($text, $offered[0]['prompt']);
	}//end testTheTextSentIsTheTextTheObjectCarries()

	/**
	 * A prompt scoped to one record type is not offered on another.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#scenario-scope-decides-where-a-prompt-appears
	 */
	public function testScopeDecidesWhereAPromptAppears(): void {
		$library = $this->library(
			[
				'p1' => ['label' => 'Zaak samenvatten', 'prompt' => 'a', 'usageScope' => 'zaak', 'order' => 0, 'enabled' => true],
				'p2' => ['label' => 'Besluit toetsen', 'prompt' => 'b', 'usageScope' => 'besluit', 'order' => 1, 'enabled' => true],
				'p3' => ['label' => 'Overal', 'prompt' => 'c', 'usageScope' => '', 'order' => 2, 'enabled' => true],
			]
		);

		$labels = array_map(
			static fn (array $p): string => (string)$p['label'],
			$library->forScope(usageScope: 'zaak')
		);

		$this->assertSame(['Zaak samenvatten', 'Overal'], $labels);
	}//end testScopeDecidesWhereAPromptAppears()

	/**
	 * The order is the administrator's, and the surface is not re-sorted: a library
	 * whose alphabetical order differs from its stored order comes back in the
	 * stored one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#scenario-order-is-the-administrators
	 */
	public function testOrderIsTheAdministrators(): void {
		$library = $this->library(
			[
				'p1' => ['label' => 'Zzz laatste', 'prompt' => 'a', 'order' => 0, 'enabled' => true],
				'p2' => ['label' => 'Aaa eerste', 'prompt' => 'b', 'order' => 1, 'enabled' => true],
			]
		);

		$labels = array_map(static fn (array $p): string => (string)$p['label'], $library->all());

		$this->assertSame(['Zzz laatste', 'Aaa eerste'], $labels);
	}//end testOrderIsTheAdministrators()

	/**
	 * A disabled prompt is not offered, though it is still in the library an
	 * administrator reads.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#scenario-everything-stops-in-one-act
	 */
	public function testADisabledPromptIsNotOffered(): void {
		$library = $this->library(
			['p1' => ['label' => 'Samenvatten', 'prompt' => 'a', 'order' => 0, 'enabled' => false]]
		);

		$this->assertCount(1, $library->all());
		$this->assertCount(0, $library->forScope(usageScope: 'zaak'));
	}//end testADisabledPromptIsNotOffered()

	/**
	 * Everything stops in one act, and the switch-off is on the record with the
	 * administrator and the time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#scenario-the-switch-off-is-on-the-record
	 */
	public function testEverythingStopsInOneRecordedAct(): void {
		$stored = [];
		for ($i = 1; $i <= 12; $i++) {
			$stored['p' . $i] = ['label' => 'Prompt ' . $i, 'prompt' => 'x', 'order' => $i, 'enabled' => true];
		}

		$library = $this->library($stored);

		$disabled = $library->disableAll(usageScope: null, actor: 'noor');

		$this->assertSame(12, $disabled);
		$this->assertCount(0, $library->forScope(usageScope: 'zaak'));

		$this->assertCount(1, $this->audits);
		$this->assertSame(AssistantPromptLibrary::DISABLE_ALL_ACTION, $this->audits[0]['action']);
		$this->assertSame('noor', $this->audits[0]['context']['actor']);
		$this->assertSame('*', $this->audits[0]['context']['usageScope']);
		$this->assertNotSame('', $this->audits[0]['context']['at']);
	}//end testEverythingStopsInOneRecordedAct()

	/**
	 * Disabling one scope leaves the others running, which is what makes it usable
	 * as an incident response rather than an instance-wide outage.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#requirement-disabling-every-prompt-must-be-one-recorded-act
	 */
	public function testDisablingOneScopeLeavesTheOthersRunning(): void {
		$library = $this->library(
			[
				'p1' => ['label' => 'Zaak', 'prompt' => 'a', 'usageScope' => 'zaak', 'order' => 0, 'enabled' => true],
				'p2' => ['label' => 'Besluit', 'prompt' => 'b', 'usageScope' => 'besluit', 'order' => 1, 'enabled' => true],
			]
		);

		$disabled = $library->disableAll(usageScope: 'zaak', actor: 'noor');

		$this->assertSame(1, $disabled);
		$this->assertCount(0, $library->forScope(usageScope: 'zaak'));
		$this->assertCount(1, $library->forScope(usageScope: 'besluit'));
	}//end testDisablingOneScopeLeavesTheOthersRunning()

	/**
	 * Coming back is deliberate: there is no bulk re-enable to call. Asserted over
	 * the public surface rather than over a behaviour, because the requirement is
	 * that the method does not exist.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#scenario-coming-back-is-deliberate
	 */
	public function testNoBulkReEnableExists(): void {
		$methods = array_map(
			static fn (\ReflectionMethod $m): string => $m->getName(),
			(new ReflectionClass(AssistantPromptLibrary::class))->getMethods()
		);

		foreach ($methods as $method) {
			$this->assertDoesNotMatchRegularExpression(
				'/^(enableAll|reEnableAll|restoreAll|enableEvery\w*)$/',
				$method,
				'A bulk re-enable would switch a prompt back on that was disabled weeks earlier for a different reason.'
			);
		}
	}//end testNoBulkReEnableExists()

	/**
	 * An administrator's edit survives the app that shipped the prompt: a re-seed
	 * leaves the edited text standing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#scenario-an-edit-survives-the-shipping-app
	 */
	public function testAnEditSurvivesTheShippingApp(): void {
		$library = $this->library();

		$library->seed('dossiq', [['label' => 'Samenvatten', 'prompt' => 'Ship text', 'usageScope' => 'zaak', 'order' => 0]]);

		$shipped = $library->all()[0];
		$library->upsert(id: (string)$shipped['id'], payload: ['prompt' => 'Wat de gemeente er zelf van maakte']);

		$result = $library->seed('dossiq', [['label' => 'Samenvatten', 'prompt' => 'Ship text', 'usageScope' => 'zaak', 'order' => 0]]);

		$this->assertSame(1, $result['skipped']);
		$this->assertSame('Wat de gemeente er zelf van maakte', $library->all()[0]['prompt']);
	}//end testAnEditSurvivesTheShippingApp()

	/**
	 * A disabled shipped prompt stays disabled when the shipping app is updated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#scenario-a-disabled-prompt-stays-disabled
	 */
	public function testADisabledPromptStaysDisabled(): void {
		$library = $this->library();

		$library->seed('dossiq', [['label' => 'Samenvatten', 'prompt' => 'Ship text', 'order' => 0]]);
		$library->disableAll(usageScope: null, actor: 'noor');

		$library->seed('dossiq', [['label' => 'Samenvatten', 'prompt' => 'Ship text', 'order' => 0]]);

		$this->assertFalse($library->all()[0]['enabled']);
		$this->assertCount(0, $library->forScope(usageScope: 'zaak'));
	}//end testADisabledPromptStaysDisabled()

	/**
	 * A shipped prompt nobody has touched is updated by a re-seed, which is what
	 * makes shipping an initial library worth doing at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#requirement-a-consuming-app-may-ship-an-initial-library-and-must-not-hold-the-edited-state
	 */
	public function testAnUntouchedShippedPromptIsUpdatedByAReSeed(): void {
		$library = $this->library();

		$library->seed('dossiq', [['label' => 'Samenvatten', 'prompt' => 'First text', 'order' => 0]]);
		$result = $library->seed('dossiq', [['label' => 'Samenvatten', 'prompt' => 'Second text', 'order' => 0]]);

		$this->assertSame(1, $result['installed']);
		$this->assertSame(0, $result['skipped']);
		$this->assertSame('Second text', $library->all()[0]['prompt']);
	}//end testAnUntouchedShippedPromptIsUpdatedByAReSeed()
}//end class
