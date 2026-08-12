<?php

/**
 * Hermiq EvalScoringService.
 *
 * Scores one EvalCase's actual output (agent-evals): a plain substring check for
 * `contains`/`notContains`, a JSONPath-equals assertion for `jsonPathEquals`, or an
 * LLM-as-judge rubric routed through the existing `ProviderFactory::generateText()`
 * chokepoint for `rubric` — so tenant model policy, budgets, and guardrails already
 * enforced at that chokepoint govern judge calls exactly as they govern any other
 * Hermiq LLM call (design.md "Trade-offs").
 *
 * Never throws: every scoring path returns a result shape
 * `{passed, errorMessage, score, judgeRationale}` even for malformed input or a
 * rejected judge call — a single bad case must never abort the rest of an EvalRun
 * (spec.md "jsonPathEquals case with malformed output fails, not errors").
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-evals/tasks.md#task-5-evalscoringservice-deterministic--llm-judge
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Service\Llm\ModelPolicyViolationException;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use Throwable;

/**
 * Deterministic + LLM-as-judge scoring for one EvalCase.
 *
 * @spec openspec/changes/agent-evals/tasks.md#task-5-evalscoringservice-deterministic--llm-judge
 */
class EvalScoringService {

	/**
	 * Default rubric pass threshold when a rubric case omits its own
	 * `rubricPassThreshold` (matches the EvalDataset schema's default).
	 *
	 * @var float
	 */
	private const DEFAULT_RUBRIC_THRESHOLD = 0.7;

	/**
	 * Constructor.
	 *
	 * @param ProviderFactory $providerFactory Routes the LLM-as-judge call through the
	 *                                         SAME chokepoint every other Hermiq LLM
	 *                                         call uses (tenant-model-policy, budgets,
	 *                                         guardrails).
	 */
	public function __construct(
		private readonly ProviderFactory $providerFactory,
	) {
	}//end __construct()

	/**
	 * Score one EvalCase against its actual output, dispatching on `expectationType`.
	 *
	 * @param array<string,mixed> $case The EvalCase (prompt, expectationType,
	 *                                  and the fields matching that type).
	 * @param string $actualOutput The agent's real output for this case.
	 * @param string|null $organisation The triggering agent's organisation
	 *                                  — threaded to the judge call so it
	 *                                  is model-policy-enforced exactly like
	 *                                  the agent-under-test call (null skips
	 *                                  enforcement, mirrors
	 *                                  ProviderFactory's own opt-in
	 *                                  default).
	 *
	 * @return array{passed:bool,errorMessage:?string,score:?float,judgeRationale:?string}
	 *
	 * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-deterministic-scoring
	 * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-llm-as-judge-scoring-goes-through-the-existing-providerfactory-chokepoint
	 */
	public function score(array $case, string $actualOutput, ?string $organisation = null): array {
		$expectationType = (string)($case['expectationType'] ?? '');

		if ($expectationType === 'contains') {
			return $this->scoreContains(case: $case, actualOutput: $actualOutput, expectPresence: true);
		}

		if ($expectationType === 'notContains') {
			return $this->scoreContains(case: $case, actualOutput: $actualOutput, expectPresence: false);
		}

		if ($expectationType === 'jsonPathEquals') {
			return $this->scoreJsonPathEquals(case: $case, actualOutput: $actualOutput);
		}

		if ($expectationType === 'rubric') {
			return $this->scoreRubric(case: $case, actualOutput: $actualOutput, organisation: $organisation);
		}

		return $this->result(
			passed: false,
			errorMessage: sprintf("Unknown expectationType '%s'.", $expectationType)
		);

	}//end score()

	/**
	 * Score a `contains`/`notContains` case: a plain (case-sensitive) substring check.
	 *
	 * @param array<string,mixed> $case The EvalCase (must carry `expectedSubstring`).
	 * @param string $actualOutput The agent's real output.
	 * @param bool $expectPresence True for `contains`, false for `notContains`.
	 *
	 * @return array{passed:bool,errorMessage:?string,score:?float,judgeRationale:?string}
	 *
	 * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-deterministic-scoring
	 */
	private function scoreContains(array $case, string $actualOutput, bool $expectPresence): array {
		$expectedSubstring = (string)($case['expectedSubstring'] ?? '');
		if ($expectedSubstring === '') {
			return $this->result(passed: false, errorMessage: 'Case is missing expectedSubstring.');
		}

		$present = str_contains($actualOutput, $expectedSubstring);
		$passed = ($present === $expectPresence);

		return $this->result(passed: $passed);
	}//end scoreContains()

