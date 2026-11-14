<?php

/**
 * Hermiq AnswerProvenanceWriter.
 *
 * Turns an answer's provenance record into short sentences in the reader's
 * language: which model answered and where it runs, which documents it used,
 * which tools it called and with what result, what the organisation's filters
 * changed, and always, last, that the reader decides what to do with the
 * answer. The sentences are built from the record; no model writes them, since
 * a model's account of its own answer is not a record.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
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
 * @spec openspec/changes/chat-how-an-answer-came-about/tasks.md#task-2-how-this-answer-came-about-on-the-chat-page
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCP\IL10N;

/**
 * Plain-language sentences from a provenance record.
 *
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-can-read-how-an-answer-came-about-in-plain-language-req-atrn-002
 */
class AnswerProvenanceWriter {

	/**
	 * The outcomes of a call that was not carried out because something refused it.
	 *
	 * @var list<string>
	 */
	private const REFUSED = ['denied', 'refused'];

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n The reader's language.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The sentences for one record, ending with the final-say line; none without a record.
	 *
	 * @param mixed $record The stored provenance record.
	 *
	 * @return list<string> The sentences.
	 *
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#scenario-a-legal-aid-worker-checks-what-an-answer-rests-on
	 */
	public function sentences(mixed $record): array {
		if (is_array($record) === false) {
			return [];
		}

		$steps = array_values(array_filter((array)($record['steps'] ?? []), 'is_array'));
		$tools = array_values(array_filter($steps, static fn (array $step): bool => ($step['type'] ?? '') === 'tool'));

		$sentences = [$this->modelSentence(model: ($record['model'] ?? null))];
		$sentences[] = $this->sourcesSentence(sources: array_values(array_filter((array)($record['sources'] ?? []), 'is_array')));
		$sentences = array_merge($sentences, $this->toolSentences(tools: $tools));
		$sentences = array_merge($sentences, $this->filterSentences(steps: $steps));
		$sentences[] = $this->l10n->t('You decide what to do with this answer.');

		return $sentences;
	}//end sentences()

	/**
	 * Which model answered and where it runs.
	 *
	 * @param mixed $model The record's model entry.
	 *
	 * @return string The sentence.
	 */
	private function modelSentence(mixed $model): string {
		if (is_array($model) === false || (string)($model['model'] ?? '') === '') {
			return $this->l10n->t('Which model answered was not recorded.');
		}

		return $this->l10n->t(
			'Answered by %1$s (%2$s), which runs %3$s.',
			[(string)$model['model'], (string)($model['provider'] ?? ''), $this->place(model: $model)]
		);
	}//end modelSentence()

	/**
	 * Where the model runs, with the declared location when there is one.
	 *
	 * @param array $model The record's model entry.
	 *
	 * @return string The phrase.
	 */
	private function place(array $model): string {
		$place = match ((string)($model['residency'] ?? '')) {
			'on-premise' => $this->l10n->t('on this server'),
			'eu' => $this->l10n->t('in the EU'),
			'outside-eu' => $this->l10n->t('outside the EU'),
			default => $this->l10n->t('at a place that has not been declared'),
		};

		$location = trim((string)($model['location'] ?? ''));
		if ($location === '') {
			return $place;
		}

		return $place . ' (' . $location . ')';
	}//end place()

	/**
	 * Which documents the answer used.
	 *
	 * @param list<array> $sources The record's sources.
	 *
	 * @return string The sentence.
	 */
	private function sourcesSentence(array $sources): string {
		if ($sources === []) {
			return $this->l10n->t('No documents were used.');
		}

		$names = array_map(fn (array $source): string => $this->sourceName(source: $source), $sources);

		return $this->l10n->n('Used %n document: %s.', 'Used %n documents: %s.', count($names), [implode(', ', $names)]);
	}//end sourcesSentence()

