<?php

/**
 * Unit tests for ResearchRunner (tools-deep-research-report).
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
use OCA\Hermiq\Service\Research\ResearchGate;
use OCA\Hermiq\Service\Research\ResearchModel;
use OCA\Hermiq\Service\Research\ResearchRunner;
use OCA\Hermiq\Service\Research\ResearchToolbox;
use OCA\Hermiq\Service\Research\ResearchTools;
use OCA\Hermiq\Service\TenantKillSwitch;
use OCA\Hermiq\Service\WebResearch\WebFetchService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\Research\ResearchRunner
 * @covers \OCA\Hermiq\Service\Research\ResearchRunState
 * @uses   \OCA\Hermiq\Service\Research\ResearchPlan
 * @uses   \OCA\Hermiq\Service\Research\ResearchCaps
 * @uses   \OCA\Hermiq\Service\Research\ResearchGate
 * @uses   \OCA\Hermiq\Service\Research\SourceLedger
 * @uses   \OCA\Hermiq\Service\Research\CitationChecker
 * @uses   \OCA\Hermiq\Service\Research\CitationCheck
 * @uses   \OCA\Hermiq\Service\TenantKillSwitch
 */
final class ResearchRunnerTest extends TestCase {

	private const START = 1763700000;

	/**
	 * The stored research handoff.
	 *
	 * @var array<string, mixed>
	 */
	private array $handoff = [];

	/**
	 * Everything that happened, in order: messages, searches, fetches, model calls.
	 *
	 * @var list<string>
	 */
	private array $events = [];

	/**
	 * The messages posted in the conversation.
	 *
	 * @var list<string>
	 */
	private array $posted = [];

	/**
	 * The search results per query; any other query finds the default list.
	 *
	 * @var list<array{title: string, url: string}>
	 */
	private array $results = [];

	/**
	 * Fetch answers per URL; a URL not listed reads as a page.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $fetches = [];

	/**
	 * The model's answers, in order.
	 *
	 * @var list<string>
	 */
	private array $answers = [];

	/**
	 * Whether the agent may search and read the web.
	 */
	private bool $toolsGranted = true;

	/**
	 * Seconds that pass with every fetch.
	 */
	private int $secondsPerFetch = 1;

	/**
	 * The clock.
	 */
	private int $now = self::START;

	/**
	 * A closure run after the n-th fetch, keyed by n.
	 *
	 * @var array<int, \Closure>
	 */
	private array $afterFetch = [];

	/**
	 * @var BudgetService&MockObject
	 */
	private BudgetService&MockObject $budgets;

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $controls;

	/**
	 * @var ReportWriter&MockObject
	 */
	private ReportWriter&MockObject $writer;

	/**
	 * The markdown the writer was asked to save, null when it was not asked.
	 */
	private ?string $written = null;

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
		$this->results = [
			['title' => 'Register Amsterdam', 'url' => 'https://a.example/1'],
			['title' => 'Register Utrecht', 'url' => 'https://a.example/2'],
		];
		$this->answers = [
			"1. Welke gemeenten?\n2. Wat publiceren ze?\n3. Sinds wanneer?",
			"Amsterdam publiceert [1: \"page one says the register is public\"].\nUtrecht ook [2: \"a passage nobody wrote\"].",
		];

		$this->budgets = $this->createMock(BudgetService::class);
		$this->budgets->method('isBlocked')->willReturn(false);
		$this->controls = $this->createMock(ObjectService::class);
		$this->controls->method('setRegister')->willReturnSelf();
		$this->controls->method('setSchema')->willReturnSelf();
		$this->controls->method('findAll')->willReturn([]);

