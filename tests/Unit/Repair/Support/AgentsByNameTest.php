<?php

/**
 * Unit tests for AgentsByName, the agent-by-name lookup the budget and incident
 * seeds share.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair\Support
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
 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair\Support;

use OCA\Hermiq\Repair\Support\AgentsByName;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the query (register, schema, limit, system context) and the first
 * exact-name entity rule.
 *
 * @covers \OCA\Hermiq\Repair\Support\AgentsByName
 *
 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
 */
class AgentsByNameTest extends TestCase {

	/**
	 * An object service that answers the given rows to the expected query only.
	 *
	 * @param array<int, mixed> $rows The rows findAll() returns.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $rows): ObjectService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->once())->method('setRegister')->with('hermiq')->willReturnSelf();
		$objectService->expects($this->once())->method('setSchema')->with('agent')->willReturnSelf();
		$objectService->expects($this->once())->method('findAll')
			->with(['limit' => 1000], false, false)
			->willReturn($rows);
		return $objectService;
	}//end objectService()

	/**
	 * An agent row with the name.
	 *
	 * @param string $name The agent's display name.
	 *
	 * @return ObjectEntity
	 */
	private function agent(string $name): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject(['name' => $name]);
		return $entity;
	}//end agent()

	/**
	 * The first entity with the exact name is the answer; non-entities and other
	 * names are skipped; no match is null.
	 *
	 * @return void
	 */
	public function testFindTakesTheFirstExactNameEntity(): void {
		$match = $this->agent(name: 'Support triage');
		$rows = [['name' => 'Support triage'], $this->agent(name: 'support triage'), $match, $this->agent(name: 'Support triage')];

		$this->assertSame($match, AgentsByName::find(objectService: $this->objectService(rows: $rows), name: 'Support triage'));
		$this->assertNull(AgentsByName::find(objectService: $this->objectService(rows: [$this->agent(name: 'Other')]), name: 'Support triage'));

	}//end testFindTakesTheFirstExactNameEntity()
}//end class
