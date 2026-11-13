<?php

/**
 * Hermiq's own registers are on the tool surface read-only, and only three of them.
 *
 * Hermiq's registers are its governance control plane: an agent that could
 * write them could widen its own grants. OpenRegister derives tools from
 * `x-openregister-mcp`, so what this file declares is exactly what an agent
 * can call. These assertions pin the declaration so a later edit cannot
 * quietly add a write verb or a governance schema.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-mcp-adoption/specs/hermiq-mcp-adoption/spec.md#requirement-declarative-read-only-tool-surface-on-a-curated-schema-subset
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing Guards a declarative register file, not a PHP class.
 */
class McpReadOnlySurfaceTest extends TestCase {

	/**
	 * The governance schemas that must stay off the tool surface, for reads too.
	 *
	 * @var list<string>
	 */
	private const GOVERNANCE = [
		'Approval', 'AgentWebhook', 'GuardrailPolicy', 'ModelPolicy', 'TenantControl', 'Budget',
		'Skill', 'SkillSource', 'Memory', 'UserProfile', 'Incident', 'AiFeature',
		'SessionTurn', 'Conversation', 'Message',
	];

	/**
	 * The shipped register's schemas, decoded.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemas(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'), true);
		self::assertIsArray($register);
		return $register['components']['schemas'];
	}//end schemas()

	/**
	 * Exactly Agent, Schedule and Session declare the dialect.
	 *
	 * @return void
	 */
	public function testExactlyThreeSchemasAreOnTheSurface(): void {
		$declared = array_keys(array_filter($this->schemas(), static fn (array $schema): bool => isset($schema['x-openregister-mcp']) === true));
		sort($declared);

		self::assertSame(['Agent', 'Schedule', 'Session'], $declared);
	}//end testExactlyThreeSchemasAreOnTheSurface()

	/**
	 * Each declares search and get only, read scope, readOnlyHint and a description.
	 *
	 * @return void
	 */
	public function testOnlyReadVerbsAreDeclared(): void {
		foreach (['Agent', 'Schedule', 'Session'] as $name) {
			$mcp = $this->schemas()[$name]['x-openregister-mcp'];
			self::assertTrue($mcp['enabled'], $name);
			self::assertSame(['search', 'get'], array_keys($mcp['tools']), $name);
			foreach ($mcp['tools'] as $verb => $tool) {
				self::assertSame('read', $tool['scope'], "$name.$verb");
				self::assertTrue($tool['readOnlyHint'], "$name.$verb");
				self::assertNotSame('', trim((string)($tool['description'] ?? '')), "$name.$verb");
			}
		}
	}//end testOnlyReadVerbsAreDeclared()

	/**
	 * Every search filter names a property the schema declares.
	 *
	 * @return void
	 */
	public function testSearchFiltersNameRealProperties(): void {
		$expected = [
			'Agent' => ['active', 'type', 'provider', 'model', 'applicationSlug'],
			'Schedule' => ['enabled', 'agentId', 'lastStatus', 'kind'],
			'Session' => ['agentId', 'userId', 'triggerOrigin'],
		];
		foreach ($expected as $name => $filters) {
			$schema = $this->schemas()[$name];
			self::assertSame($filters, $schema['x-openregister-mcp']['tools']['search']['filters'], $name);
			foreach ($filters as $filter) {
				self::assertArrayHasKey($filter, $schema['properties'], "$name filter $filter");
			}
		}
	}//end testSearchFiltersNameRealProperties()

	/**
	 * No schema declares a write verb, and no governance schema declares anything.
	 *
	 * @return void
	 */
	public function testNoWriteVerbAndNoGovernanceSchema(): void {
		foreach ($this->schemas() as $name => $schema) {
			$tools = ($schema['x-openregister-mcp']['tools'] ?? []);
			self::assertSame([], array_intersect(['create', 'update', 'delete'], array_keys($tools)), $name);
			if (in_array($name, self::GOVERNANCE, true) === true) {
				self::assertArrayNotHasKey('x-openregister-mcp', $schema, $name);
			}
		}
	}//end testNoWriteVerbAndNoGovernanceSchema()
}//end class
