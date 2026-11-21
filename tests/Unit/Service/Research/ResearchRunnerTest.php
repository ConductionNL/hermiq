<?php

/**
 * Unit tests for ResearchRunner, ResearchRecord and ResearchAuthor (tools-deep-research-report).
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
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Research;

use OCA\Hermiq\Kernel\Contracts\MessageHistoryHandlerInterface;
use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\Handoff\HandoffService;
use OCA\Hermiq\Service\Research\ReportWriter;
use OCA\Hermiq\Service\Research\ResearchAuthor;
use OCA\Hermiq\Service\Research\ResearchGate;
use OCA\Hermiq\Service\Research\ResearchObjects;
use OCA\Hermiq\Service\Research\ResearchRecord;
use OCA\Hermiq\Service\Research\ResearchRunner;
use OCA\Hermiq\Service\TenantKillSwitch;
use OCA\Hermiq\Tests\Unit\Service\Research\Fakes\EventLog;
use OCA\Hermiq\Tests\Unit\Service\Research\Fakes\RecordingResearchTools;
use OCA\Hermiq\Tests\Unit\Service\Research\Fakes\ScriptedResearchModel;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\Research\ResearchRunner
 * @covers \OCA\Hermiq\Service\Research\ResearchRecord
 * @covers \OCA\Hermiq\Service\Research\ResearchAuthor
 * @covers \OCA\Hermiq\Service\Research\ResearchRunState
 * @uses   \OCA\Hermiq\Service\Research\ResearchPlan
 * @uses   \OCA\Hermiq\Service\Research\ResearchReport
 * @uses   \OCA\Hermiq\Service\Research\ResearchCaps
 * @uses   \OCA\Hermiq\Service\Research\ResearchGate
 * @uses   \OCA\Hermiq\Service\Research\SourceLedger
 * @uses   \OCA\Hermiq\Service\Research\CitationChecker
 * @uses   \OCA\Hermiq\Service\Research\CitationCheck
 * @uses   \OCA\Hermiq\Service\TenantKillSwitch
 */
final class ResearchRunnerTest extends TestCase {

	/**
	 * When the run was started.
	 */
	private const START = 1763700000;

	/**
	 * The stored research handoff.
	 *
	 * @var array<string, mixed>
	 */
	private array $handoff = [];

	/**
	 * The messages posted in the conversation.
	 *
	 * @var list<string>
	 */
	private array $posted = [];

	/**
	 * The clock, in seconds.
	 *
	 * @var integer
	 */
	private int $now = self::START;

	/**
	 * The markdown the writer was asked to save, null when it was not asked.
	 *
	 * @var string|null
	 */
	private ?string $written = null;

	/**
	 * What happened, in order.
	 *
	 * @var EventLog
	 */
	private EventLog $log;

	/**
	 * The search and fetch tools.
	 *
	 * @var RecordingResearchTools
	 */
	private RecordingResearchTools $tools;

	/**
	 * The model.
	 *
	 * @var ScriptedResearchModel
	 */
	private ScriptedResearchModel $model;

	/**
	 * The budget.
	 *
	 * @var BudgetService&MockObject
	 */
	private BudgetService&MockObject $budgets;

	/**
	 * The tenant controls the kill switch reads.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $controls;

	/**
	 * The report writer, its message wording real.
	 *
	 * @var ReportWriter&MockObject
	 */
	private ReportWriter&MockObject $writer;

