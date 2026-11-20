<?php

/**
 * Unit tests for EvalTemplateCopier (reuse-scheduled-bias-evals).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Eval
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

namespace OCA\Hermiq\Tests\Unit\Service\Eval;

use InvalidArgumentException;
use OCA\Hermiq\Repair\SeedBiasEvalTemplate;
use OCA\Hermiq\Service\Eval\EvalTemplateCopier;
use OCA\Hermiq\Kernel\Contracts\SeedCustodyServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\Eval\EvalTemplateCopier
 * @uses   \OCA\Hermiq\Repair\SeedBiasEvalTemplate
 */
class EvalTemplateCopierTest extends TestCase {

	/**
	 * The seeded English template as a stored object.
	 *
	 * @return ObjectEntity
	 */
	private static function template(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('tpl-en');
		$entity->setObject(SeedBiasEvalTemplate::templates()[0]);
		return $entity;
	}//end template()

	/**
	 * The copy keeps all twenty pairs and the key, names the agent, links it, and is not a template.
	 *
	 * @return void
	 */
	public function testTheCopyIsAnEditableDatasetForTheAgent(): void {
		$copy = EvalTemplateCopier::copyOf(template: self::template()->getObject(), agentId: 'agent-1', agentName: 'Permit intake helper');

		self::assertSame('Bias check: public services — Permit intake helper', $copy['name']);
		self::assertCount(20, $copy['cases']);
		self::assertSame(self::template()->getObject()['cases'], $copy['cases']);
		self::assertSame('bias-public-services', $copy['templateKey']);
		self::assertSame('agent-1', $copy['agentId']);
		self::assertSame('en', $copy['language']);
		self::assertFalse($copy['template']);
	}//end testTheCopyIsAnEditableDatasetForTheAgent()

	/**
	 * Without an agent name the title is the template's own, less the suffix.
	 *
	 * @return void
	 */
	public function testTheTitleDropsTheTemplateSuffix(): void {
		$copy = EvalTemplateCopier::copyOf(template: ['name' => 'Biascheck: publieke dienstverlening (sjabloon)'], agentId: 'a', agentName: '');

		self::assertSame('Biascheck: publieke dienstverlening', $copy['name']);
	}//end testTheTitleDropsTheTemplateSuffix()

	/**
	 * The copy is stored in the hermiq dataset schema with the caller's rights (RBAC on, so the caller owns it).
	 *
	 * @return void
	 */
	public function testTheCopyIsStoredAsTheCaller(): void {
		$service = $this->createMock(ObjectService::class);
		$service->expects($this->once())->method('saveObject')->with(
			$this->callback(static fn (array $object): bool => $object['agentId'] === 'agent-1' && $object['template'] === false),
			$this->callback(static fn (ObjectSaveOptions $options): bool => $options->target->register === 'hermiq' && $options->target->schema === 'evaldataset'),
		)->willReturnCallback(static function (array $object): ObjectEntity {
			$saved = new ObjectEntity();
			$saved->setUuid('copy-1');
			$saved->setObject($object);
			return $saved;
		});

		$copy = (new EvalTemplateCopier($service, $this->createMock(AgentMapper::class), $this->createMock(SeedCustodyServiceInterface::class)))->copy(template: self::template(), agentId: 'agent-1', agentName: 'Helper');

		self::assertSame('copy-1', $copy->getUuid());
	}//end testTheCopyIsStoredAsTheCaller()

	/**
	 * A dataset that is not a template is refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testANonTemplateIsRefused(): void {
		$service = $this->createMock(ObjectService::class);
		$service->expects($this->never())->method('saveObject');
		$dataset = new ObjectEntity();
		$dataset->setObject(['name' => 'Mine', 'templateKey' => 'bias-public-services', 'template' => false]);

		$this->expectException(InvalidArgumentException::class);
		(new EvalTemplateCopier($service, $this->createMock(AgentMapper::class), $this->createMock(SeedCustodyServiceInterface::class)))->copy(template: $dataset, agentId: 'agent-1', agentName: 'Helper');
	}//end testANonTemplateIsRefused()
}//end class
