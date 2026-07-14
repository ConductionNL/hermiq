<?php

/**
 * Hermiq EvalScoringService unit tests.
 *
 * Covers the four expectation types (contains/notContains/jsonPathEquals/rubric),
 * the never-throws contract on malformed input, and that the LLM-as-judge path goes
 * through ProviderFactory::generateText() with the organisation threaded (agent-evals).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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
 * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\EvalScoringService;
use OCA\Hermiq\Service\Llm\ModelPolicyViolationException;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use PHPUnit\Framework\TestCase;

/**
 * EvalScoringService unit tests (agent-evals).
 *
 * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md
 */
class EvalScoringServiceTest extends TestCase
{

    /**
     * A scoring service over a ProviderFactory whose generateText() returns $judge.
     *
     * @param string|null $judge     The canned judge response, or null to expect no judge call.
     * @param \Throwable  $throwable Optional throwable the judge call raises instead.
     *
     * @return EvalScoringService
     */
    private function service(?string $judge=null, ?\Throwable $throwable=null): EvalScoringService
    {
        $factory = $this->createMock(ProviderFactory::class);
        if ($throwable !== null) {
            $factory->method('generateText')->willThrowException($throwable);
        } else if ($judge !== null) {
            $factory->method('generateText')->willReturn($judge);
        } else {
            $factory->expects($this->never())->method('generateText');
        }

        return new EvalScoringService(providerFactory: $factory);

    }//end service()

    /**
     * `contains` passes when the substring is present, fails when absent.
     *
     * @return void
     */
    public function testContainsSubstring(): void
    {
        $service = $this->service();
        $case    = ['expectationType' => 'contains', 'expectedSubstring' => 'hello'];

        $this->assertTrue($service->score($case, 'well hello there')['passed']);
        $this->assertFalse($service->score($case, 'goodbye')['passed']);

    }//end testContainsSubstring()

    /**
     * `notContains` is the inverse of `contains`.
     *
     * @return void
     */
    public function testNotContainsSubstring(): void
    {
        $service = $this->service();
        $case    = ['expectationType' => 'notContains', 'expectedSubstring' => 'error'];

        $this->assertTrue($service->score($case, 'all good')['passed']);
        $this->assertFalse($service->score($case, 'fatal error occurred')['passed']);

    }//end testNotContainsSubstring()

    /**
     * A missing expectedSubstring fails cleanly, never throws.
     *
     * @return void
     */
    public function testContainsMissingSubstringFailsCleanly(): void
    {
        $result = $this->service()->score(['expectationType' => 'contains'], 'anything');

        $this->assertFalse($result['passed']);
        $this->assertNotNull($result['errorMessage']);

    }//end testContainsMissingSubstringFailsCleanly()

    /**
     * `jsonPathEquals` resolves dotted + bracketed paths and compares as strings.
     *
     * @return void
     */
    public function testJsonPathEquals(): void
    {
        $service = $this->service();
        $output  = '{"result": {"status": "ok"}, "items": [{"name": "first"}]}';

        $this->assertTrue($service->score(['expectationType' => 'jsonPathEquals', 'jsonPath' => 'result.status', 'expectedValue' => 'ok'], $output)['passed']);
        $this->assertTrue($service->score(['expectationType' => 'jsonPathEquals', 'jsonPath' => 'items[0].name', 'expectedValue' => 'first'], $output)['passed']);
        $this->assertFalse($service->score(['expectationType' => 'jsonPathEquals', 'jsonPath' => 'result.status', 'expectedValue' => 'fail'], $output)['passed']);

    }//end testJsonPathEquals()

    /**
     * A jsonPathEquals case over non-JSON output FAILS (not errors) — the run continues.
     *
     * @return void
     */
    public function testJsonPathEqualsMalformedOutputFailsNotThrows(): void
    {
        $result = $this->service()->score(
            ['expectationType' => 'jsonPathEquals', 'jsonPath' => 'a.b', 'expectedValue' => 'x'],
            'this is not json'
        );

        $this->assertFalse($result['passed']);
        $this->assertNotNull($result['errorMessage']);

    }//end testJsonPathEqualsMalformedOutputFailsNotThrows()

    /**
     * An unresolvable JSON path fails cleanly.
     *
     * @return void
     */
    public function testJsonPathEqualsUnresolvedPathFails(): void
    {
        $result = $this->service()->score(
            ['expectationType' => 'jsonPathEquals', 'jsonPath' => 'missing.key', 'expectedValue' => 'x'],
            '{"present": 1}'
        );

        $this->assertFalse($result['passed']);
        $this->assertNotNull($result['errorMessage']);

    }//end testJsonPathEqualsUnresolvedPathFails()

    /**
     * A rubric case scores via the judge; a score at/above threshold passes.
     *
     * @return void
     */
    public function testRubricPassesWhenJudgeScoreMeetsThreshold(): void
    {
        $service = $this->service(judge: '{"score": 0.9, "rationale": "great"}');
        $result  = $service->score(
            ['expectationType' => 'rubric', 'rubric' => 'Is it polite?', 'rubricPassThreshold' => 0.7, 'prompt' => 'hi'],
            'Hello, how may I help?'
        );

        $this->assertTrue($result['passed']);
        $this->assertSame(0.9, $result['score']);
        $this->assertSame('great', $result['judgeRationale']);

    }//end testRubricPassesWhenJudgeScoreMeetsThreshold()

    /**
     * A judge score below threshold fails.
     *
     * @return void
     */
    public function testRubricFailsWhenJudgeScoreBelowThreshold(): void
    {
        $service = $this->service(judge: 'The verdict is {"score": 0.3, "rationale": "rude"} overall');
        $result  = $service->score(
            ['expectationType' => 'rubric', 'rubric' => 'Is it polite?', 'rubricPassThreshold' => 0.7],
            'go away'
        );

        $this->assertFalse($result['passed']);
        $this->assertSame(0.3, $result['score']);

    }//end testRubricFailsWhenJudgeScoreBelowThreshold()

    /**
     * A model-policy violation on the judge call is a failed case, never a thrown run.
     *
     * @return void
     */
    public function testRubricJudgePolicyViolationFailsNotThrows(): void
    {
        $service = $this->service(throwable: new ModelPolicyViolationException('blocked', 422));
        $result  = $service->score(['expectationType' => 'rubric', 'rubric' => 'r'], 'out');

        $this->assertFalse($result['passed']);
        $this->assertNotNull($result['errorMessage']);

    }//end testRubricJudgePolicyViolationFailsNotThrows()

    /**
     * An unparseable judge response fails cleanly (no numeric score).
     *
     * @return void
     */
    public function testRubricUnparseableJudgeResponseFails(): void
    {
        $service = $this->service(judge: 'I cannot score this.');
        $result  = $service->score(['expectationType' => 'rubric', 'rubric' => 'r'], 'out');

        $this->assertFalse($result['passed']);
        $this->assertNotNull($result['errorMessage']);

    }//end testRubricUnparseableJudgeResponseFails()

    /**
     * An unknown expectationType fails cleanly rather than throwing.
     *
     * @return void
     */
    public function testUnknownExpectationTypeFailsCleanly(): void
    {
        $result = $this->service()->score(['expectationType' => 'wat'], 'out');

        $this->assertFalse($result['passed']);
        $this->assertNotNull($result['errorMessage']);

    }//end testUnknownExpectationTypeFailsCleanly()
}//end class
