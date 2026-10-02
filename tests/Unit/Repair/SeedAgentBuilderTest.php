<?php

/**
 * The Agent builder seed (agents-plain-language-builder): one skill and one
 * agent however often the step runs, the skill installed on the agent, and no
 * tool granted, so the builder cannot write an agent itself.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-described-agent-becomes-a-draft-in-chat-req-agbuild-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\SeedAgentBuilder;
use OCA\Hermiq\Service\SeedFreshnessService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for SeedAgentBuilder.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-described-agent-becomes-a-draft-in-chat-req-agbuild-001
 */
class SeedAgentBuilderTest extends TestCase {

	/**
	 * A stateful object store: what is saved is found on the next run.
	 *
	 * @return ObjectService
	 */
	private function store(): ObjectService {
		return new class() extends ObjectService {
			private string $schema = '';

			/**
			 * @var array<string, array<string, ObjectEntity>>
			 */
			public array $objects = [];

			/**
			 * @var int
			 */
			public int $writes = 0;

			public function __construct() {
			}

			public function setRegister(mixed $register): static {
				return $this;
			}

			public function setSchema(mixed $schema): static {
				$this->schema = (string)$schema;
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return array_values($this->objects[$this->schema] ?? []);
			}

			public function saveObject(
				array|ObjectEntity $object,
				?array $extend = [],
				mixed $register = null,
				mixed $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $silent = false,
				bool $_validation = true,
				?array $uploadedFiles = null,
				?\OCP\IUser $currentUser = null,
				bool $failIfExists = false,
				bool $_unowned = false,
				bool $_dedupOverride = false,
			): ObjectEntity {
				$this->writes++;
				$id = $uuid ?? ((string)$schema . '-' . $this->writes);
				$entity = new ObjectEntity();
				$entity->setUuid($id);
				$entity->setObject(is_array($object) ? $object : $object->getObject());
				$this->objects[(string)$schema][$id] = $entity;
				return $entity;
			}
		};
	}//end store()

	/**
	 * Run the step once against the store.
	 *
	 * @param ObjectService $store The store.
	 *
	 * @return void
	 */
	private function run(ObjectService $store): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);

		(new SeedAgentBuilder(container: $container, logger: new NullLogger(), freshness: new SeedFreshnessService()))
			->run(output: $this->createMock(IOutput::class));
	}//end run()

	/**
	 * Two runs leave one skill and one agent, installed on each other, with no tools.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-described-agent-becomes-a-draft-in-chat-req-agbuild-001
	 */
	public function testTwoRunsSeedOneSkillAndOneAgent(): void {
		$store = $this->store();
		$this->run(store: $store);
		$writes = $store->writes;
		$this->run(store: $store);

		$this->assertSame($writes, $store->writes, 'A second run writes nothing.');
		$this->assertCount(1, $store->objects['agentskill']);
		$this->assertCount(1, $store->objects['agent']);

		$skill = array_values($store->objects['agentskill'])[0];
		$agent = array_values($store->objects['agent'])[0];
		$this->assertSame([(string)$skill->getUuid()], $agent->getObject()['skillInstalls']);
		$this->assertSame([(string)$agent->getUuid()], $skill->getObject()['installedOn']);
		$this->assertSame([], $agent->getObject()['tools']);
		$this->assertStringContainsString('hermiq-agent-draft', $skill->getObject()['body']);

	}//end testTwoRunsSeedOneSkillAndOneAgent()

	/**
	 * An existing Agent builder, edited or switched off by an admin, is left alone.
	 *
	 * @return void
	 */
	public function testAnExistingBuilderIsLeftAlone(): void {
		$store = $this->store();
		$existing = new ObjectEntity();
		$existing->setUuid('agent-x');
		$existing->setObject(['name' => SeedAgentBuilder::AGENT_NAME, 'active' => false]);
		$store->objects['agent'] = ['agent-x' => $existing];

		$this->run(store: $store);

		$this->assertCount(1, $store->objects['agent']);
		$this->assertFalse($store->objects['agent']['agent-x']->getObject()['active']);

	}//end testAnExistingBuilderIsLeftAlone()

	/**
	 * The seeded objects pass the real register fragments.
	 *
	 * @return void
	 */
	public function testTheSeedsPassTheRealSchemaFragments(): void {
		$store = $this->store();
		$this->run(store: $store);

		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'));
		foreach (['agentskill' => 'Skill', 'agent' => 'Agent'] as $slug => $key) {
			$fragment = $register->components->schemas->{$key};
			unset($fragment->authorization);
			$fragment = json_decode((string)preg_replace('/,?\s*"\$ref":\s*"[^"]*"/', '', (string)json_encode($fragment)));
			foreach ($store->objects[$slug] as $object) {
				$result = (new Validator())->validate(json_decode((string)json_encode($object->getObject())), $fragment);
				$this->assertTrue($result->isValid(), $key . ' seed refused by its fragment');
			}

			$this->assertFalse(
				(new Validator())->validate(json_decode('{"name": 5}'), $fragment)->isValid(),
				'negative control for ' . $key
			);
		}

	}//end testTheSeedsPassTheRealSchemaFragments()
}//end class
