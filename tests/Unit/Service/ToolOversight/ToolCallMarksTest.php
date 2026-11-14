<?php

/**
 * Tests for ToolCallMarks: an operator marks an agent's tool call as misuse.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\ToolOversight
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/verify-row-scenarios/specs/agent-tool-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\ToolOversight;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Hermiq\Service\ToolOversight\ToolCallMarks;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Row ov-tool-oversight: a mark needs a reason and records the operator.
 *
 * @covers \OCA\Hermiq\Service\ToolOversight\ToolCallMarks
 */
class ToolCallMarksTest extends TestCase {

	/**
	 * Every saveObject call, by argument name.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * The agent "Woo-triage" with these stored marks.
	 *
	 * @param array<int, array<string, mixed>> $marks The stored marks.
	 *
	 * @return ObjectEntity
	 */
	private function agent(array $marks = []): ObjectEntity {
		$agent = new ObjectEntity();
		$agent->setUuid('woo-triage');
		$agent->setObject(['@self' => ['id' => 'woo-triage'], 'name' => 'Woo-triage', 'tools' => ['hermiq.sendMail'], 'notes' => null, 'toolCallMarks' => $marks]);
		return $agent;
	}//end agent()

	/**
	 * Marks over an object service that records its saves.
	 *
	 * @return ToolCallMarks
	 */
	private function marks(): ToolCallMarks {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return new ObjectEntity();
			}
		);

		return new ToolCallMarks(objectService: $objects);
	}//end marks()

	/**
	 * A mark is written on the agent with the operator, the reason and the time.
	 *
	 * @return void
	 */
	public function testAMarkIsRecordedWithTheOperator(): void {
		$mark = $this->marks()->mark(
			agent: $this->agent(),
			callId: 'call-17',
			reason: '  Sending mail is not part of this agent\'s task ',
			operator: 'operator-anna',
			now: new DateTimeImmutable('2026-11-13T10:00:00+00:00')
		);

		$expected = [
			'callId' => 'call-17',
			'label' => 'misuse',
			'reason' => 'Sending mail is not part of this agent\'s task',
			'markedBy' => 'operator-anna',
			'markedAt' => '2026-11-13T10:00:00+00:00',
		];
		$this->assertSame($expected, $mark);
		$this->assertCount(1, $this->saves);
		$this->assertSame(
			[
				'object' => ['id' => 'woo-triage', 'name' => 'Woo-triage', 'tools' => ['hermiq.sendMail'], 'toolCallMarks' => [$expected]],
				'register' => 'hermiq',
				'schema' => 'agent',
				'uuid' => 'woo-triage',
			],
			$this->saves[0],
			'the whole agent is written, without @self and empty fields, with the mark'
		);
	}//end testAMarkIsRecordedWithTheOperator()

	/**
	 * A mark without a reason, or without a call, is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testAMarkWithoutAReasonIsRefused(): void {
		foreach ([['call-17', '   '], ['call-17', ''], [' ', 'reason']] as [$callId, $reason]) {
			try {
				$this->marks()->mark(agent: $this->agent(), callId: $callId, reason: $reason, operator: 'operator-anna');
				$this->fail('a mark without a reason or a call was accepted');
			} catch (InvalidArgumentException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}

		$this->assertSame([], $this->saves);
	}//end testAMarkWithoutAReasonIsRefused()

	/**
	 * A second mark on the same call replaces the first; other marks stay.
	 *
	 * @return void
	 */
	public function testASecondMarkReplacesTheFirst(): void {
		$earlier = ['callId' => 'call-17', 'label' => 'misuse', 'reason' => 'first', 'markedBy' => 'a', 'markedAt' => '2026-11-01T00:00:00+00:00'];
		$other = ['callId' => 'call-3', 'label' => 'misuse', 'reason' => 'other', 'markedBy' => 'b', 'markedAt' => '2026-11-02T00:00:00+00:00'];

		$this->marks()->mark(agent: $this->agent(marks: [$earlier, $other]), callId: 'call-17', reason: 'second', operator: 'c');

		$stored = $this->saves[0]['object']['toolCallMarks'];
		$this->assertSame(['call-17', 'call-3'], array_column($stored, 'callId'));
		$this->assertSame(['second', 'other'], array_column($stored, 'reason'));
	}//end testASecondMarkReplacesTheFirst()

	/**
	 * The tool activity shows a marked call with its mark; the rest is untouched.
	 *
	 * @return void
	 */
	public function testTheActivityShowsTheMark(): void {
		$mark = ['callId' => 'call-17', 'label' => 'misuse', 'reason' => 'r', 'markedBy' => 'operator-anna', 'markedAt' => '2026-11-13T10:00:00+00:00'];
		$rows = [['callId' => 'call-17', 'toolId' => 'hermiq.sendMail'], ['callId' => 'call-18', 'toolId' => 'hermiq.listFiles'], ['toolId' => 'legacy']];

		$shown = $this->marks()->overlay(rows: $rows, agent: $this->agent(marks: [$mark, 'not a mark']));

		$this->assertSame($mark, $shown[0]['mark']);
		$this->assertSame($rows[1], $shown[1]);
		$this->assertSame($rows[2], $shown[2]);
		$this->assertSame($rows, $this->marks()->overlay(rows: $rows, agent: $this->agent()));
	}//end testTheActivityShowsTheMark()
}//end class
