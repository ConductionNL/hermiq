<?php

/**
 * Unit tests for ResearchStarter and ResearchRequest (tools-deep-research-report).
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
use OCA\Hermiq\Service\Research\ResearchObjects;
use OCA\Hermiq\Service\Research\ResearchRequest;
use OCA\Hermiq\Service\Research\ResearchStarter;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\Research\ResearchStarter
 * @covers \OCA\Hermiq\Service\Research\ResearchRequest
 * @uses   \OCA\Hermiq\Service\Research\ResearchCaps
 */
final class ResearchStarterTest extends TestCase {

	/**
	 * The handoffs.
	 *
	 * @var HandoffService&MockObject
	 */
	private HandoffService&MockObject $handoffs;

	/**
	 * The objects reader.
	 *
	 * @var ResearchObjects&MockObject
	 */
	private ResearchObjects&MockObject $objects;

	/**
	 * The job list.
	 *
	 * @var IJobList&MockObject
	 */
	private IJobList&MockObject $jobs;

	/**
	 * The app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $config;

	/**
	 * The schemas the person may read.
	 *
	 * @var list<string>
	 */
	private array $readable = [ResearchObjects::CONVERSATION, ResearchObjects::AGENT];

	/**
	 * The handoff the starter opened.
	 *
	 * @var array<string, mixed>
	 */
	private array $opened = [];

	/**
	 * A person who may read the agent and the conversation, of organisation org-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->handoffs = $this->createMock(originalClassName: HandoffService::class);
		$this->objects  = $this->createMock(originalClassName: ResearchObjects::class);
		$this->jobs     = $this->createMock(originalClassName: IJobList::class);
		$this->config   = $this->createMock(originalClassName: IAppConfig::class);
		$this->objects->method('readable')->willReturnCallback(fn (string $uuid, string $schema): ?ObjectEntity => $this->object(schema: $schema));
		$this->handoffs->method('open')->willReturnCallback(function (array $handoff): array {
			$this->opened = $handoff;
			return ['id' => 'r-1', 'status' => 'waiting'] + $handoff;
		});
	}//end setUp()

	/**
	 * A start opens a research handoff with the caps for its depth and queues the run.
	 *
	 * @return void
	 */
	public function testAStartOpensAResearchHandoffAndQueuesTheRun(): void {
		$this->config->method('getValueString')->with('hermiq', 'research_caps', '')->willReturn('{"sources": 30, "minutes": "10"}');
		$this->jobs->expects(self::once())->method('add')->with(ResearchJob::class, ['handoffId' => 'r-1']);

		$request = new ResearchRequest(agentId: 'agent-1', conversationId: 'conv-1', question: '  Welke gemeenten?  ', depth: 'quick');
		$run     = $this->starter()->start(request: $request, userId: 'anne');

		self::assertSame(expected: 'research', actual: $this->opened['kind']);
		self::assertSame(expected: 'Welke gemeenten?', actual: $this->opened['label']);
		self::assertSame(expected: 'anne', actual: $this->opened['requestedBy']);
		self::assertSame(expected: 'deep-research', actual: $this->opened['target']);
		self::assertSame(expected: 'org-1', actual: $this->opened['organisation']);
		self::assertSame(expected: 1, actual: $this->opened['deadlineHours']);
		self::assertFalse(condition: $this->opened['stopRequested']);
		self::assertSame(expected: ['toolCalls' => 20, 'sources' => 15, 'minutes' => 5, 'tokens' => 100000], actual: $this->opened['caps']);
		self::assertSame(expected: 'r-1', actual: $run['id']);
		self::assertSame(expected: 'waiting', actual: $run['status']);
		self::assertSame(expected: 'Welke gemeenten?', actual: $run['question']);
		self::assertArrayNotHasKey(key: 'ledger', array: $run);
	}//end testAStartOpensAResearchHandoffAndQueuesTheRun()

	/**
	 * Unset or broken instance caps are the defaults.
	 *
	 * @return void
	 */
	public function testUnsetOrBrokenInstanceCapsAreTheDefaults(): void {
		$this->config->method('getValueString')->willReturn('not json');

		$this->starter()->start(request: new ResearchRequest(agentId: 'agent-1', conversationId: 'conv-1', question: 'Q'), userId: 'anne');

		self::assertSame(expected: ['toolCalls' => 40, 'sources' => 20, 'minutes' => 20, 'tokens' => 200000], actual: $this->opened['caps']);
		self::assertSame(expected: 'thorough', actual: $this->opened['depth']);
	}//end testUnsetOrBrokenInstanceCapsAreTheDefaults()

