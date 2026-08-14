<?php

/**
 * The collect half of the async stage pair.
 *
 * What is pinned here is the distinction the whole transport rests on: `done`,
 * `failed` and `unknown` are THREE answers, not one, and collapsing any pair of
 * them breaks the flow in a different way.
 *
 *   done     the stage RAN. Its exit code may be non-zero — that is a verdict,
 *            not an error, and a gate downstream must be able to read it.
 *   failed   the stage could NOT be carried out. A refused push lands here with
 *            its stable code, and must never surface as a completed stage
 *            carrying an unlucky field.
 *   unknown  no such job — the runner restarted, or the handle was never on the
 *            item. TERMINAL. Answering `running` is how a flow waits forever
 *            for a result that no longer exists.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Flow
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Flow;

use OCA\Hermiq\Flow\HermiqWorkloadCollectNode;
use OCA\Hermiq\Service\AsyncStageDispatchService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * @covers \OCA\Hermiq\Flow\HermiqWorkloadCollectNode
 */
final class HermiqWorkloadCollectNodeTest extends TestCase {

	/**
	 * The transport, mocked so no stage is actually started.
	 *
	 * @var AsyncStageDispatchService&MockObject
	 */
	private $stages;

	/**
	 * The node under test.
	 *
	 * @var HermiqWorkloadCollectNode
	 */
	private HermiqWorkloadCollectNode $node;

	/**
	 * Build the node with stub collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->stages = $this->createMock(originalClassName: AsyncStageDispatchService::class);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->node = new HermiqWorkloadCollectNode(
			$this->stages,
			$l10n,
			$this->createMock(originalClassName: IURLGenerator::class)
		);

	}//end setUp()

	/**
	 * Wrap a record as the single item the node is given.
	 *
	 * @param array $json The record.
	 *
	 * @return array The item list.
	 */
	private function items(array $json): array {
		return [['json' => $json]];
	}//end items()

	/**
	 * A finished stage publishes its result where a SYNCHRONOUS step would.
	 *
	 * This is the property that lets a gate downstream read `stage.exitCode`
	 * without caring which transport delivered it. If the async path published a
	 * different shape, every gate would need two branches and one of them would
	 * rot unnoticed.
	 *
	 * @return void
	 */
	public function testADoneStagePublishesItsResultWhereASyncStepWould(): void {
		$this->stages->method('collect')->willReturn(
			['status' => 'done', 'result' => ['exitCode' => 1, 'output' => 'gates found 3 issues']]
		);

		$json = $this->node->execute(
			$this->items(['dispatched' => ['job' => ['id' => 'larpingapp-327-code-review']]]),
			['job' => '{{dispatched.job.id}}'],
			[]
		)[0]['json'];

		$this->assertSame(expected: 'done', actual: $json['collected']['status']);
		$this->assertSame(
			expected: 1,
			actual: $json['stage']['exitCode'],
			message: 'a NON-ZERO exit is a verdict the gate must be able to read, not an error'
		);
		$this->assertArrayNotHasKey(
			key: 'result',
			array: $json['collected'],
			message: 'the result is moved to the stage key, not left duplicated where a gate might read a stale copy'
		);

	}//end testADoneStagePublishesItsResultWhereASyncStepWould()

	/**
	 * A refused stage stays FAILED, with its stable code intact.
	 *
	 * A push refused for leaving its declared scope is the case that matters:
	 * read as a `done`, it becomes a stage that ran and merely happened to have
	 * no exit code — which is how a fence turns advisory.
	 *
	 * @return void
	 */
	public function testAFailedStageKeepsItsCodeAndPublishesNoStage(): void {
		$this->stages->method('collect')->willReturn(
			[
				'status' => 'failed',
				'error' => 'push refused: "README.md" is outside the scope this issue declared',
				'code' => 'scope_violation',
			]
		);

		$json = $this->node->execute(
			$this->items(['dispatched' => ['job' => ['id' => 'j1']]]),
			['job' => '{{dispatched.job.id}}'],
			[]
		)[0]['json'];

		$this->assertSame(expected: 'failed', actual: $json['collected']['status']);
		$this->assertSame(
			expected: 'scope_violation',
			actual: $json['collected']['code'],
			message: 'the stable code survives, so a consumer need not match prose'
		);
		$this->assertArrayNotHasKey(
			key: 'stage',
			array: $json,
			message: 'a refusal must not publish a stage a gate could read as a completed one'
		);

	}//end testAFailedStageKeepsItsCodeAndPublishesNoStage()

