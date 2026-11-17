<?php

/**
 * Unit tests for AgentObjectMatcher.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Agent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Agent;

use OCA\Hermiq\Service\Agent\AgentObjectMatcher;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An object is a Hermiq agent only on Hermiq's register and its `agent` schema.
 *
 * @covers \OCA\Hermiq\Service\Agent\AgentObjectMatcher
 */
class AgentObjectMatcherTest extends TestCase {

	/**
	 * Whether an object on the given schema and register reads as an agent.
	 *
	 * Schema 4365 is slugged `agent`, 5018 `conversation`, 9999 is missing.
	 * Register 2428 is `hermiq`, any other `pipelinq`.
	 *
	 * @param string $schema The object's schema.
	 * @param string $register The object's register.
	 *
	 * @return bool
	 */
	private function isAgentOn(string $schema, string $register): bool {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function (int|string $id): Schema {
				if ((int)$id === 9999) {
					throw new RuntimeException('missing');
				}

				$found = new Schema();
				$found->setSlug(((int)$id === 4365) ? 'agent' : 'conversation');
				return $found;
			}
		);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			static function (int|string $id): Register {
				$found = new Register();
				$found->setSlug(((int)$id === 2428) ? 'hermiq' : 'pipelinq');
				return $found;
			}
		);

		$object = new ObjectEntity();
		$object->setSchema($schema);
		$object->setRegister($register);

		return (new AgentObjectMatcher($schemaMapper, $registerMapper))->isAgent($object);
	}//end isAgentOn()

	/**
	 * Hermiq's agent schema on Hermiq's register, by id or by slug, is an agent;
	 * another app's `agent` schema, another schema, a missing schema and no
	 * schema are not.
	 *
	 * @return void
	 */
	public function testOnlyHermiqsAgentSchemaOnHermiqsRegisterMatches(): void {
		$this->assertSame(
			[true, true, false, false, false, false],
			[
				$this->isAgentOn('4365', '2428'),
				$this->isAgentOn('agent', '2428'),
				$this->isAgentOn('4365', '3001'),
				$this->isAgentOn('5018', '2428'),
				$this->isAgentOn('9999', '2428'),
				$this->isAgentOn('', '2428'),
			]
		);
	}//end testOnlyHermiqsAgentSchemaOnHermiqsRegisterMatches()
}//end class
