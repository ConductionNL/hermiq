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
class HermiqWorkloadNodeTest extends TestCase {

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
	private function nodeReturning(array $result): HermiqWorkloadNode {
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
	private function nodeFailingWith(\Throwable $error): HermiqWorkloadNode {
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
	private function node(StageDispatchService $stages): HermiqWorkloadNode {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new HermiqWorkloadNode($stages, $l10n, $this->createMock(IURLGenerator::class));
	}//end node()

	/**
	 * A workable configuration.
	 *
	 * @return array The config.
	 */
	private function config(): array {
		return [
			'repo' => 'https://github.com/ConductionNL/hydra',
			'ref' => 'development',
			'command' => ['scripts/run-hydra-gates.sh', '--scope-to-diff'],
		];
	}//end config()

	/**
	 * A non-zero exit code is the RESULT, not a step failure.
	 *
	 * @return void
	 */
	public function testNonZeroExitCodeIsDataNotAFailure(): void {
		$node = $this->nodeReturning(['exitCode' => 18, 'output' => '18 gate(s) failed', 'ref' => 'abc123']);

		$out = $node->execute([['json' => []]], ($this->config() + ['owner' => 'ruben']), []);

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
	public function testADispatchFailurePropagates(): void {
		$node = $this->nodeFailingWith(new RuntimeException('the ExApp is not running'));

		// The owner is REQUIRED here, and not incidentally: `UnexpectedValueException`
		// extends `RuntimeException` in PHP, so an unattributed config would make
		// this test pass on the attribution refusal WITHOUT ever reaching the
		// dispatcher — green for the opposite of the reason it claims.
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('the ExApp is not running');
		$node->execute([['json' => []]], ($this->config() + ['owner' => 'ruben']), []);

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
	public function testAnUnconfiguredStepFailsInExecuteToo(array $config): void {
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
	public function testValidateConfigRefusesTheSameThings(array $config): void {
		$node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

		$this->expectException(UnexpectedValueException::class);
		$node->validateConfig($config);

	}//end testValidateConfigRefusesTheSameThings()

	/**
	 * Configurations a workload cannot run with.
	 *
	 * @return array The cases.
	 */
	public static function brokenConfigs(): array {
		return [
			'no repo' => [['ref' => 'main', 'command' => ['x']]],
			'no ref' => [['repo' => 'https://example.test/r', 'command' => ['x']]],
			'no command' => [['repo' => 'https://example.test/r', 'ref' => 'main']],
			'empty command' => [['repo' => 'https://example.test/r', 'ref' => 'main', 'command' => []]],
			'command string' => [['repo' => 'https://example.test/r', 'ref' => 'main', 'command' => 'x']],
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
	public function testPlaceholdersAreRenderedInRepoRefAndArguments(): void {
		$node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

		$node->execute(
			[
				[
					'json' => [
						'issue' => ['repo' => 'https://github.com/ConductionNL/hydra', 'branch' => 'feat/x'],
						'base' => 'origin/development',
					],
				],
			],
			[
				'repo' => '{{issue.repo}}',
				'ref' => '{{issue.branch}}',
				'command' => ['scripts/run-hydra-gates.sh', '--base', '{{base}}'],
				'owner' => 'ruben',
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
	public function testTheOutputKeyIsConfigurable(): void {
		$node = $this->nodeReturning(['exitCode' => 0, 'output' => 'ok', 'ref' => 'r']);

		$out = $node->execute([['json' => []]], ($this->config() + ['output' => 'gates', 'owner' => 'ruben']), []);

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
	public function testEveryItemIsRunAndPaired(): void {
		$node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

		$out = $node->execute([['json' => ['n' => 1]], ['json' => ['n' => 2]]], ($this->config() + ['owner' => 'ruben']), []);

		$this->assertCount(2, $out);
		$this->assertSame(0, $out[0]['pairedItem']['item']);
		$this->assertSame(1, $out[1]['pairedItem']['item']);
		$this->assertSame(2, $out[1]['json']['n']);

	}//end testEveryItemIsRunAndPaired()

	/**
	 * A stage that cannot be attributed is REFUSED, before anything is dispatched.
	 *
	 * hydra's record answers "who ran this, on whose credential" out of
	 * `cycles[].owner` and `stages[].credential_owner`. A stage that cannot say
	 * costs the record that answer permanently — the run is durable, the
	 * missing attribution is not recoverable afterwards. It is also the shape a
	 * credential misuse takes: a subscription serves its owner and never a
	 * pool, so "no owner" is exactly the state in which none may be selected.
	 *
	 * @return void
	 */
	public function testAnUnattributableStageIsRefused(): void {
		$node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

		$this->expectException(UnexpectedValueException::class);
		// No `owner` in the config AND no `triggeredBy` in the run context.
		$node->execute([['json' => []]], $this->config(), []);

	}//end testAnUnattributableStageIsRefused()

	/**
	 * A blank owner is not an owner.
	 *
	 * @return void
	 */
	public function testAWhitespaceOwnerIsRefused(): void {
		$node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

		$this->expectException(UnexpectedValueException::class);
		$node->execute([['json' => []]], ($this->config() + ['owner' => '   ']), ['triggeredBy' => '']);

	}//end testAWhitespaceOwnerIsRefused()

	/**
	 * The run's owner attributes the stage when the step names none.
	 *
	 * @return void
	 */
	public function testTheRunOwnerAttributesTheStage(): void {
		$node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

		$out = $node->execute([['json' => []]], $this->config(), ['triggeredBy' => 'ruben']);

		$this->assertSame('ruben', $out[0]['json']['stage']['owner']);

	}//end testTheRunOwnerAttributesTheStage()

	/**
	 * Attribution rides ON the result, so a fan-out cannot lose it.
	 *
	 * An owner a composer has to correlate from run metadata is an owner that
	 * goes missing the first time a flow fans out over several repositories.
	 *
	 * @return void
	 */
	public function testAttributionTravelsWithEachItemsResult(): void {
		$node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

		$out = $node->execute(
			[['json' => ['n' => 1]], ['json' => ['n' => 2]]],
			($this->config() + ['owner' => 'ruben', 'credentialId' => 'cred-uuid-1']),
			[]
		);

		foreach ($out as $item) {
			$this->assertSame('ruben', $item['json']['stage']['owner']);
			$this->assertSame('ruben', $item['json']['stage']['credential_owner']);
			$this->assertSame('cred-uuid-1', $item['json']['stage']['credential_name']);
		}

	}//end testAttributionTravelsWithEachItemsResult()

	/**
	 * With no credential, the credential fields are NULL rather than the owner.
	 *
	 * A public clone uses no credential at all, and recording one anyway would
	 * put a fact in the durable record that never happened.
	 *
	 * @return void
	 */
	public function testNoCredentialMeansNoCredentialAttribution(): void {
		$node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

		$out = $node->execute([['json' => []]], ($this->config() + ['owner' => 'ruben']), []);

		$this->assertSame('ruben', $out[0]['json']['stage']['owner']);
		$this->assertNull($out[0]['json']['stage']['credential_owner']);
		$this->assertNull($out[0]['json']['stage']['credential_name']);

	}//end testNoCredentialMeansNoCredentialAttribution()

	/**
	 * The tool tree is forwarded, with its placeholders rendered.
	 *
	 * hydra's gate runner takes the tree it gates as an argument and resolves
	 * its own helpers out of its OWN checkout, so gating an app needs hydra's
	 * scripts and the app's tree at once. Without this the only workable
	 * arrangement is every app vendoring 3,599 lines of gate runner.
	 *
	 * @return void
	 */
	public function testTheToolTreeIsForwarded(): void {
		$node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

		$node->execute(
			[['json' => ['tool' => 'https://github.com/ConductionNL/hydra']]],
			($this->config() + ['owner' => 'ruben', 'toolRepo' => '{{tool}}', 'toolRef' => 'development']),
			[]
		);

		// Positional order of dispatch(): repo, ref, command, uid, credentialId,
		// timeoutMs, toolRepo, toolRef.
		$this->assertSame('https://github.com/ConductionNL/hydra', $this->lastCall[6]);
		$this->assertSame('development', $this->lastCall[7]);

	}//end testTheToolTreeIsForwarded()

	/**
	 * With no tool tree configured, empty strings are forwarded, not nulls.
	 *
	 * The dispatcher treats '' as "the command lives in the target", which is
	 * the ordinary case and must stay the default.
	 *
	 * @return void
	 */
	public function testNoToolTreeMeansTheCommandComesFromTheTarget(): void {
		$node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

		$node->execute([['json' => []]], ($this->config() + ['owner' => 'ruben']), []);

		$this->assertSame('', $this->lastCall[6]);
		$this->assertSame('', $this->lastCall[7]);

	}//end testNoToolTreeMeansTheCommandComesFromTheTarget()

	/**
	 * The credential id is RENDERED like every other configured value.
	 *
	 * It was the one field left un-rendered, and the failure was invisible: the
	 * literal `{{forgeCredential}}` went to the broker as a credential id, the
	 * broker could not find it, and reported `credential not found` — which
	 * reads as a missing credential rather than an unrendered placeholder.
	 *
	 * @return void
	 */
	public function testTheCredentialIdIsRendered(): void {
		$node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

		$node->execute(
			[['json' => ['forgeCredential' => '35327e7a-cafe-4a21-8ffe-6195d52f9579']]],
			($this->config() + ['owner' => 'ruben', 'credentialId' => '{{forgeCredential}}']),
			[]
		);

		// Positional: repo, ref, command, uid, credentialId, ...
		$this->assertSame('35327e7a-cafe-4a21-8ffe-6195d52f9579', $this->lastCall[4]);
		$this->assertStringNotContainsString('{{', (string)$this->lastCall[4]);

	}//end testTheCredentialIdIsRendered()

	/**
	 * A stage with no `push` key forwards NO push declaration.
	 *
	 * The default matters more than the feature: the runner reads the presence
	 * of `push` as "this stage may write", so a node that forwarded `['branch'
	 * => '', ...]` for every read-only stage would turn the whole existing
	 * pipeline into writing stages, and every one of them would then be refused
	 * by `pushGuard` for having no issue. Read-only has to stay the default all
	 * the way down to the wire.
	 *
	 * @return void
	 */
	public function testAStageWithoutAPushDeclarationStaysReadOnly(): void {
		$node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

		$node->execute([['json' => []]], ($this->config() + ['owner' => 'ruben']), []);

		// Positional: repo, ref, command, uid, credentialId, timeoutMs, toolRepo, toolRef, push, pushCredentialId.
		$this->assertSame([], $this->lastCall[8]);
		$this->assertSame('', $this->lastCall[9]);

	}//end testAStageWithoutAPushDeclarationStaysReadOnly()

	/**
	 * The push declaration is forwarded with every placeholder rendered.
	 *
	 * `branch` and `issue` together ARE the allowlist `pushGuard` enforces, and
	 * both are per-item: a fan-out over issues writes a different branch each
	 * time. An unrendered `feature/{{issueNumber}}/x` would be refused by the
	 * runner as "outside the allowlist" — a refusal that reads like a scope
	 * violation rather than a templating bug, which is exactly how the
	 * un-rendered `credentialId` hid for a release.
	 *
	 * @return void
	 */
	public function testThePushDeclarationIsForwardedAndRendered(): void {
		$node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

		$node->execute(
			[['json' => ['issueNumber' => '493', 'slug' => 'builder-write-access']]],
			(
				$this->config() + [
					'owner' => 'ruben',
					'push' => [
						'branch' => 'feature/{{issueNumber}}/{{slug}}',
						'issue' => '{{issueNumber}}',
						'allowedRepo' => 'https://github.com/ConductionNL/hydra',
						'scope' => ['lib/{{slug}}', 'docs'],
						'message' => 'fix: issue {{issueNumber}}',
					],
				]
			),
			[]
		);

		$push = $this->lastCall[8];

		$this->assertSame('feature/493/builder-write-access', $push['branch']);
		$this->assertSame('493', $push['issue']);
		$this->assertSame(['lib/builder-write-access', 'docs'], $push['scope']);
		$this->assertSame('fix: issue 493', $push['message']);
		$this->assertStringNotContainsString('{{', json_encode($push));

	}//end testThePushDeclarationIsForwardedAndRendered()

	/**
	 * The push credential is forwarded SEPARATELY from the broker credential.
	 *
	 * One id cannot serve both jobs: `credentialId` is spent on the broker's own
	 * server-side `request()` for the tool tarball, which needs a host-locked
	 * proxy credential — the exact shape `resolveInjectable()` refuses. A push
	 * needs the token inside the container, i.e. `inject_only`. Collapsing them
	 * would make a stage that fetches a private tool tree and pushes
	 * inexpressible, and the symptom would be a clone that silently ran
	 * unauthenticated.
	 *
	 * @return void
	 */
	public function testThePushCredentialIsSeparateFromTheBrokerCredential(): void {
		$node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

		$node->execute(
			[['json' => ['pushCred' => '55003b23-6262-495e-b0ab-2e221ba5e17c']]],
			(
				$this->config() + [
					'owner' => 'ruben',
					'credentialId' => '35327e7a-cafe-4a21-8ffe-6195d52f9579',
					'pushCredentialId' => '{{pushCred}}',
					'push' => ['branch' => 'feature/1/x', 'issue' => '1'],
				]
			),
			[]
		);

		$this->assertSame('35327e7a-cafe-4a21-8ffe-6195d52f9579', $this->lastCall[4]);
		$this->assertSame('55003b23-6262-495e-b0ab-2e221ba5e17c', $this->lastCall[9]);
		$this->assertNotSame($this->lastCall[4], $this->lastCall[9]);

	}//end testThePushCredentialIsSeparateFromTheBrokerCredential()

	/**
	 * A push declaring no issue is refused at SAVE time.
	 *
	 * `pushGuard` builds its allowlist pattern out of the issue number and fails
	 * closed without one, so such a flow cannot push at all — it would fail half
	 * an hour into a stage with a message about an allowlist. Refusing it while
	 * the author is still looking at it is the whole point of validateConfig().
	 *
	 * @return void
	 */
	public function testAPushWithoutAnIssueIsRefusedAtSaveTime(): void {
		$node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

		$this->expectException(UnexpectedValueException::class);

		$node->validateConfig($this->config() + ['push' => ['branch' => 'feature/1/x']]);

	}//end testAPushWithoutAnIssueIsRefusedAtSaveTime()

	/**
	 * A push declaring no branch is refused at SAVE time.
	 *
	 * @return void
	 */
	public function testAPushWithoutABranchIsRefusedAtSaveTime(): void {
		$node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

		$this->expectException(UnexpectedValueException::class);

		$node->validateConfig($this->config() + ['push' => ['issue' => '493']]);

	}//end testAPushWithoutABranchIsRefusedAtSaveTime()

	/**
	 * The same refusal holds through `execute()`, not only through the editor.
	 *
	 * A flow that arrived by import or seeding never passes `validateConfig()`,
	 * and this node already learned that lesson once: an unvalidated step used
	 * to become a silent pass-through whose output key was simply absent.
	 *
	 * @return void
	 */
	public function testAnUnfencedPushIsRefusedOnExecuteToo(): void {
		$node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

		$this->expectException(UnexpectedValueException::class);

		$node->execute(
			[['json' => []]],
			($this->config() + ['owner' => 'ruben', 'push' => ['branch' => 'feature/1/x']]),
			[]
		);

	}//end testAnUnfencedPushIsRefusedOnExecuteToo()

	/**
	 * The node identifies itself as the workload step.
	 *
	 * @return void
	 */
	public function testItRegistersAsTheWorkloadStep(): void {
		$node = $this->nodeReturning(['exitCode' => 0, 'output' => '', 'ref' => '']);

		$this->assertSame('hermiq.workload-step', $node->getId());

	}//end testItRegistersAsTheWorkloadStep()
}//end class
