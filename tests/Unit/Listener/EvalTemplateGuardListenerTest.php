<?php

/**
 * Unit tests for EvalTemplateGuardListener (reuse-scheduled-bias-evals).
 *
 * @category Test
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Listener\EvalTemplateGuardListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Hermiq\Listener\EvalTemplateGuardListener
 */
class EvalTemplateGuardListenerTest extends TestCase {

	/**
	 * The listener, with schema 7 being hermiq's evaldataset and schema 8 another schema.
	 *
	 * @param string $register The slug register 3 resolves to.
	 *
	 * @return EvalTemplateGuardListener
	 */
	private function listener(string $register = 'hermiq'): EvalTemplateGuardListener {
		$dataset = $this->createMock(Schema::class);
		$dataset->method('getSlug')->willReturn('evaldataset');
		$other = $this->createMock(Schema::class);
		$other->method('getSlug')->willReturn('agent');
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(fn (int $id): Schema => ($id === 7 ? $dataset : $other));

		$registerEntity = $this->createMock(Register::class);
		$registerEntity->method('getSlug')->willReturn($register);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($registerEntity);

		return new EvalTemplateGuardListener($schemas, $registers, $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * A dataset object.
	 *
	 * @param array<string, mixed> $data   Its fields.
	 * @param string               $schema The schema id or slug.
	 *
	 * @return ObjectEntity
	 */
	private static function dataset(array $data, string $schema = '7'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('tpl-1');
		$object->setSchema($schema);
		$object->setRegister('3');
		$object->setObject(array_merge(['name' => 'Bias check: public services (template)'], $data));
		return $object;
	}//end dataset()

	/**
	 * An edit of a stored template is refused, with the reason.
	 *
	 * @return void
	 */
	public function testAnEditOfATemplateIsRefused(): void {
		$event = new ObjectUpdatingEvent(self::dataset(['template' => true, 'name' => 'Renamed']), self::dataset(['template' => true]));

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['template' => EvalTemplateGuardListener::REFUSED], $event->getErrors());
	}//end testAnEditOfATemplateIsRefused()

	/**
	 * Clearing the template flag is an edit of a template too, and is refused.
	 *
	 * @return void
	 */
	public function testUnmarkingATemplateIsRefused(): void {
		$event = new ObjectUpdatingEvent(self::dataset(['template' => false]), self::dataset(['template' => true]));

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testUnmarkingATemplateIsRefused()

	/**
	 * A copy of a template, or any other dataset, stays editable.
	 *
	 * @return void
	 */
	public function testAnOrdinaryDatasetStaysEditable(): void {
		$copy  = ['template' => false, 'templateKey' => 'bias-public-services'];
		$event = new ObjectUpdatingEvent(self::dataset($copy + ['name' => 'Mine']), self::dataset($copy));

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getErrors());
	}//end testAnOrdinaryDatasetStaysEditable()

	/**
	 * Another schema's object with a template field is not hermiq's dataset.
	 *
	 * @return void
	 */
	public function testAnotherSchemasObjectIsLeftAlone(): void {
		$event = new ObjectUpdatingEvent(self::dataset(['template' => true], schema: '8'), self::dataset(['template' => true], schema: '8'));

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemasObjectIsLeftAlone()

	/**
	 * A schema slugged evaldataset in another app's register is left alone.
	 *
	 * @return void
	 */
	public function testAnotherRegistersDatasetIsLeftAlone(): void {
		$event = new ObjectUpdatingEvent(self::dataset(['template' => true]), self::dataset(['template' => true]));

		$this->listener(register: 'otherapp')->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnotherRegistersDatasetIsLeftAlone()

	/**
	 * A create is not an edit: the seed stores the template through it.
	 *
	 * @return void
	 */
	public function testACreateIsNotRefused(): void {
		$event = new ObjectCreatingEvent(self::dataset(['template' => true]));

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testACreateIsNotRefused()
}//end class
