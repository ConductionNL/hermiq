<?php

/**
 * A human approval gate must be able to say "I could not find what I am gating".
 *
 * Every test here pins one of the three answers this node has to keep apart, because
 * two of them look identical from outside a run:
 *
 *   found       the label is on the issue. Continue.
 *   not yet     nobody has applied it. SUSPEND, and ask again later.
 *   cannot tell the step does not know which issue to watch, or the read failed.
 *
 * Collapsing "cannot tell" into "found" is what actually happened: a repo/issue
 * template that resolved to nothing was passed through, the gate waiting for a
 * person's `accepted` label was satisfied in a single pass, and the run branched a
 * repository nobody had approved. Collapsing it into "never coming" is the mirror
 * defect — an outage or a rate limit deciding that an issue was never approved.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Flow;

use OCA\Hermiq\Flow\GitHubAwaitLabelNode;
use OCA\Hermiq\Service\GitHubBroker;
use OCA\Hermiq\Tests\Support\OpenRegisterFlowClasses;
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

// The node names seven OpenRegister flow symbols that tests/bootstrap.php resolves
// to stub files that do not exist, so without this the class cannot be autoloaded
// at all. Must run before the first use of the node, hence file scope.
OpenRegisterFlowClasses::register();

/**
 * @covers \OCA\Hermiq\Flow\GitHubAwaitLabelNode
 */
final class GitHubAwaitLabelNodeTest extends TestCase {

	/**
	 * Build the node over a broker that answers, or fails, as told.
	 *
	 * @param array|\Throwable|null $issue What `getIssue()` does — the issue it
	 *                                     returns, or the throwable it raises. Null
	 *                                     means the broker is never expected to be
	 *                                     asked.
	 *
	 * @return GitHubAwaitLabelNode The node under test.
	 */
	private function nodeReading($issue = null): GitHubAwaitLabelNode {
		$broker = $this->createMock(originalClassName: GitHubBroker::class);
		if ($issue instanceof \Throwable === true) {
			$broker->method('getIssue')->willThrowException($issue);
		} elseif (is_array($issue) === true) {
			$broker->method('getIssue')->willReturn($issue);
		} else {
			$broker->expects($this->never())->method('getIssue');
		}

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new GitHubAwaitLabelNode(
			$broker,
			$l10n,
			$this->createMock(originalClassName: IURLGenerator::class)
		);

	}//end nodeReading()

	/**
	 * One item carrying an issue number, ready to be walked.
	 *
	 * @param array $json Extra record fields.
	 *
	 * @return array The item list.
	 */
	private function oneItem(array $json = []): array {
		return [['json' => (['issueNumber' => 42] + $json), 'binary' => []]];
	}//end oneItem()

	/**
	 * A workable step configuration.
	 *
	 * @param array $overrides Keys to replace.
	 *
	 * @return array The configuration.
	 */
	private function config(array $overrides = []): array {
		return ($overrides + [
			'repo' => 'ConductionNL/hydra',
			'issue' => '{{issueNumber}}',
			'label' => 'accepted',
		]);
	}//end config()

	/**
	 * The editor and the registry see exactly the seven keys the form offers.
	 *
	 * A key the form renders but `configKeys()` omits is dropped on save, so the
	 * author sets a deadline, the step stores nothing, and the wait runs on the
	 * default — invisibly, because the field still shows what was typed.
	 *
	 * @return void
	 */
	public function testConfigKeysAreExactlyTheDocumentedSeven(): void {
		$node = $this->nodeReading();

		$this->assertSame(
			['repo', 'issue', 'label', 'heartbeatMinutes', 'timeoutMinutes', 'output', 'onTimeout'],
			$node->configKeys()
		);
		$this->assertSame('hermiq.github-await-label', $node->getId());

	}//end testConfigKeysAreExactlyTheDocumentedSeven()

	/**
	 * Every field the form renders is a key the step will actually keep.
	 *
	 * The pair, not either half, is the property worth having: a list of seven that
	 * matches nothing on the canvas is just as wrong as a missing key.
	 *
	 * @return void
	 */
	public function testEveryFormFieldIsAnAcceptedConfigKey(): void {
		$node = $this->nodeReading();

		$this->assertSame(
			$node->configKeys(),
			array_map(static fn (array $field): string => (string)$field['key'], $node->configForm())
		);

	}//end testEveryFormFieldIsAnAcceptedConfigKey()

	/**
	 * A wait that cannot name what it waits for is refused at save time.
	 *
	 * @param string $missing The key left blank.
	 *
	 * @return void
	 *
	 * @dataProvider provideRequiredKeys
	 */
	public function testValidateConfigRefusesAWaitThatNamesNothing(string $missing): void {
		$node = $this->nodeReading();
		$config = $this->config();
		unset($config[$missing]);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('"' . $missing . '"');

		$node->validateConfig($config);

	}//end testValidateConfigRefusesAWaitThatNamesNothing()

	/**
	 * The three keys a wait cannot do without.
	 *
	 * @return array<string,array<int,string>> The cases.
	 */
	public static function provideRequiredKeys(): array {
		return ['repo' => ['repo'], 'issue' => ['issue'], 'label' => ['label']];
	}//end provideRequiredKeys()

