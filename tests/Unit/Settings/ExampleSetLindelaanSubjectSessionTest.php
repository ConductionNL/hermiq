<?php

/**
 * Example data for DqZaakAssistent's Assistent card: Pieter's session about
 * the Lindelaan Woo case, its question and answer, and the proposal the
 * assistant waits on ("Voorstel: taak aanmaken").
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-a-session-may-name-the-record-it-is-about
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The session about the Lindelaan case.
 */
class ExampleSetLindelaanSubjectSessionTest extends TestCase {

	/**
	 * The Lindelaan case: portaliq's fragment writes it, so this fragment finds it by its business key.
	 */
	private const CASE_REF = '{{lookup:case}}';

	/**
	 * The lookup that finds the Lindelaan case.
	 */
	private const CASE_LOOKUP = ['register' => 'dossiq', 'schema' => 'case', 'where' => ['externalReference' => 'MZ-ZDR-2026-0412']];

	/**
	 * The Woo-triage agent of this set.
	 */
	private const AGENT_REF = '{{object:agent-woo-triage.id}}';

	/**
	 * The set's objects keyed by their key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function objects(): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$out = [];
		foreach ($set['objects'] as $object) {
			$out[$object['key']] = $object;
		}

		return $out;
	}//end objects()

	/**
	 * Pieter's session names the Lindelaan case as its subject and the Woo-triage agent.
	 *
	 * @return void
	 */
	public function testThePieterSessionIsAboutTheLindelaanCase(): void {
		$session = ($this->objects()['session-lindelaan-pieter'] ?? null);
		$this->assertNotNull(actual: $session);
		$this->assertSame(expected: ['hermiq', 'agentsession'], actual: [$session['register'], $session['schema']]);
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $session['owner']);
		$this->assertSame(expected: self::CASE_LOOKUP, actual: $session['lookups']['case']);
		$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $session['data']['userId']);
		$this->assertSame(expected: self::AGENT_REF, actual: $session['data']['agentId']);
		$this->assertSame(
			expected: ['objectUuid' => self::CASE_REF, 'register' => 'dossiq', 'schema' => 'case', 'appId' => 'dossiq'],
			actual: $session['data']['metadata']['subject']
		);
	}//end testThePieterSessionIsAboutTheLindelaanCase()

	/**
	 * The session holds the board's question and answer, in that order.
	 *
	 * @return void
	 */
	public function testTheSessionHoldsTheQuestionAndTheAnswer(): void {
		$turns = [];
		foreach ($this->objects() as $object) {
			if ($object['schema'] === 'agentsessionturn') {
				$turns[] = $object;
			}
		}

		$this->assertSame(expected: ['user', 'assistant'], actual: array_map(static fn (array $t): string => $t['data']['role'], $turns));
		foreach ($turns as $turn) {
			$this->assertSame(expected: '{{object:session-lindelaan-pieter.id}}', actual: $turn['data']['sessionId']);
			$this->assertSame(expected: '{{cast:pieter.uid}}', actual: $turn['owner']);
		}

		$this->assertSame(expected: 'Welke documenten moeten nog beoordeeld?', actual: $turns[0]['data']['content']);
		$this->assertStringStartsWith(prefix: 'Drie documenten staan nog op te beoordelen', string: $turns[1]['data']['content']);
	}//end testTheSessionHoldsTheQuestionAndTheAnswer()

	/**
	 * The assistant waits on Pieter for one held tool call: create the task.
	 *
	 * @return void
	 */
	public function testTheAssistantWaitsOnPieterToCreateTheTask(): void {
		$approval = ($this->objects()['ai-lindelaan-voorstel-taak'] ?? null);
		$this->assertNotNull(actual: $approval);
		$data = $approval['data'];
		$this->assertSame(expected: 'approval', actual: $approval['schema']);
		$this->assertSame(expected: ['pending', 'toolcall'], actual: [$data['status'], $data['sourceType']]);
		$this->assertSame(expected: [self::AGENT_REF, 'dossiq.createTask'], actual: [$data['agentId'], $data['toolId']]);
		$this->assertSame(expected: ['{{cast:pieter.uid}}', 'user'], actual: [$data['reviewer'], $data['reviewerType']]);
		$this->assertSame(expected: self::CASE_LOOKUP, actual: $approval['lookups']['case']);
		$this->assertSame(expected: self::CASE_REF, actual: $data['toolArguments']['case']);
		$this->assertSame(expected: 'Taak Offerte beoordelen voor Pieter Jansen, klaar op 10 okt.', actual: $data['prompt']);
	}//end testTheAssistantWaitsOnPieterToCreateTheTask()
}//end class
