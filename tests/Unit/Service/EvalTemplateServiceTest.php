<?php

/**
 * Unit tests for EvalTemplateService: "Use for this agent" (reuse-scheduled-bias-evals).
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
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Kernel\Contracts\SeedCustodyServiceInterface;
use OCA\Hermiq\Repair\SeedBiasEvalTemplate;
use OCA\Hermiq\Service\EvalActionException;
use OCA\Hermiq\Service\EvalOwnership;
use OCA\Hermiq\Service\EvalTemplateService;
use OCA\Hermiq\Tests\Unit\Support\InMemoryEvalObjects;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A template becomes an editable dataset of the caller's own, linked to their agent.
 *
 * @covers \OCA\Hermiq\Service\EvalTemplateService
 * @uses   \OCA\Hermiq\Service\EvalOwnership
 * @uses   \OCA\Hermiq\Service\EvalActionException
 * @uses   \OCA\Hermiq\Repair\SeedBiasEvalTemplate
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
 */
class EvalTemplateServiceTest extends TestCase {

	/**
	 * The object store.
	 *
	 * @var InMemoryEvalObjects
	 */
	private InMemoryEvalObjects $objects;

	/**
	 * Seed the store with the English template, owned by the system.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new InMemoryEvalObjects();
		$this->objects->put(schema: 'evaldataset', uuid: 'tpl-en', data: SeedBiasEvalTemplate::template(language: 'en'), owner: '__system__');
		$this->objects->put(schema: 'evaldataset', uuid: 'plain', data: ['name' => 'Woo triage', 'cases' => []], owner: 'bob');
	}//end setUp()

	/**
	 * The service, with agent "agent-1" owned by alice.
	 *
	 * @return EvalTemplateService
	 */
	private function service(): EvalTemplateService {
		$agent = new Agent();
		$agent->setUuid('agent-1');
		$agent->setOwner('alice');
		$agent->setName('Permit intake helper');

		$mapper = $this->createMock(AgentMapper::class);
		$mapper->method('findByUuid')->willReturnCallback(
			static function (string $uuid) use ($agent): Agent {
				if ($uuid !== 'agent-1') {
					throw new RuntimeException('not found');
				}

				return $agent;
			}
		);
		$custody = $this->createMock(SeedCustodyServiceInterface::class);
		$custody->method('actsAsOwner')->willReturnCallback(static fn (?string $owner, string $uid): bool => $owner === $uid);

		return new EvalTemplateService(
			objectService: $this->objects,
			ownership: new EvalOwnership(objectService: $this->objects, agentMapper: $mapper, seedCustody: $custody)
		);
	}//end service()

	/**
	 * "Use for this agent" copies all twenty pairs into a dataset the caller owns, linked to the agent.
	 *
	 * @return void
	 */
	public function testUseForThisAgentCopiesTheTemplateForTheCaller(): void {
		$copy = $this->service()->useForAgent(templateId: 'tpl-en', agentId: 'agent-1', uid: 'alice');

		$this->assertSame('Bias check: public services — Permit intake helper', $copy['name']);
		$this->assertSame('agent-1', $copy['agentId']);
		$this->assertSame(20, $copy['caseCount']);

		$this->assertCount(1, $this->objects->saved);
		$save = $this->objects->saved[0];
		$this->assertSame('evaldataset', $save['schema']);
		$this->assertNull($save['uuid'], 'A copy is a new dataset, never a write to the template.');
		$this->assertSame('alice', $save['owner']);
		$this->assertFalse($save['object']['template'], 'The copy is editable, not a template.');
		$this->assertSame('bias-public-services', $save['object']['templateKey']);
		$this->assertSame('en', $save['object']['language']);
		$this->assertSame('tpl-en', $save['object']['copiedFrom']);
		$this->assertSame(SeedBiasEvalTemplate::template(language: 'en')['cases'], $save['object']['cases']);
		$this->assertSame('alice', $this->objects->objects['evaldataset'][$copy['id']]->getOwner());
	}//end testUseForThisAgentCopiesTheTemplateForTheCaller()

	/**
	 * Someone who does not own the agent gets 404 and nothing is written.
	 *
	 * @return void
	 */
	public function testANonOwnerOfTheAgentIsRefusedWith404(): void {
		try {
			$this->service()->useForAgent(templateId: 'tpl-en', agentId: 'agent-1', uid: 'mallory');
			$this->fail('A non-owner must be refused.');
		} catch (EvalActionException $e) {
			$this->assertSame(404, $e->getCode());
			$this->assertSame('Agent not found', $e->getMessage());
		}

		$this->assertSame([], $this->objects->saved);
	}//end testANonOwnerOfTheAgentIsRefusedWith404()

	/**
	 * A dataset that is not a template cannot be used this way.
	 *
	 * @return void
	 */
	public function testAPlainDatasetIsNotATemplate(): void {
		$this->expectException(EvalActionException::class);
		$this->expectExceptionCode(400);

		$this->service()->useForAgent(templateId: 'plain', agentId: 'agent-1', uid: 'alice');
	}//end testAPlainDatasetIsNotATemplate()

	/**
	 * A template that does not exist answers 404.
	 *
	 * @return void
	 */
	public function testAMissingTemplateIs404(): void {
		$this->expectException(EvalActionException::class);
		$this->expectExceptionCode(404);
		$this->expectExceptionMessage('Dataset not found');

		$this->service()->useForAgent(templateId: 'nope', agentId: 'agent-1', uid: 'alice');
	}//end testAMissingTemplateIs404()
}//end class