	/**
	 * Score a `jsonPathEquals` case: parse the actual output as JSON and compare the
	 * value at `jsonPath` (as a string) to `expectedValue`. Malformed JSON or an
	 * unresolvable path never throws — the case simply fails with an `errorMessage`
	 * (spec.md "jsonPathEquals case with malformed output fails, not errors").
	 *
	 * @param array<string,mixed> $case The EvalCase (must carry `jsonPath` and `expectedValue`).
	 * @param string $actualOutput The agent's real output.
	 *
	 * @return array{passed:bool,errorMessage:?string,score:?float,judgeRationale:?string}
	 *
	 * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-deterministic-scoring
	 */
	private function scoreJsonPathEquals(array $case, string $actualOutput): array {
		$jsonPath = (string)($case['jsonPath'] ?? '');
		$expectedValue = (string)($case['expectedValue'] ?? '');

		if ($jsonPath === '') {
			return $this->result(passed: false, errorMessage: 'Case is missing jsonPath.');
		}

		$decoded = json_decode($actualOutput, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
			return $this->result(passed: false, errorMessage: 'Actual output is not valid JSON.');
		}

		[$found, $value] = $this->resolveJsonPath(data: $decoded, path: $jsonPath);
		if ($found === false) {
			return $this->result(
				passed: false,
				errorMessage: sprintf("JSON path '%s' did not resolve in the actual output.", $jsonPath)
			);
		}

		$actualValue = json_encode($value);
		if (is_scalar($value) === true) {
			$actualValue = (string)$value;
		}

		return $this->result(passed: ($actualValue === $expectedValue));
	}//end scoreJsonPathEquals()

	/**
	 * Resolve a simple dot/bracket JSON path (e.g. `result.status` or
	 * `items[0].name`) against a decoded array. Not a full JSONPath implementation
	 * (no wildcards/filters/recursive descent) — the brief scopes this to a
	 * dependency-free assertion, not a JSONPath library (proposal.md "New
	 * Dependencies: None").
	 *
	 * @param array<mixed> $data The decoded JSON data.
	 * @param string $path The dot/bracket path (a leading `$.` is stripped).
	 *
	 * @return array{0:bool,1:mixed} `[found, value]` — `found` is false when any
	 *                               segment does not resolve.
	 */
	private function resolveJsonPath(array $data, string $path): array {
		$path = ltrim($path, '$');
		$path = ltrim($path, '.');
		if ($path === '') {
			return [false, null];
		}

		$segments = [];
		foreach (explode('.', $path) as $part) {
			while (preg_match('/^([^\[\]]*)\[(\d+)\](.*)$/', $part, $matches) === 1) {
				if ($matches[1] !== '') {
					$segments[] = $matches[1];
				}

				$segments[] = (int)$matches[2];
				$part = ltrim($matches[3], '.');
			}

			if ($part !== '') {
				$segments[] = $part;
			}
		}//end foreach

		$cursor = $data;
		foreach ($segments as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return [false, null];
			}

			$cursor = $cursor[$segment];
		}