	/**
	 * A waiting research run with room in every cap, three sub-questions and a draft citing two sources.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->handoff = [
			'kind'           => 'research',
			'status'         => 'waiting',
			'agentId'        => 'agent-1',
			'conversationId' => 'conv-1',
			'requestedBy'    => 'anne',
			'label'          => 'Welke gemeenten gebruiken een algoritmeregister?',
			'organisation'   => 'org-1',
			'caps'           => ['toolCalls' => 40, 'sources' => 20, 'minutes' => 20, 'tokens' => 200000],
			'requestedAt'    => '@' . self::START,
			'stopRequested'  => false,
		];
		$this->log   = new EventLog();
		$this->tools = new RecordingResearchTools(log: $this->log);
		$this->model = new ScriptedResearchModel(
			answers: [
				"1. Welke gemeenten?\n2. Wat publiceren ze?\n3. Sinds wanneer?",
				"Amsterdam publiceert [1: \"page one says the register is public\"].\nUtrecht ook [2: \"a passage nobody wrote\"].",
			],
			log: $this->log
		);

		$this->budgets  = $this->createMock(originalClassName: BudgetService::class);
		$this->budgets->method('isBlocked')->willReturn(false);
		$this->controls = $this->killSwitchControls(engaged: []);

		$this->writer = $this->getMockBuilder(className: ReportWriter::class)->disableOriginalConstructor()->onlyMethods(['write'])->getMock();
		$this->writer->method('write')->willReturnCallback(function (string $userId, string $title, string $markdown): array {
			$this->log->note(event: 'write');
			$this->written = $markdown;
			return ['fileId' => 7, 'name' => '2025-11-21 Report.md', 'path' => 'Hermiq/Onderzoek/2025-11-21 Report.md', 'url' => 'https://nc/f/7'];
		});
	}//end setUp()

	/**
	 * The plan is the first model call, posted before any search; the report follows and the run is done.
	 *
	 * @return void
	 */
	public function testThePlanIsPostedBeforeAnySearchAndTheReportFollows(): void {
		self::assertSame(expected: 'done', actual: $this->runner()->run('r-1'));

		self::assertSame(expected: 'model', actual: $this->log->events[0]);
		self::assertStringStartsWith(prefix: 'message:**Research plan**', string: $this->log->events[1]);
		self::assertStringStartsWith(prefix: 'search:', string: $this->log->events[2]);
		self::assertSame(expected: "**Research plan**\n\n1. Welke gemeenten?\n2. Wat publiceren ze?\n3. Sinds wanneer?", actual: $this->posted[0]);
		self::assertSame(expected: ['Welke gemeenten?', 'Wat publiceren ze?', 'Sinds wanneer?'], actual: $this->handoff['plan']);
		self::assertSame(expected: 'done', actual: $this->handoff['status']);
		self::assertSame(expected: 'done', actual: $this->handoff['progress']['phase']);
	}//end testThePlanIsPostedBeforeAnySearchAndTheReportFollows()

	/**
	 * Every sub-question is one search through the toolbox; every page read is numbered once.
	 *
	 * @return void
	 */
	public function testEverySearchAndFetchGoesThroughTheToolboxAndEveryPageIsNumbered(): void {
		$this->runner()->run('r-1');

		self::assertSame(expected: 'agent-1', actual: $this->tools->openedFor);
		self::assertCount(expectedCount: 3, haystack: $this->log->starting(prefix: 'search:'));
		self::assertCount(expectedCount: 6, haystack: $this->log->starting(prefix: 'fetch:'));
		self::assertCount(expectedCount: 2, haystack: $this->handoff['ledger']);
		self::assertSame(expected: 1, actual: $this->handoff['ledger'][0]['number']);
		self::assertSame(expected: 'https://a.example/1', actual: $this->handoff['ledger'][0]['url']);
		self::assertSame(expected: 'Register Amsterdam', actual: $this->handoff['ledger'][0]['title']);
		self::assertSame(expected: 9, actual: $this->handoff['progress']['toolCalls']);
	}//end testEverySearchAndFetchGoesThroughTheToolboxAndEveryPageIsNumbered()

	/**
	 * A fetch the governed chain refused is not in the ledger.
	 *
	 * @return void
	 */
	public function testARefusedFetchIsNotInTheLedger(): void {
		$this->tools->fetches['https://a.example/2'] = ['error' => 'egress_denied'];

		$this->runner()->run('r-1');

		self::assertCount(expectedCount: 1, haystack: $this->handoff['ledger']);
		self::assertSame(expected: 'https://a.example/1', actual: $this->handoff['ledger'][0]['url']);
	}//end testARefusedFetchIsNotInTheLedger()

	/**
	 * Every citation is checked against what was read; the message counts the unverified.
	 *
	 * @return void
	 */
	public function testEveryCitationIsCheckedAndTheMessageCountsTheUnverified(): void {
		$this->runner()->run('r-1');

		self::assertStringContainsString(needle: '[1]', haystack: (string)$this->written);
		self::assertStringContainsString(needle: '(unverified)', haystack: (string)$this->written);
		self::assertStringContainsString(needle: '## Sources', haystack: (string)$this->written);
		self::assertStringContainsString(needle: '2 sources, 2 citations checked, 1 unverified', haystack: (string)end($this->posted));
		self::assertStringContainsString(needle: '(https://nc/f/7)', haystack: (string)end($this->posted));
		self::assertSame(expected: ['report', 'sources', 'checked', 'unverified'], actual: array_keys($this->handoff['result']));
		self::assertSame(expected: 1, actual: $this->handoff['result']['unverified']);
	}//end testEveryCitationIsCheckedAndTheMessageCountsTheUnverified()

	/**
	 * At the source limit gathering stops, and the report starts with the reason.
	 *
	 * @return void
	 */
	public function testTheSourceLimitStopsGatheringAndHeadsTheReport(): void {
		$this->handoff['caps']['sources'] = 1;

		self::assertSame(expected: 'done', actual: $this->runner()->run('r-1'));

		self::assertCount(expectedCount: 1, haystack: $this->log->starting(prefix: 'fetch:'));
		self::assertStringStartsWith(prefix: 'This report stopped early: the source limit of 1 was reached.', string: (string)$this->written);
	}//end testTheSourceLimitStopsGatheringAndHeadsTheReport()

