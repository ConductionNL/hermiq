<?php

/**
 * Unit tests for ResearchStarter (tools-deep-research-report).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Research
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Research;

use InvalidArgumentException;
use OCA\Hermiq\BackgroundJob\ResearchJob;
use OCA\Hermiq\Service\Handoff\HandoffService;
use OCA\Hermiq\Service\Research\ResearchStarter;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\Research\ResearchStarter
 * @uses   \OCA\Hermiq\Service\Research\ResearchCaps
 */
final class ResearchStarterTest extends TestCase {

	/**
	 * @var HandoffService&MockObject
	 */
	private HandoffService&MockObject $handoffs;

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * @var IJobList&MockObject
	 */
	private IJobList&MockObject $jobs;

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $config;

	/**
	 * The schemas the person may read.
	 *
	 * @var list<string>
	 */
	private array $readable = ['agentsession', 'agent'];

	protected function setUp(): void {
		$this->handoffs = $this->createMock(HandoffService::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->jobs = $this->createMock(IJobList::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->objects->method('find')->willReturnCallback(function (string $id, ObjectFindOptions $options): ObjectEntity {
			if (in_array($options->schema, $this->readable, true) === false) {
				throw new RuntimeException('Not readable');
			}

			$entity = $this->createMock(ObjectEntity::class);
			$entity->method('getOrganisation')->willReturn('org-1');
			return $entity;
		});
	}

	public function testAStartOpensAResearchHandoffAndQueuesTheRun(): void {
		$this->config->method('getValueString')->with('hermiq', 'research_caps', '')->willReturn('{"sources": 30, "minutes": "10"}');
		$opened = null;
		$this->handoffs->expects(self::once())->method('open')->willReturnCallback(function (array $handoff) use (&$opened): array {
			$opened = $handoff;
			return ['id' => 'r-1', 'status' => 'waiting'] + $handoff;
		});
		$this->jobs->expects(self::once())->method('add')->with(ResearchJob::class, ['handoffId' => 'r-1']);

		$run = $this->starter()->start('agent-1', 'conv-1', 'anne', '  Welke gemeenten?  ', 'quick');

		self::assertSame('research', $opened['kind']);
		self::assertSame('Welke gemeenten?', $opened['label']);
		self::assertSame('anne', $opened['requestedBy']);
		self::assertSame('deep-research', $opened['target']);
		self::assertSame('org-1', $opened['organisation']);
		self::assertSame(1, $opened['deadlineHours']);
		self::assertFalse($opened['stopRequested']);
		// The instance caps, halved for a quick run.
		self::assertSame(['toolCalls' => 20, 'sources' => 15, 'minutes' => 5, 'tokens' => 100000], $opened['caps']);
		self::assertSame('r-1', $run['id']);
		self::assertSame('waiting', $run['status']);
		self::assertSame('Welke gemeenten?', $run['question']);
		self::assertArrayNotHasKey('ledger', $run);
	}

	public function testUnsetOrBrokenInstanceCapsAreTheDefaults(): void {
		$this->config->method('getValueString')->willReturn('not json');
		$opened = null;
		$this->handoffs->method('open')->willReturnCallback(function (array $handoff) use (&$opened): array {
			$opened = $handoff;
			return ['id' => 'r-1'] + $handoff;
		});

		$this->starter()->start('agent-1', 'conv-1', 'anne', 'Q', 'thorough');

		self::assertSame(['toolCalls' => 40, 'sources' => 20, 'minutes' => 20, 'tokens' => 200000], $opened['caps']);
	}

	public function testAnAgentOrConversationThePersonCannotReadStartsNothing(): void {
		$this->handoffs->expects(self::never())->method('open');
		$this->jobs->expects(self::never())->method('add');

		$this->readable = ['agentsession'];
		self::assertNull($this->starter()->start('agent-1', 'conv-1', 'anne', 'Q', 'thorough'));

		$this->readable = ['agent'];
		self::assertNull($this->starter()->start('agent-1', 'conv-1', 'anne', 'Q', 'thorough'));
	}

	/**
	 * @dataProvider badStarts
	 *
	 * @param list<string> $arguments The start's arguments.
	 */
	public function testABadStartIsRefused(array $arguments): void {
		$this->handoffs->expects(self::never())->method('open');
		$this->expectException(InvalidArgumentException::class);

		$this->starter()->start(...$arguments);
	}

	/**
	 * @return array<string, array{0: list<string>}>
	 */
	public static function badStarts(): array {
		return [
			'no question'     => [['agent-1', 'conv-1', 'anne', '   ', 'quick']],
			'no agent'        => [['', 'conv-1', 'anne', 'Q', 'quick']],
			'no conversation' => [['agent-1', '', 'anne', 'Q', 'quick']],
			'unknown depth'   => [['agent-1', 'conv-1', 'anne', 'Q', 'deep']],
		];
	}

	public function testOnlyThePersonWhoStartedARunMayStopOrReadIt(): void {
		$this->handoffs->method('find')->willReturnCallback(fn (string $id): ?array => match ($id) {
			'r-1' => ['kind' => 'research', 'requestedBy' => 'anne', 'status' => 'waiting', 'label' => 'Q', 'plan' => ['a'], 'ledger' => [['text' => 'secret']]],
			'h-1' => ['kind' => 'flow', 'requestedBy' => 'anne', 'status' => 'waiting'],
			default => null,
		});
		$this->handoffs->expects(self::once())->method('update')->with('r-1', ['stopRequested' => true])->willReturn(true);

		self::assertFalse($this->starter()->stop('r-1', 'bob'));
		self::assertFalse($this->starter()->stop('h-1', 'anne'));
		self::assertFalse($this->starter()->stop('gone', 'anne'));
		self::assertFalse($this->starter()->stop('r-1', ''));
		self::assertTrue($this->starter()->stop('r-1', 'anne'));

		self::assertNull($this->starter()->show('r-1', 'bob'));
		$run = $this->starter()->show('r-1', 'anne');
		self::assertSame(['a'], $run['plan']);
		self::assertSame('r-1', $run['id']);
		self::assertArrayNotHasKey('ledger', $run);
	}

	/**
	 * The starter over the doubles.
	 *
	 * @return ResearchStarter
	 */
	private function starter(): ResearchStarter {
		return new ResearchStarter($this->handoffs, $this->objects, $this->jobs, $this->config);
	}
}
