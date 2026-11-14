<?php

/**
 * The Agent schema makes OpenRegister's bulk saves raise the object events.
 *
 * OpenRegister's bulk save writes rows without the save events unless the
 * schema, its register or the instance sets `fireEventsOnBulkWrites`. The
 * approval-waiver audit listens to those events, so without the setting a
 * waiver written through POST /api/bulk/hermiq/agent/save leaves no record.
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
 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing Guards a declarative register file, not a PHP class.
 */
class AgentBulkWriteEventsTest extends TestCase {

	/**
	 * The shipped register, decoded.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'), true);
		$this->assertIsArray($register, 'The register must be valid JSON.');

		return $register;
	}//end register()

	/**
	 * The Agent schema turns the save events on for bulk writes, as a real boolean.
	 *
	 * @return void
	 */
	public function testTheAgentSchemaFiresEventsOnBulkWrites(): void {
		$agent = $this->register()['components']['schemas']['Agent'];

		$this->assertSame(true, ($agent['configuration']['fireEventsOnBulkWrites'] ?? null));
	}//end testTheAgentSchemaFiresEventsOnBulkWrites()

	/**
	 * The schema and register versions moved with it: the import is gated on them.
	 *
	 * @return void
	 */
	public function testTheVersionsCarryTheChange(): void {
		$register = $this->register();

		$this->assertTrue(version_compare((string)$register['info']['version'], '0.52.0', '>='));
		$this->assertTrue(version_compare((string)$register['components']['schemas']['Agent']['version'], '0.13.0', '>='));
	}//end testTheVersionsCarryTheChange()
}//end class
