<?php

/**
 * Unit tests for GitHubTool — the surface a model sees.
 *
 * The tool is a dispatcher, so these tests are about the two things a dispatcher
 * can get wrong in a way nothing else catches: the declared surface drifting from
 * what `executeFunction()` actually answers to, and an error leaving here as an
 * exception. The second is deliberate behaviour and not defensive habit — the
 * caller is a model's tool loop, where a thrown exception ends the turn while
 * `['success' => false, 'error' => ...]` lets the model read what went wrong and
 * try something else.
 *
 * The broker is doubled with `createMock()` / `onlyMethods()` and never with
 * `addMethods()`: a double carrying a method the real class lacks can only ever
 * pass, which is how a green suite once covered a call that 500s in production.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Tool
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Tool;

use OCA\Hermiq\Service\GitHubBroker;
use OCA\Hermiq\Tool\GitHubTool;
use OCA\OpenRegister\Tool\ToolInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for {@see GitHubTool}.
 */
final class GitHubToolTest extends TestCase {

	/**
	 * Every function name the tool declares, in the order it declares them.
	 *
	 * @var array<int,string>
	 */
	private const DECLARED = [
		'github_create_issue',
		'github_get_issue',
		'github_comment_issue',
		'github_create_branch',
		'github_get_file',
		'github_list_files',
		'github_put_file',
		'github_create_pull_request',
		'github_compare',
	];

	/**
	 * The tool is what the registry expects, so it can be registered at all.
	 *
	 * @return void
	 */
	public function testItIsAnOpenRegisterTool(): void {
		$tool = new GitHubTool($this->createMock(GitHubBroker::class));

		$this->assertInstanceOf(ToolInterface::class, $tool);
		$this->assertSame('GitHub', $tool->getName());
		$this->assertNotSame('', $tool->getDescription());
	}//end testItIsAnOpenRegisterTool()

	/**
	 * The declared surface is exactly the nine documented functions.
	 *
	 * @return void
	 */
	public function testGetFunctionsDeclaresTheNineDocumentedFunctions(): void {
		$functions = (new GitHubTool($this->createMock(GitHubBroker::class)))->getFunctions();

		$this->assertCount(9, $functions);
		$this->assertSame(self::DECLARED, array_column($functions, 'name'));
	}//end testGetFunctionsDeclaresTheNineDocumentedFunctions()

	/**
	 * Every declaration is complete enough for a model to call it.
	 *
	 * A missing description or an empty `properties` map is not a validation error
	 * anywhere: the function simply gets called with arguments nobody described.
	 *
	 * @return void
	 */
	public function testEveryDeclaredFunctionCarriesADescribedObjectSchema(): void {
		$functions = (new GitHubTool($this->createMock(GitHubBroker::class)))->getFunctions();

		foreach ($functions as $function) {
			$name = (string)($function['name'] ?? '');

			$this->assertNotSame('', $name, 'Every function must be named');
			$this->assertArrayHasKey('description', $function, $name . ' must carry a description');
			$this->assertNotSame('', $function['description'], $name . ' must carry a description');
			$this->assertArrayHasKey('parameters', $function, $name . ' must carry a parameter schema');

			$parameters = $function['parameters'];
			$this->assertSame('object', $parameters['type'] ?? null, $name . ' must take an object');
			$this->assertIsArray($parameters['properties'] ?? null, $name . ' must describe its properties');
			$this->assertNotSame([], $parameters['properties'], $name . ' must describe at least one property');
			$this->assertArrayHasKey('repo', $parameters['properties'], $name . ' must take an explicit repo');

			foreach ($parameters['properties'] as $property => $schema) {
				$this->assertIsArray($schema, $name . '.' . $property . ' must be a schema');
				$this->assertArrayHasKey('type', $schema, $name . '.' . $property . ' must declare a type');
				$this->assertArrayHasKey('description', $schema, $name . '.' . $property . ' must be described');
			}
		}
	}//end testEveryDeclaredFunctionCarriesADescribedObjectSchema()

	/**
	 * Every declared function is one `executeFunction()` actually answers to.
	 *
	 * The two halves are written apart, so nothing but this comparison stops a
	 * declared function from dispatching to `default` and telling the model it does
	 * not exist.
	 *
	 * @return void
	 */
	public function testEveryDeclaredFunctionIsDispatched(): void {
		$tool = new GitHubTool($this->createMock(GitHubBroker::class));

		foreach (self::DECLARED as $name) {
			$result = $tool->executeFunction($name, ['repo' => 'acme/demo'], 'alice');

			$this->assertStringNotContainsStringIgnoringCase(
				'unknown function',
				(string)($result['error'] ?? ''),
				$name . ' is declared but not dispatched'
			);
		}
	}//end testEveryDeclaredFunctionIsDispatched()

