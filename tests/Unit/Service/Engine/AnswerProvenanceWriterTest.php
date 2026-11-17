<?php

/**
 * Hermiq AnswerProvenanceWriter: the account in plain language, per sentence.
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
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-can-read-how-an-answer-came-about-in-plain-language-req-atrn-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\AnswerProvenanceWriter;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the account writer.
 */
final class AnswerProvenanceWriterTest extends TestCase {

	/**
	 * A writer over an IL10N that translates through the given catalogue.
	 *
	 * @param array<string, string> $catalogue Translations by source text.
	 *
	 * @return AnswerProvenanceWriter The writer.
	 */
	private function writer(array $catalogue = []): AnswerProvenanceWriter {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => vsprintf(($catalogue[$text] ?? $text), (array)$parameters)
		);
		$l10n->method('n')->willReturnCallback(
			static function (string $singular, string $plural, int $count, array $parameters = []) use ($catalogue): string {
				$text = ($count === 1 ? $singular : $plural);
				$text = ($catalogue[$text] ?? $text);
				return vsprintf(str_replace('%n', (string)$count, $text), $parameters);
			}
		);
		return new AnswerProvenanceWriter(l10n: $l10n);
	}//end writer()

	/**
	 * The legal aid worker's answer: model and place, two documents, a done call, a refused call and the final say.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#scenario-a-legal-aid-worker-checks-what-an-answer-rests-on
	 */
	public function testTheAccountNamesModelDocumentsCallsAndEndsWithTheFinalSay(): void {
		$sentences = $this->writer()->sentences(
			record: [
				'model' => ['feature' => 'chat-companion', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'residency' => 'eu', 'location' => 'Frankfurt'],
				'sources' => [['id' => '1', 'type' => 'file', 'name' => 'Huurrecht.pdf'], ['id' => '2', 'type' => 'file', 'name' => 'Uitspraak 2025-114.pdf']],
				'steps' => [
					['type' => 'tool', 'name' => 'hermiq.readFile', 'outcome' => 'ok'],
					['type' => 'tool', 'name' => 'mail.send', 'outcome' => 'denied'],
				],
			]
		);

		$this->assertSame(
			[
				'Answered by claude-sonnet-4-5 (anthropic), which runs in the EU (Frankfurt).',
				'Used 2 documents: Huurrecht.pdf, Uitspraak 2025-114.pdf.',
				'Called 1 tool: hermiq.readFile (done).',
				'1 call was refused: mail.send (this agent may not use it).',
				'You decide what to do with this answer.',
			],
			$sentences
		);
	}//end testTheAccountNamesModelDocumentsCallsAndEndsWithTheFinalSay()

	/**
	 * A Dutch reader gets Dutch sentences, still ending with the final say.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-how-an-answer-came-about/tasks.md#task-2-how-this-answer-came-about-on-the-chat-page
	 */
	public function testADutchReaderReadsDutch(): void {
		$sentences = $this->writer(
			[
				'Answered by %1$s (%2$s), which runs %3$s.' => 'Beantwoord door %1$s (%2$s), dat %3$s draait.',
				'on this server' => 'op deze server',
				'No documents were used.' => 'Er zijn geen documenten gebruikt.',
				'No tools were called.' => 'Er zijn geen tools aangeroepen.',
				'You decide what to do with this answer.' => 'Jij beslist wat je met dit antwoord doet.',
			]
		)->sentences(record: ['model' => ['provider' => 'ollama', 'model' => 'qwen3', 'residency' => 'on-premise'], 'sources' => [], 'steps' => []]);

		$this->assertSame(
			[
				'Beantwoord door qwen3 (ollama), dat op deze server draait.',
				'Er zijn geen documenten gebruikt.',
				'Er zijn geen tools aangeroepen.',
				'Jij beslist wat je met dit antwoord doet.',
			],
			$sentences
		);
	}//end testADutchReaderReadsDutch()

	/**
	 * Filter actions and a stopped run are said; an unrecorded model is said as such.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-can-read-how-an-answer-came-about-in-plain-language-req-atrn-002
	 */
	public function testFiltersAndAStopAreSaid(): void {
		$sentences = $this->writer()->sentences(
			record: [
				'model' => null,
				'sources' => [['id' => '9', 'type' => 'object', 'name' => '']],
				'steps' => [
					['type' => 'guardrail', 'name' => 'Input filter', 'outcome' => 'redacted'],
					['type' => 'tool', 'name' => 'flow.run', 'outcome' => 'awaiting_approval'],
					['type' => 'guardrail', 'name' => 'Output filter', 'outcome' => 'blocked'],
					['type' => 'guard', 'name' => 'limit_reached', 'outcome' => 'stopped'],
				],
			]
		);

		$this->assertSame(
			[
				'Which model answered was not recorded.',
				'Used 1 document: 9.',
				'Called 1 tool: flow.run (waiting for approval).',
				'The organisation\'s input filter masked part of the question.',
				'The organisation\'s output filter blocked part of the answer.',
				'The run was stopped: limit_reached.',
				'You decide what to do with this answer.',
			],
			$sentences
		);
	}//end testFiltersAndAStopAreSaid()

	/**
	 * A turn without a record has no account.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-can-read-how-an-answer-came-about-in-plain-language-req-atrn-002
	 */
	public function testNoRecordNoAccount(): void {
		$this->assertSame([], $this->writer()->sentences(record: null));
	}//end testNoRecordNoAccount()
}//end class