	/**
	 * An agent or a conversation the person cannot read starts nothing.
	 *
	 * @return void
	 */
	public function testAnAgentOrConversationThePersonCannotReadStartsNothing(): void {
		$this->handoffs->expects(self::never())->method('open');
		$this->jobs->expects(self::never())->method('add');
		$request = new ResearchRequest(agentId: 'agent-1', conversationId: 'conv-1', question: 'Q');

		$this->readable = [ResearchObjects::CONVERSATION];
		self::assertNull(actual: $this->starter()->start(request: $request, userId: 'anne'));

		$this->readable = [ResearchObjects::AGENT];
		self::assertNull(actual: $this->starter()->start(request: $request, userId: 'anne'));

		$this->readable = [ResearchObjects::CONVERSATION, ResearchObjects::AGENT];
		self::assertNull(actual: $this->starter()->start(request: $request, userId: ''));
	}//end testAnAgentOrConversationThePersonCannotReadStartsNothing()

	/**
	 * A request without a question, agent or conversation, or with an unknown depth, is refused.
	 *
	 * @param array{0: string, 1: string, 2: string, 3: string} $parts The agent, conversation, question and depth.
	 *
	 * @return void
	 *
	 * @dataProvider badRequests
	 */
	public function testABadRequestIsRefused(array $parts): void {
		$this->expectException(exception: InvalidArgumentException::class);

		new ResearchRequest(agentId: $parts[0], conversationId: $parts[1], question: $parts[2], depth: $parts[3]);
	}//end testABadRequestIsRefused()

	/**
	 * The bad requests.
	 *
	 * @return array<string, array{0: array{0: string, 1: string, 2: string, 3: string}}>
	 */
	public static function badRequests(): array {
		return [
			'no question'     => [['agent-1', 'conv-1', '   ', 'quick']],
			'no agent'        => [['', 'conv-1', 'Q', 'quick']],
			'no conversation' => [['agent-1', '', 'Q', 'quick']],
			'unknown depth'   => [['agent-1', 'conv-1', 'Q', 'deep']],
		];
	}//end badRequests()

	/**
	 * Only the person who started a run may stop or read it.
	 *
	 * @return void
	 */
	public function testOnlyThePersonWhoStartedARunMayStopOrReadIt(): void {
		$this->handoffs->method('find')->willReturnCallback(fn (string $id): ?array => $this->stored(handoffId: $id));
		$this->handoffs->expects(self::once())->method('update')->with('r-1', ['stopRequested' => true])->willReturn(true);
		$starter = $this->starter();

		self::assertFalse(condition: $starter->stop(handoffId: 'r-1', userId: 'bob'));
		self::assertFalse(condition: $starter->stop(handoffId: 'h-1', userId: 'anne'));
		self::assertFalse(condition: $starter->stop(handoffId: 'gone', userId: 'anne'));
		self::assertFalse(condition: $starter->stop(handoffId: 'r-1', userId: ''));
		self::assertTrue(condition: $starter->stop(handoffId: 'r-1', userId: 'anne'));

		self::assertNull(actual: $starter->show(handoffId: 'r-1', userId: 'bob'));
		$run = $starter->show(handoffId: 'r-1', userId: 'anne');
		self::assertSame(expected: ['a'], actual: $run['plan']);
		self::assertSame(expected: 'r-1', actual: $run['id']);
		self::assertArrayNotHasKey(key: 'ledger', array: $run);
	}//end testOnlyThePersonWhoStartedARunMayStopOrReadIt()

	/**
	 * The starter over the doubles.
	 *
	 * @return ResearchStarter
	 */
	private function starter(): ResearchStarter {
		return new ResearchStarter(handoffs: $this->handoffs, objects: $this->objects, jobList: $this->jobs, appConfig: $this->config);
	}//end starter()

	/**
	 * An object of org-1 when the person may read the schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return ObjectEntity|null
	 */
	private function object(string $schema): ?ObjectEntity {
		if (in_array($schema, $this->readable, true) === false) {
			return null;
		}

		$entity = new ObjectEntity();
		$entity->setOrganisation('org-1');
		return $entity;
	}//end object()

	/**
	 * A stored handoff by id.
	 *
	 * @param string $handoffId The id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function stored(string $handoffId): ?array {
		$handoffs = [
			'r-1' => ['kind' => 'research', 'requestedBy' => 'anne', 'status' => 'waiting', 'label' => 'Q', 'plan' => ['a'], 'ledger' => [['text' => 'page']]],
			'h-1' => ['kind' => 'flow', 'requestedBy' => 'anne', 'status' => 'waiting'],
		];

		return ($handoffs[$handoffId] ?? null);
	}//end stored()
}//end class
