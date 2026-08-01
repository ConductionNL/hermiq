<?php

/**
 * The workload node's contract with a flow.
 *
 * One distinction carries this node, and most of this file is about it:
 *
 *   a command that RAN and exited non-zero  ->  DATA on the item
 *   a workload that COULD NOT BE RUN        ->  a thrown step failure
 *
 * hydra's gate runner uses its exit code as a failure COUNT, so a router
 * downstream is meant to read it — throwing would make "if the gates failed,
 * comment and retry" inexpressible. Conversely a dispatch that never happened
 * must not arrive as `exitCode: 0`, because a downstream router cannot tell
 * "the gates found nothing" from "the gates never ran", and both would look
 * like a clean tick. This codebase has now met that defect in four shapes;
 * these tests are what stop the fifth.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Flow;

use OCA\Hermiq\Flow\HermiqWorkloadNode;
use OCA\Hermiq\Service\StageDispatchService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * @covers \OCA\Hermiq\Flow\HermiqWorkloadNode
 */
class HermiqWorkloadNodeTest extends TestCase
{

    /**
     * The arguments the last dispatch was called with.
     *
     * @var array
     */
    private array $lastCall = [];

    /**
     * Build a node whose dispatcher returns a fixed result.
     *
     * @param array $result What the dispatcher returns.
     *
     * @return HermiqWorkloadNode The node under test.
     */
    private function nodeReturning(array $result): HermiqWorkloadNode
    {
        $stages = $this->createMock(StageDispatchService::class);
        $stages->method('dispatch')->willReturnCallback(
            function (...$arguments) use ($result): array {
                $this->lastCall = $arguments;

                return $result;
            }
        );

        return $this->node(stages: $stages);

    }//end nodeReturning()

    /**
     * Build a node whose dispatcher throws.
     *
     * @param \Throwable $error What the dispatcher throws.
     *
     * @return HermiqWorkloadNode The node under test.
     */
    private function nodeFailingWith(\Throwable $error): HermiqWorkloadNode
    {
        $stages = $this->createMock(StageDispatchService::class);
        $stages->method('dispatch')->willThrowException($error);

        return $this->node(stages: $stages);

    }//end nodeFailingWith()

