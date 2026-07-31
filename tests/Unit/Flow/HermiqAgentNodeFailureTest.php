<?php
/**
 * An agent step must never report success for a turn that did not produce an answer.
 *
 * These tests exist because the two failure modes they pin are both INVISIBLE at
 * the flow level. A step that swallows its error and a step that hands back prose
 * where JSON was promised both leave a well-formed item on the walk, so the run
 * completes, the trace is clean, and the only symptom is a router quietly taking
 * its default branch. Nothing in a green run says the agent never spoke.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Flow;

use OCA\Hermiq\Flow\HermiqAgentNode;
use OCA\Hermiq\Service\ScheduleService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * @covers \OCA\Hermiq\Flow\HermiqAgentNode
 */
class HermiqAgentNodeFailureTest extends TestCase
{

    private const AGENT_ID = '352f552f-0000-0000-0000-000000000001';


    /**
     * Build the node over a ScheduleService that answers, or throws, as told.
     *
     * @param string|\Throwable $answer What `runAgentAsOwner()` does — a string it
     *                                  returns, or a throwable it raises.
     *
     * @return HermiqAgentNode The node under test.
     */
    private function nodeAnswering($answer): HermiqAgentNode
    {
        $schedule = $this->createMock(ScheduleService::class);
        $invocation = $schedule->method('runAgentAsOwner');
        if ($answer instanceof \Throwable === true) {
            $invocation->willThrowException($answer);
        } else {
            $invocation->willReturn($answer);
        }

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static function (string $text, array $parameters=[]): string {
                return vsprintf(str_replace('%s', '%s', $text), $parameters);
            }
        );

        return new HermiqAgentNode(
            scheduleService: $schedule,
            l10n: $l10n,
            urls: $this->createMock(IURLGenerator::class)
        );

    }//end nodeAnswering()


    /**
     * One item, ready to be walked.
     *
     * @return array<int, array> The item list.
     */
    private function oneItem(): array
    {
        return [['json' => ['issueRef' => 'ConductionNL/hydra#1'], 'binary' => []]];

    }//end oneItem()


    /**
     * A failed turn propagates, so the engine's `onError` policy is what decides.
     *
     * The engine reaches `outcomeForFailedStep()` from the `catch (Throwable)`
     * around its step dispatch. A node that catches internally is therefore not
     * "deferring to onError" — it is bypassing it, and the default policy (`stop`)
     * never applies.
     *
     * @return void
     */
    public function testAFailedTurnFailsTheStep(): void
    {
        $node = $this->nodeAnswering(new RuntimeException('the model call timed out'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the model call timed out');

        $node->execute($this->oneItem(), ['agentId' => self::AGENT_ID], []);

    }//end testAFailedTurnFailsTheStep()


    /**
     * `expectJson` plus a prose answer fails rather than passing the prose along.
     *
     * This is the shape an LLM failure actually takes most often: the turn
     * "succeeds" and the model apologises. Handing that back as the step's output
     * makes every `{{output.field}}` read resolve to empty, which a router cannot
     * distinguish from a real negative verdict.
     *
     * @return void
     */
    public function testProseWhereJsonWasPromisedFailsTheStep(): void
    {
        $node = $this->nodeAnswering("I'm sorry — I could not review that pull request.");

        $this->expectException(UnexpectedValueException::class);

        $node->execute(
            $this->oneItem(),
            ['agentId' => self::AGENT_ID, 'expectJson' => true, 'output' => 'stage'],
            []
        );

    }//end testProseWhereJsonWasPromisedFailsTheStep()


    /**
     * An EMPTY answer under `expectJson` fails too.
     *
     * Pinned separately because the empty string used to be short-circuited past
     * the parse entirely, which is precisely how a swallowed failure reached the
     * item as a well-formed but meaningless output.
     *
     * @return void
     */
    public function testAnEmptyAnswerWhereJsonWasPromisedFailsTheStep(): void
    {
        $node = $this->nodeAnswering('');

        $this->expectException(UnexpectedValueException::class);

        $node->execute(
            $this->oneItem(),
            ['agentId' => self::AGENT_ID, 'expectJson' => true],
            []
        );

    }//end testAnEmptyAnswerWhereJsonWasPromisedFailsTheStep()


    /**
     * The success path is unchanged: fenced JSON is unwrapped and decoded onto the item.
     *
     * The guard tests above are only meaningful next to this one — a node that
     * threw on everything would pass all three and be useless.
     *
     * @return void
     */
    public function testAJsonAnswerStillDecodesOntoTheItem(): void
    {
        $node = $this->nodeAnswering("```json\n{\"verdict\":\"GO\",\"reason\":\"gates green\"}\n```");

        $out = $node->execute(
            $this->oneItem(),
            ['agentId' => self::AGENT_ID, 'expectJson' => true, 'output' => 'stage'],
            []
        );

        $this->assertSame('GO', $out[0]['json']['stage']['verdict']);
        $this->assertSame('ConductionNL/hydra#1', $out[0]['json']['issueRef']);

    }//end testAJsonAnswerStillDecodesOntoTheItem()


    /**
     * Without `expectJson` a prose answer is still the step's output, unchanged.
     *
     * The stricter parse must apply only where the flow author asked for JSON;
     * a step whose answer is meant to be text is not broken by being text.
     *
     * @return void
     */
    public function testProseIsFineWhenNoJsonWasPromised(): void
    {
        $node = $this->nodeAnswering('Looks reasonable to me.');

        $out = $node->execute($this->oneItem(), ['agentId' => self::AGENT_ID], []);

        $this->assertSame('Looks reasonable to me.', $out[0]['json']['result']);

    }//end testProseIsFineWhenNoJsonWasPromised()
}//end class
