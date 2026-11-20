<?php

/**
 * The EvalSchedule schema in the REAL register file (reuse-scheduled-bias-evals, task 3).
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The declaration EvalScheduleTask reads, and the import needs.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
class EvalScheduleSchemaTest extends TestCase {

	/**
	 * The register, decoded as arrays.
	 *
	 * @return array<string, mixed>
	 */
	private function register(): array {
		return json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
	}//end register()

	/**
	 * The schema is listed on the register under its slug, and the version moved past 0.78.0.
	 *
	 * @return void
	 */
	public function testTheSchemaIsListedAndTheVersionBumped(): void {
		$register = $this->register();

		$this->assertSame('evalschedule', $register['components']['schemas']['EvalSchedule']['slug']);
		$this->assertContains('evalschedule', $register['components']['registers']['hermiq']['schemas']);
		$this->assertTrue(version_compare($register['info']['version'], '0.78.0', '>'));
	}//end testTheSchemaIsListedAndTheVersionBumped()

	/**
	 * A schedule names a dataset and an agent, both removed with what they point at.
	 *
	 * @return void
	 */
	public function testAScheduleReferencesItsDatasetAndAgent(): void {
		$schema = $this->register()['components']['schemas']['EvalSchedule'];

		$this->assertSame(['datasetId', 'agentId'], $schema['required']);
		$this->assertSame('evaldataset', $schema['properties']['datasetId']['$ref']);
		$this->assertSame('agent', $schema['properties']['agentId']['$ref']);
		$this->assertSame('CASCADE', $schema['properties']['datasetId']['onDelete']);
		$this->assertSame('CASCADE', $schema['properties']['agentId']['onDelete']);
	}//end testAScheduleReferencesItsDatasetAndAgent()

	/**
	 * The job's fields: monthly by default, off until enabled, derived next run and last run.
	 *
	 * @return void
	 */
	public function testTheJobFieldsAreDeclared(): void {
		$properties = $this->register()['components']['schemas']['EvalSchedule']['properties'];

		$this->assertSame('0 6 1 * *', $properties['cronExpr']['default']);
		$this->assertFalse($properties['enabled']['default']);
		$this->assertSame('date-time', $properties['nextRun']['format']);
		$this->assertSame('evalrun', $properties['lastRunId']['$ref']);
		$this->assertSame('integer', $properties['regressionThresholdPercent']['type']);
	}//end testTheJobFieldsAreDeclared()
}//end class