    /**
     * Assemble the node with stub collaborators.
     *
     * @param StageDispatchService $stages The dispatcher.
     *
     * @return HermiqWorkloadNode The node.
     */
    private function node(StageDispatchService $stages): HermiqWorkloadNode
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static fn (string $text, array $parameters=[]): string => vsprintf($text, $parameters)
        );

        return new HermiqWorkloadNode($stages, $l10n, $this->createMock(IURLGenerator::class));

    }//end node()

    /**
     * A workable configuration.
     *
     * @return array The config.
     */
    private function config(): array
    {
        return [
            'repo'    => 'https://github.com/ConductionNL/hydra',
            'ref'     => 'development',
            'command' => ['scripts/run-hydra-gates.sh', '--scope-to-diff'],
        ];
    }//end config()

    /**
     * A non-zero exit code is the RESULT, not a step failure.
     *
     * @return void
     */
    public function testNonZeroExitCodeIsDataNotAFailure(): void
    {
        $node = $this->nodeReturning(['exitCode' => 18, 'output' => '18 gate(s) failed', 'ref' => 'abc123']);

        $out = $node->execute([['json' => []]], $this->config(), []);

        // 18, not an exception and not a boolean: hydra reads this number.
        $this->assertSame(18, $out[0]['json']['stage']['exitCode']);
        $this->assertSame('18 gate(s) failed', $out[0]['json']['stage']['output']);

    }//end testNonZeroExitCodeIsDataNotAFailure()

    /**
     * A dispatch that could not run PROPAGATES, so `onError` decides.
     *
     * The engine reaches its per-step `onError` policy from the `catch` around
     * the step dispatch, so a failure swallowed here would never reach it —
     * the step would report success with the output key simply absent.
     *
     * @return void
     */
    public function testADispatchFailurePropagates(): void
    {
        $node = $this->nodeFailingWith(new RuntimeException('the ExApp is not running'));

        $this->expectException(RuntimeException::class);
        $node->execute([['json' => []]], $this->config(), []);

    }//end testADispatchFailurePropagates()

    /**
     * An unconfigured step FAILS rather than passing items through.
     *
     * `validateConfig()` runs only when a flow is saved; a seeded or imported
     * flow reaches `execute()` unvalidated.
     *
     * @param array $config The broken configuration.
     *
     * @return void
     *
     * @dataProvider brokenConfigs
     */
    public function testAnUnconfiguredStepFailsInExecuteToo(array $config): void
    {
        $node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

        $this->expectException(UnexpectedValueException::class);
        $node->execute([['json' => []]], $config, []);

    }//end testAnUnconfiguredStepFailsInExecuteToo()

    /**
     * The same configurations are refused when the flow is SAVED.
     *
     * @param array $config The broken configuration.
     *
     * @return void
     *
     * @dataProvider brokenConfigs
     */
    public function testValidateConfigRefusesTheSameThings(array $config): void
    {
        $node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

        $this->expectException(UnexpectedValueException::class);
        $node->validateConfig($config);

    }//end testValidateConfigRefusesTheSameThings()

    /**
     * Configurations a workload cannot run with.
     *
     * @return array The cases.
     */
    public static function brokenConfigs(): array
    {
        return [
            'no repo'         => [['ref' => 'main', 'command' => ['x']]],
            'no ref'          => [['repo' => 'https://example.test/r', 'command' => ['x']]],
            'no command'      => [['repo' => 'https://example.test/r', 'ref' => 'main']],
            'empty command'   => [['repo' => 'https://example.test/r', 'ref' => 'main', 'command' => []]],
            'command string'  => [['repo' => 'https://example.test/r', 'ref' => 'main', 'command' => 'x']],
            'blank first arg' => [['repo' => 'https://example.test/r', 'ref' => 'main', 'command' => ['  ']]],
        ];

    }//end brokenConfigs()

    /**
     * `{{dotted.path}}` is substituted into the repo, the ref AND the argv.
     *
     * The argv matters most: it is how a flow passes the base to
     * `--scope-to-diff`, and rendering only the repo and ref would leave that
     * as a literal placeholder the gate runner would try to diff against.
     *
     * @return void
     */
    public function testPlaceholdersAreRenderedInRepoRefAndArguments(): void
    {
        $node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

        $node->execute(
            [
                [
                    'json' => [
                        'issue' => ['repo' => 'https://github.com/ConductionNL/hydra', 'branch' => 'feat/x'],
                        'base'  => 'origin/development',
                    ],
                ],
            ],
            [
                'repo'    => '{{issue.repo}}',
                'ref'     => '{{issue.branch}}',
                'command' => ['scripts/run-hydra-gates.sh', '--base', '{{base}}'],
            ],
            []
        );

        $this->assertSame('https://github.com/ConductionNL/hydra', $this->lastCall[0]);
        $this->assertSame('feat/x', $this->lastCall[1]);
        $this->assertSame(['scripts/run-hydra-gates.sh', '--base', 'origin/development'], $this->lastCall[2]);

    }//end testPlaceholdersAreRenderedInRepoRefAndArguments()

    /**
     * The result lands under `stage` by default, or the configured key.
     *
     * @return void
     */
    public function testTheOutputKeyIsConfigurable(): void
    {
        $node = $this->nodeReturning(['exitCode' => 0, 'output' => 'ok', 'ref' => 'r']);

        $out = $node->execute([['json' => []]], ($this->config() + ['output' => 'gates']), []);

        $this->assertArrayHasKey('gates', $out[0]['json']);
        $this->assertArrayNotHasKey('stage', $out[0]['json']);

    }//end testTheOutputKeyIsConfigurable()

    /**
     * Every item is run and paired back to its input.
     *
     * A fanned-out collection is the normal case — one item per repository —
     * so a node that ran only the first would silently gate one of them.
     *
     * @return void
     */
    public function testEveryItemIsRunAndPaired(): void
    {
        $node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

        $out = $node->execute([['json' => ['n' => 1]], ['json' => ['n' => 2]]], $this->config(), []);

        $this->assertCount(2, $out);
        $this->assertSame(0, $out[0]['pairedItem']['item']);
        $this->assertSame(1, $out[1]['pairedItem']['item']);
        $this->assertSame(2, $out[1]['json']['n']);

    }//end testEveryItemIsRunAndPaired()

    /**
     * The node identifies itself as the workload step.
     *
     * @return void
     */
    public function testItRegistersAsTheWorkloadStep(): void
    {
        $node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

        $this->assertSame('hermiq.workload-step', $node->getId());

    }//end testItRegistersAsTheWorkloadStep()
}//end class