		$this->writer = $this->getMockBuilder(ReportWriter::class)->disableOriginalConstructor()->onlyMethods(['write'])->getMock();
		$this->writer->method('write')->willReturnCallback(function (string $userId, string $title, string $markdown): array {
			$this->events[] = 'write';
			$this->written = $markdown;
			return ['fileId' => 7, 'name' => '2025-11-21 Report.md', 'path' => 'Hermiq/Onderzoek/2025-11-21 Report.md', 'url' => 'https://nc/f/7'];
		});
	}

	public function testThePlanIsPostedBeforeAnySearchAndTheReportFollows(): void {
		self::assertSame('done', $this->runner()->run('r-1'));

		self::assertStringStartsWith('message:**Research plan**', $this->events[1]);
		self::assertSame('model', $this->events[0]);
		self::assertStringStartsWith('search:', $this->events[2]);
		self::assertSame("**Research plan**\n\n1. Welke gemeenten?\n2. Wat publiceren ze?\n3. Sinds wanneer?", $this->posted[0]);
		self::assertSame(['Welke gemeenten?', 'Wat publiceren ze?', 'Sinds wanneer?'], $this->handoff['plan']);
		self::assertSame('done', $this->handoff['status']);
		self::assertSame('done', $this->handoff['progress']['phase']);
	}

	public function testEverySearchAndFetchGoesThroughTheToolboxAndEveryPageIsNumbered(): void {
		$this->runner()->run('r-1');

		// Three sub-questions, each one search; the same two URLs keep their first number.
		self::assertCount(3, array_filter($this->events, static fn (string $event): bool => str_starts_with($event, 'search:')));
		self::assertCount(6, array_filter($this->events, static fn (string $event): bool => str_starts_with($event, 'fetch:')));
		self::assertCount(2, $this->handoff['ledger']);
		self::assertSame(1, $this->handoff['ledger'][0]['number']);
		self::assertSame('https://a.example/1', $this->handoff['ledger'][0]['url']);
		self::assertSame('Register Amsterdam', $this->handoff['ledger'][0]['title']);
		self::assertSame(9, $this->handoff['progress']['toolCalls']);
	}

	public function testARefusedFetchIsNotInTheLedger(): void {
		$this->fetches['https://a.example/2'] = ['error' => 'egress_denied'];

		$this->runner()->run('r-1');

		self::assertCount(1, $this->handoff['ledger']);
		self::assertSame('https://a.example/1', $this->handoff['ledger'][0]['url']);
	}

	public function testEveryCitationIsCheckedAndTheMessageCountsTheUnverified(): void {
		$this->runner()->run('r-1');

		self::assertStringContainsString('[1]', (string)$this->written);
		self::assertStringContainsString('(unverified)', (string)$this->written);
		self::assertStringContainsString('## Sources', (string)$this->written);
		self::assertStringContainsString('2 sources, 2 citations checked, 1 unverified', end($this->posted));
		self::assertStringContainsString('(https://nc/f/7)', end($this->posted));
		self::assertSame(['report', 'sources', 'checked', 'unverified'], array_keys($this->handoff['result']));
		self::assertSame(1, $this->handoff['result']['unverified']);
	}

	public function testTheSourceLimitStopsGatheringAndHeadsTheReport(): void {
		$this->handoff['caps']['sources'] = 1;

		self::assertSame('done', $this->runner()->run('r-1'));

		self::assertCount(1, array_filter($this->events, static fn (string $event): bool => str_starts_with($event, 'fetch:')));
		self::assertStringStartsWith('This report stopped early: the source limit of 1 was reached.', (string)$this->written);
	}

	public function testTheTimeLimitStopsGathering(): void {
		$this->handoff['caps']['minutes'] = 1;
		$this->secondsPerFetch = 61;

		$this->runner()->run('r-1');

		self::assertCount(1, array_filter($this->events, static fn (string $event): bool => str_starts_with($event, 'fetch:')));
		self::assertStringStartsWith('This report stopped early: the time limit of 1 minutes was reached.', (string)$this->written);
	}

	public function testAStopEndsTheRunAtItsNextStepWithoutAReport(): void {
		$this->afterFetch[1] = function (): void {
			$this->handoff['stopRequested'] = true;
		};

		self::assertSame('ended', $this->runner()->run('r-1'));

		self::assertCount(1, array_filter($this->events, static fn (string $event): bool => str_starts_with($event, 'fetch:')));
		self::assertNull($this->written);
		self::assertSame('Research stopped.', end($this->posted));
		self::assertSame('failed', $this->handoff['status']);
		self::assertSame('Research stopped.', $this->handoff['result']['reason']);
	}

	public function testAnEngagedKillSwitchEndsTheRunBeforeTheFirstModelCall(): void {
		$engaged = $this->createMock(ObjectEntity::class);
		$engaged->method('getObject')->willReturn(['engaged' => true]);
		$engaged->method('getOrganisation')->willReturn('org-1');
		$this->controls = $this->createMock(ObjectService::class);
		$this->controls->method('setRegister')->willReturnSelf();
		$this->controls->method('setSchema')->willReturnSelf();
		$this->controls->method('findAll')->willReturn([$engaged]);

		self::assertSame('ended', $this->runner()->run('r-1'));

		self::assertNotContains('model', $this->events);
		self::assertSame([ResearchGate::KILL_SWITCH], $this->posted);
	}

	public function testABudgetAtItsCapBeforeTheReportEndsTheRunWithoutAReport(): void {
		$this->budgets = $this->createMock(BudgetService::class);
		$this->budgets->method('isBlocked')->willReturnOnConsecutiveCalls(false, true);

		self::assertSame('ended', $this->runner()->run('r-1'));

		self::assertSame(['model'], array_values(array_filter($this->events, static fn (string $event): bool => $event === 'model')));
		self::assertNull($this->written);
		self::assertSame(ResearchGate::BUDGET, end($this->posted));
	}

	public function testAnAgentThatMayNotSearchAndReadTheWebEndsTheRun(): void {
		$this->toolsGranted = false;

		self::assertSame('ended', $this->runner()->run('r-1'));

		self::assertSame(ResearchRunner::NO_TOOLS, end($this->posted));
		self::assertNull($this->written);
	}

	public function testAFailedMarkFailsTheRun(): void {
		$this->writer = $this->getMockBuilder(ReportWriter::class)->disableOriginalConstructor()->onlyMethods(['write'])->getMock();
		$this->writer->method('write')->willThrowException(new RuntimeException(ReportWriter::NOT_MARKED));

		self::assertSame('ended', $this->runner()->run('r-1'));

		self::assertSame(ReportWriter::NOT_MARKED, end($this->posted));
		self::assertSame('failed', $this->handoff['status']);
	}

	public function testAModelFailureEndsTheRunWithoutAReport(): void {
		$this->answers = [];

		self::assertSame('ended', $this->runner()->run('r-1'));

		self::assertSame([ResearchRunner::FAILED], $this->posted);
		self::assertSame('failed', $this->handoff['status']);
	}

	public function testAHandoffThatIsNotAWaitingResearchRunIsSkipped(): void {
		$this->handoff['kind'] = 'flow';
		self::assertSame('skipped', $this->runner()->run('r-1'));

		$this->handoff['kind'] = 'research';
		$this->handoff['status'] = 'done';
		self::assertSame('skipped', $this->runner()->run('r-1'));

		self::assertSame('skipped', $this->runner()->run('gone'));
		self::assertSame([], $this->events);
	}

	public function testTheAgentsPromptLeadsEveryModelCall(): void {
		$prompts = [];
		$this->runner(prompts: $prompts)->run('r-1');

		self::assertCount(2, $prompts);
		self::assertStringStartsWith('Schrijf zakelijk.', $prompts[0]);
		self::assertStringStartsWith('Schrijf zakelijk.', $prompts[1]);
		self::assertStringContainsString('[1] Register Amsterdam <https://a.example/1>', $prompts[1]);
	}

	/**
	 * The runner over the fakes of this test.
	 *
	 * @param list<string> $prompts Collects the model prompts.
	 *
	 * @return ResearchRunner
	 */
	private function runner(array &$prompts = []): ResearchRunner {
		$handoffs = $this->createMock(HandoffService::class);
		$handoffs->method('find')->willReturnCallback(fn (string $id): ?array => ($id === 'r-1') ? $this->handoff : null);
		$handoffs->method('update')->willReturnCallback(function (string $id, array $fields): bool {
			$this->handoff = array_merge($this->handoff, $fields);
			return true;
		});
		$handoffs->method('close')->willReturnCallback(function (string $id, string $status, mixed $result): bool {
			$this->handoff['status'] = $status;
			$this->handoff['result'] = $result;
			return true;
		});

		$messages = $this->createMock(MessageHistoryHandlerInterface::class);
		$messages->method('storeMessage')->willReturnCallback(function (string $conversationId, string $role, string $content): ObjectEntity {
			$this->events[] = 'message:' . $content;
			$this->posted[] = $content;
			return $this->createMock(ObjectEntity::class);
		});

		$agent = $this->createMock(ObjectEntity::class);
		$agent->method('getObject')->willReturn(['prompt' => 'Schrijf zakelijk.']);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($agent);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new ResearchRunner(
			handoffs: $handoffs,
			model: $this->model(prompts: $prompts),
			tools: $this->tools(),
			gate: new ResearchGate($this->budgets, new TenantKillSwitch($this->controls, new NullLogger())),
			writer: $this->writer,
			messages: $messages,
			objectService: $objects,
			time: $time,
			logger: new NullLogger()
		);
	}

	/**
	 * A model that answers the queued answers in order and fails when none is left.
	 *
	 * @param list<string> $prompts Collects the prompts.
	 *
	 * @return ResearchModel
	 */
	private function model(array &$prompts): ResearchModel {
		$test = $this;
		return new class($test, $prompts) implements ResearchModel {
			public function __construct(private readonly ResearchRunnerTest $test, private array &$prompts) {
			}

			public function generate(string $prompt, string $userId, string $organisation): string {
				$this->prompts[] = $prompt;
				return $this->test->nextAnswer();
			}
		};
	}

	/**
	 * The next queued model answer.
	 *
	 * @return string
	 */
	public function nextAnswer(): string {
		$this->events[] = 'model';
		if ($this->answers === []) {
			throw new RuntimeException('provider down');
		}

		return array_shift($this->answers);
	}

	/**
	 * Tools that open a recording toolbox when granted.
	 *
	 * @return ResearchTools
	 */
	private function tools(): ResearchTools {
		$test = $this;
		return new class($test) implements ResearchTools {
			public function __construct(private readonly ResearchRunnerTest $test) {
			}

			public function open(string $agentId, string $organisation, string $conversationId): ?ResearchToolbox {
				return $this->test->toolbox(agentId: $agentId);
			}
		};
	}

	/**
	 * The recording toolbox, or null when the agent was not granted the tools.
	 *
	 * @param string $agentId The agent the run opened it for.
	 *
	 * @return ResearchToolbox|null
	 */
	public function toolbox(string $agentId): ?ResearchToolbox {
		self::assertSame('agent-1', $agentId);
		if ($this->toolsGranted === false) {
			return null;
		}

		$test = $this;
		return new class($test) implements ResearchToolbox {
			public function __construct(private readonly ResearchRunnerTest $test) {
			}

			public function search(string $query): array {
				return $this->test->searched(query: $query);
			}

			public function fetch(string $url): array {
				return $this->test->fetched(url: $url);
			}
		};
	}

	/**
	 * Record a search and answer the results.
	 *
	 * @param string $query The query.
	 *
	 * @return list<array{title: string, url: string}>
	 */
	public function searched(string $query): array {
		$this->events[] = 'search:' . $query;
		return $this->results;
	}

	/**
	 * Record a fetch, let time pass, and answer the page.
	 *
	 * @param string $url The URL.
	 *
	 * @return array<string, mixed>
	 */
	public function fetched(string $url): array {
		$this->events[] = 'fetch:' . $url;
		$this->now += $this->secondsPerFetch;
		$count = count(array_filter($this->events, static fn (string $event): bool => str_starts_with($event, 'fetch:')));
		if (isset($this->afterFetch[$count]) === true) {
			($this->afterFetch[$count])();
		}

		if (isset($this->fetches[$url]) === true) {
			return $this->fetches[$url];
		}

		$text = ($url === 'https://a.example/1') ? 'page one says the register is public' : 'page two lists Utrecht';
		return ['url' => $url, 'content' => WebFetchService::UNTRUSTED_BEGIN . "\nSource: {$url}\n\n{$text}\n" . WebFetchService::UNTRUSTED_END];
	}
}
