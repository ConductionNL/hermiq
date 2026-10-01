<?php

/**
 * Start fields an agent asks for before a conversation (agents-instruction-variables).
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
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Agent;

use OCA\Hermiq\Service\Agent\StartFields;
use PHPUnit\Framework\TestCase;

/**
 * Tests for StartFields.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */
class StartFieldsTest extends TestCase {

	/**
	 * The department scenario's agent: one required choice, plus one of each other type.
	 *
	 * @return array<string, mixed>
	 */
	private function agent(): array {
		return [
			'startFields' => [
				['key' => 'department', 'label' => 'Department', 'type' => 'select', 'options' => ['Permits', 'Taxes'], 'required' => true],
				['key' => 'case', 'label' => 'Case number', 'type' => 'number'],
				['key' => 'deadline', 'label' => 'Deadline', 'type' => 'date'],
				['key' => 'notes', 'label' => 'Notes', 'type' => 'paragraph'],
				['key' => 'Bad Key', 'label' => 'Refused', 'type' => 'text'],
				['key' => 'kind', 'label' => 'Refused', 'type' => 'colour'],
				['key' => 'department', 'label' => 'Duplicate', 'type' => 'text'],
			],
		];
	}//end agent()

	/**
	 * Malformed and duplicate fields are left out, the rest normalised.
	 *
	 * @return void
	 */
	public function testOnlyWellFormedFieldsCount(): void {
		$fields = StartFields::of(agentData: $this->agent());

		$this->assertSame(['department', 'case', 'deadline', 'notes'], array_column($fields, 'key'));
		$this->assertTrue($fields[0]['required']);
		$this->assertFalse($fields[1]['required']);
		$this->assertSame('', $fields[1]['default']);

	}//end testOnlyWellFormedFieldsCount()

	/**
	 * No more than ten fields are used.
	 *
	 * @return void
	 */
	public function testAtMostTenFields(): void {
		$many = [];
		for ($i = 0; $i < 12; $i++) {
			$many[] = ['key' => 'f' . $i, 'label' => 'F' . $i, 'type' => 'text'];
		}

		$this->assertCount(10, StartFields::of(agentData: ['startFields' => $many]));

	}//end testAtMostTenFields()

	/**
	 * A required field left empty, and answers of the wrong kind, are named per field.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
	 */
	public function testProblemsAreNamedPerField(): void {
		$fields = StartFields::of(agentData: $this->agent());

		$this->assertSame(['department' => 'required'], StartFields::problems(fields: $fields, values: []));
		$this->assertSame(
			['department' => 'not one of the options', 'case' => 'not a number', 'deadline' => 'not a date (YYYY-MM-DD)'],
			StartFields::problems(fields: $fields, values: ['department' => 'Parking', 'case' => 'twelve', 'deadline' => '2026-02-30'])
		);
		$this->assertSame(
			[],
			StartFields::problems(fields: $fields, values: ['department' => 'Permits', 'case' => '12', 'deadline' => '2026-10-02'])
		);

	}//end testProblemsAreNamedPerField()

	/**
	 * Only declared answers are kept, trimmed; empty ones are dropped.
	 *
	 * @return void
	 */
	public function testCleanKeepsDeclaredAnswers(): void {
		$fields = StartFields::of(agentData: $this->agent());

		$this->assertSame(
			['department' => 'Permits', 'case' => '12'],
			StartFields::clean(fields: $fields, values: ['department' => ' Permits ', 'case' => 12, 'notes' => '', 'other' => 'x', 'deadline' => ['no']])
		);

	}//end testCleanKeepsDeclaredAnswers()

	/**
	 * Each field contributes its answer, else its default; a one-line field stays one line.
	 *
	 * @return void
	 */
	public function testAnswersFallBackToTheDefault(): void {
		$fields = StartFields::of(
			agentData: [
				'startFields' => [
					['key' => 'department', 'label' => 'Department', 'type' => 'select', 'options' => ['Permits'], 'default' => 'Permits'],
					['key' => 'subject', 'label' => 'Subject', 'type' => 'text'],
					['key' => 'notes', 'label' => 'Notes', 'type' => 'paragraph'],
				],
			]
		);

		$this->assertSame(
			['department' => 'Permits', 'subject' => 'line one line two', 'notes' => "a\nb"],
			StartFields::answersFor(fields: $fields, values: ['subject' => "line one\n line two", 'notes' => "a\nb"])
		);

	}//end testAnswersFallBackToTheDefault()
}//end class
