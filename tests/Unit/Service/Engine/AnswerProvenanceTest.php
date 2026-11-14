<?php

/**
 * Hermiq AnswerProvenance: the record holds identities and outcomes, never content.
 *
 * Drives the real builder over a real RunTraceCollector filled the way the engine,
 * the guardrail and the tool invoker fill it (chat-how-an-answer-came-about task 1).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
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
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-every-answer-records-how-it-came-about-req-atrn-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\AnswerProvenance;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the provenance record.
 */
final class AnswerProvenanceTest extends TestCase {

	/**
	 * A trace of one answered turn: timing steps, a read, a refused call with its
	 * argument, a write that produced an artefact, a web call with its target and
	 * an output filter that masked part of the answer.
	 *
	 * @return RunTraceCollector The trace.
	 */
	private function trace(): RunTraceCollector {
		$trace = new RunTraceCollector();
		$trace->endStep(token: $trace->startStep(type: 'context', name: 'Context retrieval'), outcome: 'ok');
		$trace->endStep(token: $trace->startStep(type: 'tool', name: 'hermiq.readFile'), outcome: 'ok');
		$trace->endStep(
			token: $trace->startStep(type: 'tool', name: 'mail.send'),
			outcome: 'denied',
			extra: ['error' => 'grant_constraint_violated', 'argument' => 'to', 'constraint' => ['mode' => 'allow', 'permitted' => ['a@b.nl']]]
		);
		$trace->endStep(
			token: $trace->startStep(type: 'tool', name: 'notes.create'),
			outcome: 'ok',
			extra: ['artefact' => ['type' => 'note', 'id' => '77'], 'arguments' => ['body' => 'Geheime notitie']]
		);
		$trace->endStep(token: $trace->startStep(type: 'tool', name: 'web.fetch'), outcome: 'ok', extra: ['target' => 'https://example.org/x']);
		$trace->endStep(token: $trace->startStep(type: 'llm', name: 'LLM generation'), outcome: 'ok');
		$trace->endStep(token: $trace->startStep(type: 'guardrail', name: 'Output filter'), outcome: 'redacted');
		$trace->recordProviderDisclosure(
			[
				'feature' => 'chat-companion',
				'provider' => 'anthropic',
				'model' => 'claude-sonnet-4-5',
				'residency' => 'eu',
				'location' => 'Frankfurt',
				'dataUse' => 'no-training',
				'termsReference' => 'https://terms',
			]
		);
		return $trace;
	}//end trace()

	/**
	 * The record names the model, the sources and every tool and guardrail step, and nothing else.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#scenario-a-streamed-answer-gets-the-same-record-as-a-sent-one
	 */
	public function testTheRecordHoldsModelSourcesAndStepOutcomes(): void {
		$record = (new AnswerProvenance())->build(
			trace: $this->trace(),
			sources: [
				['id' => 12, 'type' => 'file', 'name' => 'Inkoopbeleid 2026.pdf', 'text' => 'Artikel 4: drempelbedragen', 'similarity' => 0.91, 'file_path' => '/Beleid/Inkoopbeleid 2026.pdf'],
			]
		);

		$this->assertSame(
			[
				'model' => ['feature' => 'chat-companion', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'residency' => 'eu', 'location' => 'Frankfurt'],
				'sources' => [['id' => '12', 'type' => 'file', 'name' => 'Inkoopbeleid 2026.pdf']],
				'steps' => [
					['type' => 'tool', 'name' => 'hermiq.readFile', 'outcome' => 'ok'],
					['type' => 'tool', 'name' => 'mail.send', 'outcome' => 'denied'],
					['type' => 'tool', 'name' => 'notes.create', 'outcome' => 'ok', 'artefact' => ['type' => 'note', 'id' => '77']],
					['type' => 'tool', 'name' => 'web.fetch', 'outcome' => 'ok'],
					['type' => 'guardrail', 'name' => 'Output filter', 'outcome' => 'redacted'],
				],
			],
			$record
		);
	}//end testTheRecordHoldsModelSourcesAndStepOutcomes()

	/**
	 * A refused call's argument and constraint, a write's arguments, a web target and an excerpt never reach the record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-every-answer-records-how-it-came-about-req-atrn-001
	 */
	public function testNoArgumentResultOrDocumentTextIsCopied(): void {
		$json = (string)json_encode(
			(new AnswerProvenance())->build(
				trace: $this->trace(),
				sources: [['id' => 12, 'type' => 'file', 'name' => 'Inkoopbeleid 2026.pdf', 'text' => 'Artikel 4: drempelbedragen']]
			)
		);

		foreach (['a@b.nl', 'grant_constraint_violated', '"argument"', 'Geheime notitie', 'example.org', 'drempelbedragen', 'no-training', 'durationMs', 'startedAt'] as $absent) {
			$this->assertStringNotContainsString($absent, $json);
		}
	}//end testNoArgumentResultOrDocumentTextIsCopied()

	/**
	 * Without a trace the record still lists the sources, with no model and no steps.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-every-answer-records-how-it-came-about-req-atrn-001
	 */
	public function testWithoutATraceOnlyTheSourcesAreKnown(): void {
		$this->assertSame(
			['model' => null, 'sources' => [['id' => '5', 'type' => 'object', 'name' => '']], 'steps' => []],
			(new AnswerProvenance())->build(trace: null, sources: [['id' => '5', 'type' => 'object'], 'not-a-source'])
		);
	}//end testWithoutATraceOnlyTheSourcesAreKnown()
}//end class