	/**
	 * An unknown function is an answer the model can read, not an exception.
	 *
	 * @return void
	 */
	public function testAnUnknownFunctionIsReportedRatherThanThrown(): void {
		$broker = $this->createMock(GitHubBroker::class);
		$broker->expects($this->never())->method($this->anything());

		$result = (new GitHubTool($broker))->executeFunction('github_delete_repo', ['repo' => 'acme/demo'], 'alice');

		$this->assertSame(false, $result['success']);
		$this->assertStringContainsString('github_delete_repo', (string)$result['error']);
	}//end testAnUnknownFunctionIsReportedRatherThanThrown()

	/**
	 * A missing repo is refused here, before anything is dialled.
	 *
	 * @return void
	 */
	public function testAMissingRepoIsRefusedWithoutCallingTheBroker(): void {
		$broker = $this->createMock(GitHubBroker::class);
		$broker->expects($this->never())->method($this->anything());

		$result = (new GitHubTool($broker))->executeFunction('github_create_issue', ['title' => 'A title'], 'alice');

		$this->assertSame(false, $result['success']);
		$this->assertStringContainsString('repo', (string)$result['error']);
	}//end testAMissingRepoIsRefusedWithoutCallingTheBroker()

	/**
	 * A whitespace-only repo is as absent as no repo at all.
	 *
	 * @return void
	 */
	public function testABlankRepoIsTreatedAsMissing(): void {
		$broker = $this->createMock(GitHubBroker::class);
		$broker->expects($this->never())->method($this->anything());

		$result = (new GitHubTool($broker))->executeFunction('github_get_issue', ['repo' => '   '], null);

		$this->assertSame(false, $result['success']);
	}//end testABlankRepoIsTreatedAsMissing()

	/**
	 * A broker refusal comes back as a result, not as a thrown exception.
	 *
	 * This is the deliberate one. The caller is a tool loop: an exception ends the
	 * turn, whereas the error text goes back to the model, which can correct a
	 * branch name it invented and try again.
	 *
	 * @return void
	 */
	public function testABrokerRefusalIsReturnedRatherThanThrown(): void {
		$broker = $this->getMockBuilder(GitHubBroker::class)
			->disableOriginalConstructor()
			->onlyMethods(['getIssue'])
			->getMock();
		$broker->method('getIssue')->willThrowException(
			new RuntimeException('GitHub refused GET /repos/acme/demo/issues/7 (HTTP 404): Not Found')
		);

		$result = (new GitHubTool($broker))->executeFunction('github_get_issue', ['repo' => 'acme/demo', 'number' => 7], 'alice');

		$this->assertSame(
			[
				'success' => false,
				'error' => 'GitHub refused GET /repos/acme/demo/issues/7 (HTTP 404): Not Found',
			],
			$result
		);
	}//end testABrokerRefusalIsReturnedRatherThanThrown()

	/**
	 * The acting user and the declared defaults reach the broker unchanged.
	 *
	 * @return void
	 */
	public function testTheDeclaredDefaultsAndTheActingUserReachTheBroker(): void {
		$broker = $this->getMockBuilder(GitHubBroker::class)
			->disableOriginalConstructor()
			->onlyMethods(['createPullRequest'])
			->getMock();
		$broker->expects($this->once())
			->method('createPullRequest')
			->with('acme/demo', 'feature-x', 'development', 'A title', 'A body', true, 'alice')
			->willReturn(['success' => true, 'number' => 5]);

		$result = (new GitHubTool($broker))->executeFunction(
			'github_create_pull_request',
			['repo' => 'acme/demo', 'head' => 'feature-x', 'title' => 'A title', 'body' => 'A body'],
			'alice'
		);

		$this->assertSame(5, $result['number']);
	}//end testTheDeclaredDefaultsAndTheActingUserReachTheBroker()