	/**
	 * At the time limit gathering stops, judged by the injected clock.
	 *
	 * @return void
	 */
	public function testTheTimeLimitStopsGathering(): void {
		$this->handoff['caps']['minutes'] = 1;
		$this->tools->afterFetch = function (): void {
			$this->now += 61;
		};

		$this->runner()->run('r-1');

		self::assertCount(expectedCount: 1, haystack: $this->log->starting(prefix: 'fetch:'));
		self::assertStringStartsWith(prefix: 'This report stopped early: the time limit of 1 minutes was reached.', string: (string)$this->written);
	}//end testTheTimeLimitStopsGathering()

	/**
	 * A stop ends the run at its next step: no further fetch, no file, "Research stopped.".
	 *
	 * @return void
	 */
	public function testAStopEndsTheRunAtItsNextStepWithoutAReport(): void {
		$this->tools->afterFetch = function (int $fetches): void {
			$this->handoff['stopRequested'] = ($fetches >= 1);
		};

		self::assertSame(expected: 'ended', actual: $this->runner()->run('r-1'));

		self::assertCount(expectedCount: 1, haystack: $this->log->starting(prefix: 'fetch:'));
		self::assertNull(actual: $this->written);
		self::assertSame(expected: 'Research stopped.', actual: end($this->posted));
		self::assertSame(expected: 'failed', actual: $this->handoff['status']);
		self::assertSame(expected: 'Research stopped.', actual: $this->handoff['result']['reason']);
	}//end testAStopEndsTheRunAtItsNextStepWithoutAReport()

	/**
	 * An engaged kill switch ends the run before the first model call.
	 *
	 * @return void
	 */
	public function testAnEngagedKillSwitchEndsTheRunBeforeTheFirstModelCall(): void {
		$this->controls = $this->killSwitchControls(engaged: ['org-1']);

		self::assertSame(expected: 'ended', actual: $this->runner()->run('r-1'));

		self::assertNotContains(needle: 'model', haystack: $this->log->events);
		self::assertSame(expected: [ResearchGate::KILL_SWITCH], actual: $this->posted);
	}//end testAnEngagedKillSwitchEndsTheRunBeforeTheFirstModelCall()

	/**
	 * A budget at its cap before the report's model call ends the run without a report.
	 *
	 * @return void
	 */
	public function testABudgetAtItsCapBeforeTheReportEndsTheRunWithoutAReport(): void {
		$this->budgets = $this->createMock(originalClassName: BudgetService::class);
		$this->budgets->method('isBlocked')->willReturnOnConsecutiveCalls(false, true);

		self::assertSame(expected: 'ended', actual: $this->runner()->run('r-1'));

		self::assertSame(expected: ['model'], actual: $this->log->starting(prefix: 'model'));
		self::assertNull(actual: $this->written);
		self::assertSame(expected: ResearchGate::BUDGET, actual: end($this->posted));
	}//end testABudgetAtItsCapBeforeTheReportEndsTheRunWithoutAReport()

	/**
	 * An agent that may not both search and read the web ends the run.
	 *
	 * @return void
	 */
	public function testAnAgentThatMayNotSearchAndReadTheWebEndsTheRun(): void {
		$this->tools->granted = false;

		self::assertSame(expected: 'ended', actual: $this->runner()->run('r-1'));

		self::assertSame(expected: ResearchRunner::NO_TOOLS, actual: end($this->posted));
		self::assertNull(actual: $this->written);
	}//end testAnAgentThatMayNotSearchAndReadTheWebEndsTheRun()

	/**
	 * A report that cannot be marked fails the run with the writer's sentence.
	 *
	 * @return void
	 */
	public function testAFailedMarkFailsTheRun(): void {
		$this->writer = $this->getMockBuilder(className: ReportWriter::class)->disableOriginalConstructor()->onlyMethods(['write'])->getMock();
		$this->writer->method('write')->willThrowException(new RuntimeException(ReportWriter::NOT_MARKED));

		self::assertSame(expected: 'ended', actual: $this->runner()->run('r-1'));

		self::assertSame(expected: ReportWriter::NOT_MARKED, actual: end($this->posted));
		self::assertSame(expected: 'failed', actual: $this->handoff['status']);
	}//end testAFailedMarkFailsTheRun()