	/**
	 * An item with NO handle is `unknown`, and the runner is never asked.
	 *
	 * An empty handle is not a job that is still running — it is a dispatch
	 * whose acknowledgement never reached the item. Answering `running` would
	 * park the flow on it forever, which is the exact shape of a hang.
	 *
	 * @return void
	 */
	public function testAnItemWithoutAHandleIsUnknownAndNeverAsked(): void {
		$this->stages->expects($this->never())->method('collect');

		$json = $this->node->execute(
			$this->items(['dispatched' => []]),
			['job' => '{{dispatched.job.id}}'],
			[]
		)[0]['json'];

		$this->assertSame(
			expected: 'unknown',
			actual: $json['collected']['status'],
			message: 'a missing handle is terminal — reporting it as running is how a flow waits forever'
		);

	}//end testAnItemWithoutAHandleIsUnknownAndNeverAsked()

	/**
	 * The output keys are configurable, and both are honoured.
	 *
	 * @return void
	 */
	public function testTheOutputKeysAreHonoured(): void {
		$this->stages->method('collect')->willReturn(['status' => 'done', 'result' => ['exitCode' => 0]]);

		$json = $this->node->execute(
			$this->items(['dispatched' => ['job' => ['id' => 'j1']]]),
			['job' => '{{dispatched.job.id}}', 'output' => 'review', 'stageOutput' => 'reviewStage'],
			[]
		)[0]['json'];

		$this->assertSame(expected: 'done', actual: $json['review']['status']);
		$this->assertSame(expected: 0, actual: $json['reviewStage']['exitCode']);

	}//end testTheOutputKeysAreHonoured()

	/**
	 * A blank output key falls back rather than writing under ''.
	 *
	 * @return void
	 */
	public function testABlankOutputKeyFallsBack(): void {
		$this->stages->method('collect')->willReturn(['status' => 'done', 'result' => ['exitCode' => 0]]);

		$json = $this->node->execute(
			$this->items(['dispatched' => ['job' => ['id' => 'j1']]]),
			['job' => '{{dispatched.job.id}}', 'output' => '   ', 'stageOutput' => ''],
			[]
		)[0]['json'];

		$this->assertSame(expected: 'done', actual: $json['collected']['status']);
		$this->assertSame(expected: 0, actual: $json['stage']['exitCode']);

	}//end testABlankOutputKeyFallsBack()

	/**
	 * A step that names no job is refused at configuration time.
	 *
	 * @return void
	 */
	public function testAStepThatNamesNoJobIsRefused(): void {
		$this->expectException(exception: UnexpectedValueException::class);

		$this->node->validateConfig(['job' => '  ']);

	}//end testAStepThatNamesNoJobIsRefused()

	/**
	 * Every item is collected, not just the first.
	 *
	 * @return void
	 */
	public function testEveryItemIsCollected(): void {
		$this->stages->method('collect')->willReturn(['status' => 'done', 'result' => ['exitCode' => 0]]);

		$out = $this->node->execute(
			[
				['json' => ['dispatched' => ['job' => ['id' => 'a']]]],
				['json' => ['dispatched' => ['job' => ['id' => 'b']]]],
			],
			['job' => '{{dispatched.job.id}}'],
			[]
		);

		$this->assertCount(expectedCount: 2, haystack: $out);
		$this->assertSame(expected: 'done', actual: $out[1]['json']['collected']['status']);

	}//end testEveryItemIsCollected()

	/**
	 * The step declares itself for both scopes, matching the step it collects.
	 *
	 * A collect step available in fewer scopes than its dispatch step is a flow
	 * that can start work it cannot finish.
	 *
	 * @return void
	 */
	public function testTheStepIsAvailableWhereverItsDispatchStepIs(): void {
		$this->assertSame(expected: 'hermiq.workload-collect', actual: $this->node->getId());
		$this->assertTrue(condition: $this->node->isAvailableForScope(\OCP\WorkflowEngine\IManager::SCOPE_ADMIN));
		$this->assertTrue(condition: $this->node->isAvailableForScope(\OCP\WorkflowEngine\IManager::SCOPE_USER));

	}//end testTheStepIsAvailableWhereverItsDispatchStepIs()
}//end class