	/**
	 * Labels arrive at the broker as a list of strings, whatever shape they came in.
	 *
	 * A model passes a bare string as often as a list, and a JSON-encoded list about
	 * as often as either. All three are accepted, and blank entries are dropped
	 * rather than forwarded: GitHub answers a `""` label with a 422 that reads, to
	 * the model, like the issue itself being invalid.
	 *
	 * @param mixed $given    The labels argument as the model passed it.
	 * @param array $expected The list the broker must receive.
	 *
	 * @return void
	 *
	 * @dataProvider labelShapes
	 */
	public function testLabelsAreCoercedIntoAListOfStrings(mixed $given, array $expected): void {
		$captured = null;
		$broker = $this->getMockBuilder(GitHubBroker::class)
			->disableOriginalConstructor()
			->onlyMethods(['createIssue'])
			->getMock();
		$broker->method('createIssue')->willReturnCallback(
			static function (string $repo, string $title, string $body, array $labels, ?string $userId) use (&$captured): array {
				$captured = $labels;

				return ['success' => true, 'number' => 1];
			}
		);

		(new GitHubTool($broker))->executeFunction(
			'github_create_issue',
			['repo' => 'acme/demo', 'title' => 'A title', 'body' => 'A body', 'labels' => $given],
			'alice'
		);

		$this->assertSame($expected, $captured);
		$this->assertSame(array_values($expected), $captured, 'The broker must receive a list, not a map');
	}//end testLabelsAreCoercedIntoAListOfStrings()

	/**
	 * The shapes a model sends labels in.
	 *
	 * @return array<string,array{0:mixed,1:array<int,string>}> The cases.
	 */
	public static function labelShapes(): array {
		return [
			'a plain string' => ['bug', ['bug']],
			'a JSON-encoded list' => ['["bug","accepted"]', ['bug', 'accepted']],
			'a real array' => [['bug', 'accepted'], ['bug', 'accepted']],
			'padding and blanks' => [[' bug ', '', '   ', 'accepted'], ['bug', 'accepted']],
			'nothing at all' => [[], []],
			'an empty string' => ['', []],
			'a number' => [[7], ['7']],
		];
	}//end labelShapes()

	/**
	 * A labels argument that is neither string nor array is dropped, not forwarded.
	 *
	 * @return void
	 */
	public function testAnUnusableLabelsArgumentBecomesNoLabels(): void {
		$captured = null;
		$broker = $this->getMockBuilder(GitHubBroker::class)
			->disableOriginalConstructor()
			->onlyMethods(['createIssue'])
			->getMock();
		$broker->method('createIssue')->willReturnCallback(
			static function (string $repo, string $title, string $body, array $labels, ?string $userId) use (&$captured): array {
				$captured = $labels;

				return ['success' => true, 'number' => 1];
			}
		);

		(new GitHubTool($broker))->executeFunction(
			'github_create_issue',
			['repo' => 'acme/demo', 'title' => 'A title', 'body' => 'A body', 'labels' => 42],
			'alice'
		);

		$this->assertSame([], $captured);
	}//end testAnUnusableLabelsArgumentBecomesNoLabels()

	/**
	 * An empty function name is refused for its missing repo, not as an unknown name.
	 *
	 * The guard used to read `$repo === '' && $functionName !== ''`, so a call
	 * carrying neither fell past it into `default` and came back as "Unknown
	 * function ''". That points the model at the function name, which is the one
	 * part of the call it is least likely to have got wrong on its own, while the
	 * argument it actually left out goes unnamed.
	 *
	 * @return void
	 */
	public function testAnEmptyFunctionNameIsRefusedForItsMissingRepo(): void {
		$broker = $this->createMock(GitHubBroker::class);
		$broker->expects($this->never())->method($this->anything());

		$result = (new GitHubTool($broker))->executeFunction('', [], 'alice');

		$this->assertSame(false, $result['success']);
		$this->assertSame('repo is required, as owner/name.', $result['error']);
		$this->assertStringNotContainsStringIgnoringCase('unknown function', (string)$result['error']);
	}//end testAnEmptyFunctionNameIsRefusedForItsMissingRepo()

	/**
	 * An empty function name WITH a repo is still an unknown function.
	 *
	 * The pair matters: dropping the `$functionName !== ''` clause must move the
	 * blank-name-and-blank-repo case only, and leave the dispatcher answering for
	 * a name it genuinely does not know.
	 *
	 * @return void
	 */
	public function testAnEmptyFunctionNameWithARepoIsStillUnknown(): void {
		$broker = $this->createMock(GitHubBroker::class);
		$broker->expects($this->never())->method($this->anything());

		$result = (new GitHubTool($broker))->executeFunction('', ['repo' => 'acme/demo'], 'alice');

		$this->assertSame(false, $result['success']);
		$this->assertStringContainsStringIgnoringCase('unknown function', (string)$result['error']);
	}//end testAnEmptyFunctionNameWithARepoIsStillUnknown()
}//end class