	/**
	 * A source's name, or its id when it has none.
	 *
	 * @param array $source The source.
	 *
	 * @return string The name.
	 */
	private function sourceName(array $source): string {
		$name = (string)($source['name'] ?? '');
		if ($name === '') {
			return (string)($source['id'] ?? '');
		}

		return $name;
	}//end sourceName()

	/**
	 * Which tools were called with what result, and which calls were refused and why.
	 *
	 * @param list<array> $tools The tool steps.
	 *
	 * @return list<string> The sentences.
	 */
	private function toolSentences(array $tools): array {
		if ($tools === []) {
			return [$this->l10n->t('No tools were called.')];
		}

		$called = [];
		$refused = [];
		foreach ($tools as $tool) {
			$name = (string)($tool['name'] ?? '');
			$outcome = (string)($tool['outcome'] ?? '');
			if (in_array($outcome, self::REFUSED, true) === true) {
				$refused[] = $name . ' (' . $this->refusalReason(outcome: $outcome) . ')';
				continue;
			}

			$called[] = $name . ' (' . $this->outcomeWord(outcome: $outcome) . ')';
		}

		$sentences = [];
		if ($called !== []) {
			$sentences[] = $this->l10n->n('Called %n tool: %s.', 'Called %n tools: %s.', count($called), [implode(', ', $called)]);
		}

		if ($refused !== []) {
			$sentences[] = $this->l10n->n('%n call was refused: %s.', '%n calls were refused: %s.', count($refused), [implode(', ', $refused)]);
		}

		return $sentences;
	}//end toolSentences()

	/**
	 * A tool outcome in words.
	 *
	 * @param string $outcome The outcome.
	 *
	 * @return string The word.
	 */
	private function outcomeWord(string $outcome): string {
		return match ($outcome) {
			'ok' => $this->l10n->t('done'),
			'error' => $this->l10n->t('failed'),
			'awaiting_approval' => $this->l10n->t('waiting for approval'),
			'dry_run' => $this->l10n->t('tried without effect'),
			'stopped' => $this->l10n->t('stopped'),
			default => $outcome,
		};
	}//end outcomeWord()

	/**
	 * Why a call was refused, from its outcome.
	 *
	 * @param string $outcome The outcome.
	 *
	 * @return string The reason.
	 */
	private function refusalReason(string $outcome): string {
		if ($outcome === 'denied') {
			return $this->l10n->t('this agent may not use it');
		}

		return $this->l10n->t('a rule of the organisation does not allow it');
	}//end refusalReason()

	/**
	 * What the organisation's filters changed, and why a run stopped.
	 *
	 * @param list<array> $steps The steps.
	 *
	 * @return list<string> The sentences.
	 */
	private function filterSentences(array $steps): array {
		$sentences = [];
		foreach ($steps as $step) {
			$type = (string)($step['type'] ?? '');
			$name = (string)($step['name'] ?? '');
			$outcome = (string)($step['outcome'] ?? '');
			if ($type === 'guardrail') {
				$sentences[] = $this->filterSentence(name: $name, outcome: $outcome);
			}

			if ($type === 'guard') {
				$sentences[] = $this->l10n->t('The run was stopped: %s.', [$name]);
			}
		}

		return $sentences;
	}//end filterSentences()

	/**
	 * One filter action in words.
	 *
	 * @param string $name    The filter step name, e.g. "Output filter".
	 * @param string $outcome `redacted` or `blocked`.
	 *
	 * @return string The sentence.
	 */
	private function filterSentence(string $name, string $outcome): string {
		$input = ($name === 'Input filter');
		if ($outcome === 'blocked') {
			if ($input === true) {
				return $this->l10n->t('The organisation\'s input filter blocked part of the question.');
			}

			return $this->l10n->t('The organisation\'s output filter blocked part of the answer.');
		}

		if ($input === true) {
			return $this->l10n->t('The organisation\'s input filter masked part of the question.');
		}

		return $this->l10n->t('The organisation\'s output filter masked part of the answer.');
	}//end filterSentence()
}//end class