	/**
	 * A model that fails ends the run without a report.
	 *
	 * @return void
	 */
	public function testAModelFailureEndsTheRunWithoutAReport(): void {
		$this->model = new ScriptedResearchModel(answers: [], log: $this->log);

		self::assertSame(expected: 'ended', actual: $this->runner()->run('r-1'));

		self::assertSame(expected: [ResearchRunner::FAILED], actual: $this->posted);
		self::assertSame(expected: 'failed', actual: $this->handoff['status']);
	}//end testAModelFailureEndsTheRunWithoutAReport()

	/**
	 * A handoff that is not a waiting research run is skipped untouched.
	 *
	 * @return void
	 */
	public function testAHandoffThatIsNotAWaitingResearchRunIsSkipped(): void {
		$this->handoff['kind'] = 'flow';
		self::assertSame(expected: 'skipped', actual: $this->runner()->run('r-1'));

		$this->handoff['kind']   = 'research';
		$this->handoff['status'] = 'done';
		self::assertSame(expected: 'skipped', actual: $this->runner()->run('r-1'));

		self::assertSame(expected: 'skipped', actual: $this->runner()->run('gone'));
		self::assertSame(expected: [], actual: $this->log->events);
	}//end testAHandoffThatIsNotAWaitingResearchRunIsSkipped()

	/**
	 * The agent owner's prompt leads both model calls; the report prompt carries the numbered sources.
	 *
	 * @return void
	 */
	public function testTheAgentsPromptLeadsEveryModelCall(): void {
		$this->runner()->run('r-1');

		self::assertCount(expectedCount: 2, haystack: $this->model->prompts);
		self::assertStringStartsWith(prefix: "Schrijf zakelijk.\n\nYou plan a web research.", string: $this->model->prompts[0]);
		self::assertStringStartsWith(prefix: "Schrijf zakelijk.\n\nWrite a research report", string: $this->model->prompts[1]);
		self::assertStringContainsString(needle: '[1] Register Amsterdam <https://a.example/1>', haystack: $this->model->prompts[1]);
		self::assertGreaterThan(expected: 0, actual: $this->handoff['progress']['tokens']);
	}//end testTheAgentsPromptLeadsEveryModelCall()

	/**
	 * The runner over the doubles of this test.
	 *
	 * @return ResearchRunner
	 */
	private function runner(): ResearchRunner {
		$handoffs = $this->createMock(originalClassName: HandoffService::class);
		$handoffs->method('find')->willReturnCallback(fn (string $id): ?array => $this->stored(handoffId: $id));
		$handoffs->method('update')->willReturnCallback(function (string $id, array $fields): bool {
			$this->handoff = array_merge($this->handoff, $fields);
			return true;
		});
		$handoffs->method('close')->willReturnCallback(function (string $id, string $status, mixed $result): bool {
			$this->handoff['status'] = $status;
			$this->handoff['result'] = $result;
			return true;
		});

		$messages = $this->createMock(originalClassName: MessageHistoryHandlerInterface::class);
		$messages->method('storeMessage')->willReturnCallback(function (string $conversationId, string $role, string $content): ObjectEntity {
			$this->log->note(event: 'message:' . $content);
			$this->posted[] = $content;
			return new ObjectEntity();
		});

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$objects = $this->createMock(originalClassName: ResearchObjects::class);
		$objects->method('agentPrompt')->willReturn('Schrijf zakelijk.');

		return new ResearchRunner(
			record: new ResearchRecord(handoffs: $handoffs, messages: $messages, time: $time, logger: new NullLogger()),
			author: new ResearchAuthor(
				model: $this->model,
				gate: new ResearchGate(budgets: $this->budgets, killSwitch: new TenantKillSwitch(objectService: $this->controls, logger: new NullLogger())),
				objects: $objects
			),
			tools: $this->tools,
			writer: $this->writer
		);
	}//end runner()

	/**
	 * The stored handoff for this id, null for any other.
	 *
	 * @param string $handoffId The id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function stored(string $handoffId): ?array {
		if ($handoffId !== 'r-1') {
			return null;
		}

		return $this->handoff;
	}//end stored()

	/**
	 * Tenant controls with the kill switch engaged for these organisations.
	 *
	 * @param list<string> $engaged The engaged organisations.
	 *
	 * @return ObjectService&MockObject
	 */
	private function killSwitchControls(array $engaged): ObjectService&MockObject {
		$objects = [];
		foreach ($engaged as $organisation) {
			$entity = $this->createMock(originalClassName: ObjectEntity::class);
			$entity->method('getObject')->willReturn(['engaged' => true]);
			$entity->method('getOrganisation')->willReturn($organisation);
			$objects[] = $entity;
		}

		$controls = $this->createMock(originalClassName: ObjectService::class);
		$controls->method('setRegister')->willReturnSelf();
		$controls->method('setSchema')->willReturnSelf();
		$controls->method('findAll')->willReturn($objects);

		return $controls;
	}//end killSwitchControls()
}//end class
