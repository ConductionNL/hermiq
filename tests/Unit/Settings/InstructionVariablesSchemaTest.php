<?php

/**
 * The register carries what agents-instruction-variables writes: an agent's
 * start fields, a session's answers and a schedule's values. Each payload the
 * code writes is validated against the real fragment, with negative controls
 * so the test can fail.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the startFields / startValues fragments.
 *
 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */
class InstructionVariablesSchemaTest extends TestCase {

	/**
	 * The department field from the spec's scenario.
	 *
	 * @var array<string, mixed>
	 */
	private const DEPARTMENT = ['key' => 'department', 'label' => 'Department', 'type' => 'select', 'options' => ['Permits', 'Taxes'], 'required' => true, 'default' => 'Permits'];

	/**
	 * An agent uuid.
	 */
	private const AGENT = '0b7a4d7e-3f2c-4e55-9a51-6f3f2b1c0d11';

	/**
	 * Validate a payload against a real register fragment.
	 *
	 * @param string               $schema  The schema key.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return bool
	 */
	private function valid(string $schema, array $payload): bool {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'));
		$fragment = $register->components->schemas->{$schema};
		unset($fragment->authorization);
		// OpenRegister reads `$ref` as a relation to another schema and stores a
		// uuid; it is not a JSON Schema reference, so it is dropped here.
		$fragment = json_decode((string)preg_replace('/,?\s*"\$ref":\s*"[^"]*"/', '', (string)json_encode($fragment)));
		return (new Validator())->validate(json_decode((string)json_encode($payload)), $fragment)->isValid();
	}//end valid()

	/**
	 * The three properties are declared, and the register version moved so the import runs.
	 *
	 * @return void
	 */
	public function testThePropertiesAreDeclaredAndTheVersionMoved(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'), true);
		$schemas = $register['components']['schemas'];

		$this->assertSame(10, ($schemas['Agent']['properties']['startFields']['maxItems'] ?? null));
		$this->assertSame('^[a-z][a-z0-9_]{0,31}$', ($schemas['Agent']['properties']['startFields']['items']['properties']['key']['pattern'] ?? null));
		$this->assertSame('object', ($schemas['Session']['properties']['startValues']['type'] ?? null));
		$this->assertSame('object', ($schemas['Schedule']['properties']['startValues']['type'] ?? null));
		$this->assertTrue(version_compare((string)$register['info']['version'], '0.47.0', '>='));

	}//end testThePropertiesAreDeclaredAndTheVersionMoved()

	/**
	 * What the agent form, the chat page and the schedule write is accepted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
	 */
	public function testTheWrittenPayloadsAreAccepted(): void {
		$this->assertTrue($this->valid('Agent', ['name' => 'Vergunningen helper', 'startFields' => [self::DEPARTMENT, ['key' => 'case_no', 'label' => 'Case number', 'type' => 'number']]]));
		$this->assertTrue($this->valid('Session', ['agentId' => self::AGENT, 'userId' => 'fatima', 'title' => 'Dakkapel', 'startValues' => ['department' => 'Permits']]));
		$this->assertTrue($this->valid('Schedule', ['name' => 'Ochtendronde', 'agentId' => self::AGENT, 'kind' => 'cron', 'cronExpr' => '0 7 * * *', 'deliver' => 'talk', 'enabled' => true, 'startValues' => ['department' => 'Taxes']]));

	}//end testTheWrittenPayloadsAreAccepted()

	/**
	 * Negative controls: a bad key, an unknown type, eleven fields, a non-string answer.
	 *
	 * @return void
	 */
	public function testTheFragmentRefusesWhatTheCodeNeverWrites(): void {
		$this->assertFalse($this->valid('Agent', ['name' => 'x', 'startFields' => [['key' => 'Department', 'label' => 'D', 'type' => 'text']]]));
		$this->assertFalse($this->valid('Agent', ['name' => 'x', 'startFields' => [['key' => 'd', 'label' => 'D', 'type' => 'colour']]]));
		$eleven = [];
		for ($i = 0; $i < 11; $i++) {
			$eleven[] = ['key' => 'f' . $i, 'label' => 'F', 'type' => 'text'];
		}

		$this->assertFalse($this->valid('Agent', ['name' => 'x', 'startFields' => $eleven]));
		$this->assertFalse($this->valid('Session', ['agentId' => self::AGENT, 'userId' => 'fatima', 'startValues' => ['department' => ['Permits']]]));

	}//end testTheFragmentRefusesWhatTheCodeNeverWrites()
}//end class
