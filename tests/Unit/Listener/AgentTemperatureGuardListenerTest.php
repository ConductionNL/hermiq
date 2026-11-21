<?php

/**
 * Tests for AgentTemperatureGuardListener.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Listener
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
 * @spec openspec/changes/spec-coverage-built-rows/specs/agent-management-ui/spec.md#REQ-SPCOV-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Kernel\Contracts\AgentObjectMatcherInterface;
use OCA\Hermiq\Listener\AgentFieldChanges;
use OCA\Hermiq\Listener\AgentTemperatureGuardListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * In range, out of range, unchanged, and the writes the guard leaves alone.
 */
#[CoversClass(AgentTemperatureGuardListener::class)]
#[CoversClass(AgentFieldChanges::class)]
final class AgentTemperatureGuardListenerTest extends TestCase {

	/**
	 * The guard, treating objects of the `agent` schema as agents.
	 *
	 * @return AgentTemperatureGuardListener
	 */
	private function guard(): AgentTemperatureGuardListener {
		$agents = $this->createMock(originalClassName: AgentObjectMatcherInterface::class);
		$agents->method('isAgent')->willReturnCallback(static fn (ObjectEntity $o): bool => (string)$o->getSchema() === 'agent');

		return new AgentTemperatureGuardListener(changes: new AgentFieldChanges(agents: $agents), logger: new NullLogger());
	}//end guard()

	/**
	 * An object of a schema with this data.
	 *
	 * @param array<string, mixed> $data   The data.
	 * @param string               $schema The schema.
	 *
	 * @return ObjectEntity
	 */
	private static function object(array $data, string $schema = 'agent'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema($schema);
		$object->setObject($data);

		return $object;
	}//end object()

	/**
	 * A new agent with a temperature from 0 to 2 is written.
	 *
	 * @param mixed $temperature The written temperature.
	 *
	 * @return void
	 */
	#[DataProvider('inRange')]
	public function testATemperatureFromZeroToTwoIsWritten(mixed $temperature): void {
		$event = new ObjectCreatingEvent(self::object(data: ['name' => 'Vergunningenhulp', 'temperature' => $temperature]));

		$this->guard()->handle($event);

		self::assertSame([], $event->getErrors());
		self::assertFalse($event->isPropagationStopped());
	}//end testATemperatureFromZeroToTwoIsWritten()

	/**
	 * Temperatures a provider accepts, and the blank one the form sends when the field is empty.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function inRange(): array {
		return [
			'zero' => [0],
			'low' => [0.2],
			'two' => [2],
			'numeric text' => ['1.5'],
			'blank' => [''],
			'null' => [null],
		];
	}//end inRange()

	/**
	 * A new agent with a temperature outside 0 to 2 is refused before it is saved.
	 *
	 * @param mixed $temperature The written temperature.
	 *
	 * @return void
	 */
	#[DataProvider('outOfRange')]
	public function testATemperatureOutsideZeroToTwoIsRefused(mixed $temperature): void {
		$event = new ObjectCreatingEvent(self::object(data: ['name' => 'Vergunningenhulp', 'temperature' => $temperature]));

		$this->guard()->handle($event);

		self::assertSame(['temperature' => AgentTemperatureGuardListener::OUT_OF_RANGE], $event->getErrors());
		self::assertTrue($event->isPropagationStopped());
	}//end testATemperatureOutsideZeroToTwoIsRefused()

	/**
	 * Values a provider refuses or that are not a number.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function outOfRange(): array {
		return [
			'three' => [3],
			'just above two' => [2.01],
			'negative' => [-0.1],
			'text' => ['warm'],
			'a list' => [[1]],
			'a boolean' => [true],
		];
	}//end outOfRange()

	/**
	 * A changed temperature on an update is checked, so the stored agent keeps its old one.
	 *
	 * @return void
	 */
	public function testAChangedTemperatureOnAnUpdateIsChecked(): void {
		$event = new ObjectUpdatingEvent(
			self::object(data: ['name' => 'Vergunningenhulp', 'temperature' => 3]),
			self::object(data: ['name' => 'Vergunningenhulp', 'temperature' => 0.2])
		);

		$this->guard()->handle($event);

		self::assertSame(['temperature' => AgentTemperatureGuardListener::OUT_OF_RANGE], $event->getErrors());
		self::assertTrue($event->isPropagationStopped());
	}//end testAChangedTemperatureOnAnUpdateIsChecked()

	/**
	 * An unchanged temperature is not checked again, so an older agent can still be renamed.
	 *
	 * @return void
	 */
	public function testAnUnchangedTemperatureIsNotCheckedAgain(): void {
		$event = new ObjectUpdatingEvent(
			self::object(data: ['name' => 'Nieuw', 'temperature' => 5]),
			self::object(data: ['name' => 'Oud', 'temperature' => 5])
		);

		$this->guard()->handle($event);

		self::assertSame([], $event->getErrors());
		self::assertFalse($event->isPropagationStopped());
	}//end testAnUnchangedTemperatureIsNotCheckedAgain()

	/**
	 * Writes without a temperature, of other schemas, after an earlier refusal, or of other events are left alone.
	 *
	 * @return void
	 */
	public function testOtherWritesAreLeftAlone(): void {
		$guard = $this->guard();

		$noTemperature = new ObjectCreatingEvent(self::object(data: ['name' => 'Gewoon']));
		$guard->handle($noTemperature);

		$otherSchema = new ObjectCreatingEvent(self::object(data: ['temperature' => 9], schema: 'skill'));
		$guard->handle($otherSchema);

		$stopped = new ObjectCreatingEvent(self::object(data: ['temperature' => 9]));
		$stopped->stopPropagation();
		$guard->handle($stopped);

		$created = new ObjectCreatedEvent(self::object(data: ['temperature' => 9]));
		$guard->handle($created);

		self::assertSame([], $noTemperature->getErrors());
		self::assertSame([], $otherSchema->getErrors());
		self::assertSame([], $stopped->getErrors());
	}//end testOtherWritesAreLeftAlone()
}//end class