	/**
	 * POSITIVE CONTROL: a complete configuration is accepted.
	 *
	 * Without this a `validateConfig()` that threw unconditionally would pass all
	 * three cases above and refuse every valid step.
	 *
	 * @return void
	 */
	public function testValidateConfigAcceptsACompleteWait(): void {
		$node = $this->nodeReading();

		$node->validateConfig($this->config());

		$this->addToAssertionCount(1);

	}//end testValidateConfigAcceptsACompleteWait()

	/**
	 * The label is matched without regard to case, and the result lands on the item.
	 *
	 * GitHub labels are typed by people, so `Accepted` and `accepted` are the same
	 * approval. A case-sensitive match would suspend forever on a label that is
	 * visibly there, which reads as "nobody has approved it yet".
	 *
	 * @return void
	 */
	public function testAnIssueCarryingTheLabelContinuesAndPublishesTheResult(): void {
		$node = $this->nodeReading(
			['labels' => ['Accepted', 'enhancement'], 'title' => 'Add the thing', 'state' => 'open']
		);

		$out = $node->execute($this->oneItem(), $this->config(['output' => 'gate']), []);

		$this->assertTrue($out[0]['json']['gate']['found']);
		$this->assertSame('Add the thing', $out[0]['json']['gate']['title']);
		$this->assertSame(42, $out[0]['json']['issueNumber']);

	}//end testAnIssueCarryingTheLabelContinuesAndPublishesTheResult()

	/**
	 * No label yet suspends the run rather than continuing past the gate.
	 *
	 * @return void
	 */
	public function testAnIssueWithoutTheLabelSuspends(): void {
		$node = $this->nodeReading(['labels' => ['enhancement'], 'title' => 'Add the thing', 'state' => 'open']);

		$this->expectException(FlowSuspension::class);

		$node->execute($this->oneItem(), $this->config(), []);

	}//end testAnIssueWithoutTheLabelSuspends()

	/**
	 * 🔴 A TEMPLATE THAT RESOLVES TO NOTHING STOPS THE RUN AND NAMES ITSELF.
	 *
	 * This is the load-bearing one. The first version wrote "no issue on this item"
	 * onto the record and carried on, so a gate configured with a template the item
	 * does not carry was satisfied without ever reading GitHub. The run then branched
	 * a repository and wrote code nobody had approved, and looked healthy throughout.
	 *
	 * The message has to carry the TEMPLATE, not just the empty value it produced:
	 * an author reading "issue resolved to empty" cannot tell which of the step's
	 * fields is wrong.
	 *
	 * @param array $config The broken configuration.
	 * @param string $template The template text the message must name.
	 *
	 * @return void
	 *
	 * @dataProvider provideUnresolvableTargets
	 */
	public function testATemplateResolvingToNothingStopsTheRun(array $config, string $template): void {
		$node = $this->nodeReading();

		try {
			$node->execute($this->oneItem(), $this->config($config), []);
			$this->fail('a gate that cannot find its issue must stop, not pass the item through');
		} catch (FlowStop $stop) {
			$this->assertStringContainsString($template, $stop->getMessage());
			$this->assertTrue($stop->isError());
		}

	}//end testATemplateResolvingToNothingStopsTheRun()

	/**
	 * The two ways a step can fail to name its issue.
	 *
	 * @return array<string,array{0:array<string,string>,1:string}> The cases.
	 */
	public static function provideUnresolvableTargets(): array {
		return [
			'repo' => [['repo' => '{{repoThatIsNotOnTheItem}}'], '{{repoThatIsNotOnTheItem}}'],
			'issue' => [['issue' => '{{issueThatIsNotOnTheItem}}'], '{{issueThatIsNotOnTheItem}}'],
		];
	}//end provideUnresolvableTargets()

	/**
	 * 🔴 A FAILED READ IS "NOT YET", NEVER "NEVER".
	 *
	 * A rate limit, a revoked token or a GitHub outage must not be able to decide
	 * that an issue was never approved. The only safe reading of a failed read is
	 * that the answer is still unknown, so the wait continues.
	 *
	 * The broker's throwable must also not escape: a run that died on a transient
	 * read has lost a human's approval to an outage just as surely as one that
	 * concluded the approval never came.
	 *
	 * @return void
	 */
	public function testAFailedReadSuspendsRatherThanDecidingTheLabelWillNeverCome(): void {
		$node = $this->nodeReading(new RuntimeException('403 rate limit exceeded'));

		$this->expectException(FlowSuspension::class);

		$node->execute($this->oneItem(), $this->config(), []);

	}//end testAFailedReadSuspendsRatherThanDecidingTheLabelWillNeverCome()

	/**
	 * A failed read does not become a timeout either, even with `onTimeout: fail`.
	 *
	 * `onTimeout: fail` is the author saying "give up when the DEADLINE passes".
	 * Reaching it because a read failed turns every transient GitHub error into a
	 * refused approval, which is the same wrong answer as the test above arriving
	 * by the configured route.
	 *
	 * @return void
	 */
	public function testAFailedReadIsNotTreatedAsATimeoutFailure(): void {
		$node = $this->nodeReading(new RuntimeException('502 bad gateway'));

		$this->expectException(FlowSuspension::class);

		$node->execute($this->oneItem(), $this->config(['onTimeout' => 'fail']), []);

	}//end testAFailedReadIsNotTreatedAsATimeoutFailure()
}//end class