		return [true, $cursor];
	}//end resolveJsonPath()

	/**
	 * Score a `rubric` case via one LLM-as-judge call through
	 * `ProviderFactory::generateText()` — the SAME governed chokepoint the
	 * agent-under-test call goes through, so tenant model policy, budgets, and the
	 * kill-switch apply to the judge call exactly as they apply to any other Hermiq
	 * LLM call. A `ModelPolicyViolationException`/`ProviderUnavailableException`
	 * (or any other judge-call failure) is recorded as a failed case with an
	 * `errorMessage`, never a fatal EvalRun failure.
	 *
	 * @param array<string,mixed> $case The EvalCase (must carry `rubric`; MAY
	 *                                  carry `rubricPassThreshold`).
	 * @param string $actualOutput The agent's real output.
	 * @param string|null $organisation The triggering agent's organisation,
	 *                                  threaded to the judge call.
	 *
	 * @return array{passed:bool,errorMessage:?string,score:?float,judgeRationale:?string}
	 *
	 * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-llm-as-judge-scoring-goes-through-the-existing-providerfactory-chokepoint
	 */
	private function scoreRubric(array $case, string $actualOutput, ?string $organisation): array {
		$rubric = (string)($case['rubric'] ?? '');
		if ($rubric === '') {
			return $this->result(passed: false, errorMessage: 'Case is missing rubric.');
		}

		$threshold = self::DEFAULT_RUBRIC_THRESHOLD;
		if (isset($case['rubricPassThreshold']) === true && is_numeric($case['rubricPassThreshold']) === true) {
			$threshold = (float)$case['rubricPassThreshold'];
		}

		$prompt = $this->buildJudgePrompt(rubric: $rubric, prompt: (string)($case['prompt'] ?? ''), actualOutput: $actualOutput);

		try {
			$judgeResponse = $this->providerFactory->generateText(
				prompt: $prompt,
				userId: null,
				allowNextcloud: true,
				organisation: $organisation
			);
		} catch (ModelPolicyViolationException|ProviderUnavailableException $e) {
			return $this->result(passed: false, errorMessage: $e->getMessage());
		} catch (Throwable $e) {
			return $this->result(passed: false, errorMessage: 'Judge call failed: ' . $e->getMessage());
		}

		[$score, $rationale] = $this->parseJudgeResponse(response: $judgeResponse);
		if ($score === null) {
			return $this->result(
				passed: false,
				errorMessage: 'Could not parse a numeric score from the judge response.',
				judgeRationale: $rationale
			);
		}

		return $this->result(passed: ($score >= $threshold), score: $score, judgeRationale: $rationale);
	}//end scoreRubric()

	/**
	 * Build the judge prompt: the rubric, the case's original input, and the
	 * agent's actual output, asking for a strict JSON verdict. The actual output is
	 * user/agent-controlled content flowing into a second LLM call — standard
	 * prompt-injection caution applies exactly as it already does for every other
	 * Hermiq LLM call (design.md "Security Considerations"); the judge call is
	 * never given tool access, so a prompt-injected judge call cannot invoke tools.
	 *
	 * @param string $rubric The case's grading rubric.
	 * @param string $prompt The case's original input prompt.
	 * @param string $actualOutput The agent's real output being graded.
	 *
	 * @return string The judge prompt.
	 */
	private function buildJudgePrompt(string $rubric, string $prompt, string $actualOutput): string {
		return 'You are an evaluation judge. Score the AGENT OUTPUT below against the RUBRIC '
			. "on a scale from 0.0 (fails completely) to 1.0 (fully satisfies the rubric).\n\n"
			. "RUBRIC:\n" . $rubric . "\n\n"
			. "ORIGINAL INPUT:\n" . $prompt . "\n\n"
			. "AGENT OUTPUT:\n" . $actualOutput . "\n\n"
			. 'Respond with ONLY a JSON object of the exact shape {"score": <number between 0 and 1>, "rationale": "<one short sentence>"}. '
			. 'Do not include any other text.';

	}//end buildJudgePrompt()

	/**
	 * Parse the judge's raw text response for a `{"score": ..., "rationale": ...}`
	 * JSON object. Tolerant of surrounding prose (extracts the first `{`..last `}`
	 * substring before decoding) since not every provider reliably emits ONLY JSON.
	 *
	 * @param string $response The judge's raw response text.
	 *
	 * @return array{0:?float,1:?string} `[score, rationale]` — `score` is null when
	 *                                   no numeric score could be parsed.
	 */
	private function parseJudgeResponse(string $response): array {
		$start = strpos($response, '{');
		$end = strrpos($response, '}');
		if ($start === false || $end === false || $end < $start) {
			return [null, null];
		}

		$candidate = substr($response, $start, ($end - $start + 1));
		$decoded = json_decode($candidate, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
			return [null, null];
		}

		$score = ($decoded['score'] ?? null);
		if (is_numeric($score) === false) {
			return [null, null];
		}

		$rationale = ($decoded['rationale'] ?? null);
		if (is_string($rationale) === false) {
			$rationale = null;
		}

		return [max(0.0, min(1.0, (float)$score)), $rationale];
	}//end parseJudgeResponse()

	/**
	 * Build the standard scoring result shape.
	 *
	 * @param bool $passed Whether the case passed.
	 * @param string|null $errorMessage Why scoring could not complete cleanly, if at all.
	 * @param float|null $score The judge's numeric score (rubric only).
	 * @param string|null $judgeRationale The judge's rationale (rubric only).
	 *
	 * @return array{passed:bool,errorMessage:?string,score:?float,judgeRationale:?string}
	 */
	private function result(bool $passed, ?string $errorMessage = null, ?float $score = null, ?string $judgeRationale = null): array {
		return [
			'passed' => $passed,
			'errorMessage' => $errorMessage,
			'score' => $score,
			'judgeRationale' => $judgeRationale,
		];

	}//end result()
}//end class
