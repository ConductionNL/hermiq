<?php

/**
 * Unit tests for correcting and forgetting a memory entry (memory-correct-and-forget).
 *
 * Every saved payload is validated against the REAL Memory schema fragment in
 * lib/Settings/hermiq_register.json with Opis, so a write the schema refuses fails
 * here rather than live.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-memory/spec.md#requirement-an-owner-can-correct-a-remembered-fact-req-memedit-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\MemoryService;
use OCA\Hermiq\Service\RedactionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use OCP\IUserSession;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Correct and forget one memory entry, validated against the real schema.
 *
 * @spec openspec/specs/agent-memory/spec.md#requirement-an-owner-can-correct-a-remembered-fact-req-memedit-001
 */
class MemoryCorrectionTest extends TestCase {

	private const OLD_ID = '5b0c1f7e-2c4d-4a8e-9f10-1a2b3c4d5e6f';

	/**
	 * The Memory schema fragment from the register file, with OpenRegister's
	 * object-reference `$ref` removed (it names a schema slug, not a JSON pointer).
	 *
	 * @return object The schema.
	 */
	private function memorySchema(): object {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'));
		$schema = $register->components->schemas->Memory;
		unset($schema->properties->agentId->{'$ref'});

		return $schema;
	}//end memorySchema()

	/**
	 * Assert a payload validates against the real Memory schema.
	 *
	 * @param array<string, mixed> $payload The saved payload.
	 *
	 * @return void
	 */
	private function assertValidMemory(array $payload): void {
		$result = (new Validator())->validate(json_decode((string)json_encode($payload)), $this->memorySchema());
		$errors = [];
		if ($result->hasError() === true) {
			$errors = (new ErrorFormatter())->format($result->error());
		}

		$this->assertTrue($result->isValid(), (string)json_encode($errors, JSON_PRETTY_PRINT));
	}//end assertValidMemory()

	/**
	 * A MemoryService over one stored Memory object, capturing the saved payload.
	 *
	 * @param array<string, mixed> $stored The stored Memory payload.
	 * @param array<string, mixed>|null $captured Out-param: the last saved payload.
	 *
	 * @return MemoryService
	 */
	private function service(array $stored, ?array &$captured): MemoryService {
		$entity = new ObjectEntity();
		$entity->setUuid('7d1e2f3a-4b5c-4d6e-8f70-8192a3b4c5d6');
		$entity->setObject($stored);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturn([$entity]);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object) use (&$captured): ObjectEntity {
				$captured = $object;
				$saved = new ObjectEntity();
				$saved->setUuid('7d1e2f3a-4b5c-4d6e-8f70-8192a3b4c5d6');
				$saved->setObject($object);
				return $saved;
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('yes');

		return new MemoryService($objects, new RedactionService($config), $this->createMock(IUserSession::class));
	}//end service()

	/**
	 * The stored memory of "Permit helper".
	 *
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		return [
			'agentId' => '0f1e2d3c-4b5a-4968-8776-655443322110',
			'entries' => [
				['id' => self::OLD_ID, 'text' => 'The permit desk closes at 16:00', 'createdAt' => '2026-09-01T09:00:00+00:00'],
			],
			'charBudget' => 8000,
			'needsConsolidation' => false,
		];
	}//end stored()

	/**
	 * Correcting keeps the old entry soft-deleted and appends the new text, in one
	 * save that the real schema accepts.
	 *
	 * @return void
	 */
	public function testCorrectingSoftDeletesTheOldEntryAndAppendsTheNewText(): void {
		$captured = null;
		$result = $this->service($this->stored(), $captured)->correctMemoryEntry(
			agentId: '0f1e2d3c-4b5a-4968-8776-655443322110',
			entryId: self::OLD_ID,
			text: 'The permit desk closes at 17:00'
		);

		$this->assertNotNull($result);
		$this->assertCount(2, $captured['entries']);
		$this->assertSame(self::OLD_ID, $captured['entries'][0]['id']);
		$this->assertArrayHasKey('deletedAt', $captured['entries'][0]);
		$this->assertSame('The permit desk closes at 17:00', $captured['entries'][1]['text']);
		$this->assertArrayNotHasKey('deletedAt', $captured['entries'][1]);
		$this->assertNotSame(self::OLD_ID, $captured['entries'][1]['id']);
		$this->assertValidMemory($captured);
	}//end testCorrectingSoftDeletesTheOldEntryAndAppendsTheNewText()

	/**
	 * An unknown or already forgotten entry is not found and nothing is saved.
	 *
	 * @return void
	 */
	public function testCorrectingAnUnknownOrForgottenEntryIsNotFound(): void {
		$captured = null;
		$service = $this->service($this->stored(), $captured);
		$this->assertNull($service->correctMemoryEntry(agentId: 'a', entryId: 'nope', text: 'x'));

		$stored = $this->stored();
		$stored['entries'][0]['deletedAt'] = '2026-09-02T09:00:00+00:00';
		$this->assertNull($this->service($stored, $captured)->correctMemoryEntry(agentId: 'a', entryId: self::OLD_ID, text: 'x'));
		$this->assertNull($captured);
	}//end testCorrectingAnUnknownOrForgottenEntryIsNotFound()

	/**
	 * Entries stored before ids existed get an id on the next write, so the UI can
	 * address every entry it shows; the payload still validates.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-memory/spec.md#requirement-an-owner-can-make-an-agent-forget-a-fact-req-memedit-002
	 */
	public function testAWriteGivesOldEntriesAnId(): void {
		$stored = $this->stored();
		$stored['entries'][] = ['text' => 'Stored before ids existed', 'createdAt' => '2026-01-01T00:00:00+00:00'];

		$captured = null;
		$this->service($stored, $captured)->appendMemoryEntry(agentId: 'a', text: 'A new fact');

		$this->assertCount(3, $captured['entries']);
		foreach ($captured['entries'] as $entry) {
			$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $entry['id']);
		}

		$this->assertSame(self::OLD_ID, $captured['entries'][0]['id']);
		$this->assertValidMemory($captured);
	}//end testAWriteGivesOldEntriesAnId()

	/**
	 * Forgetting through the owner path soft-deletes and validates.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-memory/spec.md#requirement-an-owner-can-make-an-agent-forget-a-fact-req-memedit-002
	 */
	public function testForgettingSoftDeletesAndValidates(): void {
		$captured = null;
		$result = $this->service($this->stored(), $captured)->forgetEntry(agentId: 'a', subjectUid: null, entryId: self::OLD_ID);

		$this->assertTrue($result['found']);
		$this->assertArrayHasKey('deletedAt', $captured['entries'][0]);
		$this->assertValidMemory($captured);
	}//end testForgettingSoftDeletesAndValidates()

	/**
	 * Control: the schema check can fail, so the green above means something.
	 *
	 * @return void
	 */
	public function testTheSchemaRefusesADeletedAtThatIsNotADate(): void {
		$payload = $this->stored();
		$payload['entries'][0]['deletedAt'] = 'yesterday';

		$result = (new Validator())->validate(json_decode((string)json_encode($payload)), $this->memorySchema());
		$this->assertFalse($result->isValid());
	}//end testTheSchemaRefusesADeletedAtThatIsNotADate()
}//end class
