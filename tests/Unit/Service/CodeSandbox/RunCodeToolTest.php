<?php

/**
 * The runCode tool: its AI feature gate, the files it reads and its dispatch.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-an-agent-runs-code-only-with-a-grant-and-an-enabled-ai-feature-req-sandbox-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\CodeSandbox;

use InvalidArgumentException;
use OCA\Hermiq\Service\AiFeature\AiFeatureLookup;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxClient;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxSettings;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxUnavailableException;
use OCA\Hermiq\Service\CodeSandbox\RunCodeTool;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\CodeSandbox\RunCodeTool
 * @uses   \OCA\Hermiq\Mcp\CodeSandboxToolDescriptors
 * @uses   \OCA\Hermiq\Service\CodeSandbox\CodeSandboxSettings
 * @uses   \OCA\Hermiq\Service\CodeSandbox\CodeSandboxUnavailableException
 */
class RunCodeToolTest extends TestCase {

	private CodeSandboxClient&MockObject $client;
	private IRootFolder&MockObject $rootFolder;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->client = $this->createMock(CodeSandboxClient::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
	}//end setUp()

	/**
	 * The tool with the feature in the given state and the given stored backend.
	 *
	 * @param array<string, mixed>|null|false $feature The feature's data, null for absent, false for a failing lookup.
	 * @param string                          $stored  The stored sandbox choice.
	 *
	 * @return RunCodeTool
	 */
	private function tool(array|null|false $feature=['enabled' => true], string $stored=''): RunCodeTool {
		$features = $this->createMock(AiFeatureLookup::class);
		if ($feature === false) {
			$features->method('findBySlug')->willThrowException(new RuntimeException('register down'));
		} else {
			$features->method('findBySlug')->willReturnCallback(
				static function (string $slug) use ($feature): ?ObjectEntity {
					if ($slug !== RunCodeTool::FEATURE_SLUG || $feature === null) {
						return null;
					}

					$entity = new ObjectEntity();
					$entity->setObject($feature);
					return $entity;
				}
			);
		}

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($stored);

		return new RunCodeTool(
			features: $features,
			client: $this->client,
			settings: new CodeSandboxSettings(appConfig: $config),
			rootFolder: $this->rootFolder,
			logger: new NullLogger()
		);
	}//end tool()

	/**
	 * Spec scenario "The AI feature is off": disabled, absent or unreadable,
	 * nothing is sent and the stated message comes back.
	 *
	 * @return void
	 */
	public function testNothingIsSentWhileTheFeatureIsNotEnabled(): void {
		$this->client->expects($this->never())->method('run');
		$this->rootFolder->expects($this->never())->method('getUserFolder');
		$expected = ['error' => ['code' => 'code_execution_not_enabled', 'message' => 'Running code must be enabled as an AI feature before an agent can run code.']];
		$arguments = ['language' => 'python', 'code' => 'print(1)', 'files' => [['path' => 'a.csv']]];

		$this->assertSame($expected, $this->tool(feature: ['enabled' => false])->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: $arguments));
		$this->assertSame($expected, $this->tool(feature: ['enabled' => 'yes'])->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: $arguments));
		$this->assertSame($expected, $this->tool(feature: null)->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: $arguments));
		$this->assertSame($expected, $this->tool(feature: false)->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: $arguments));
	}//end testNothingIsSentWhileTheFeatureIsNotEnabled()

	/**
	 * Spec scenario "A policy officer asks for a calculation": the code goes to
	 * the sandbox as the acting user with the asked limits, and the result
	 * comes back with its language and the reach `self`.
	 *
	 * @return void
	 */
	public function testACalculationRunsInTheOwnSandbox(): void {
		$result = ['exitCode' => 0, 'stdout' => "44940\n", 'stderr' => '', 'durationMs' => 41, 'timedOut' => false, 'files' => [], 'limits' => ['wallSeconds' => 10, 'memoryMb' => 512]];
		$this->client->expects($this->once())->method('run')
			->with('python', 'print(round(1284000 * 0.035))', '', [], ['wallSeconds' => 10, 'memoryMb' => null], 'alice')
			->willReturn($result);

		$answer = $this->tool()->invoke(
			uid: 'alice',
			toolId: 'hermiq.runCode',
			arguments: ['language' => 'python', 'code' => 'print(round(1284000 * 0.035))', 'timeoutSeconds' => 10]
		);

		$this->assertSame($result + ['language' => 'python', 'reach' => 'self'], $answer);
	}//end testACalculationRunsInTheOwnSandbox()

	/**
	 * Files are read from the acting user's own folder, copied in by base name,
	 * and lift the reach to `user`.
	 *
	 * @return void
	 */
	public function testFilesAreReadAsTheActingUser(): void {
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(12);
		$file->method('getContent')->willReturn("a,b\n1,2\n");
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->with('Begroting/regels.csv')->willReturn(true);
		$folder->method('get')->with('Begroting/regels.csv')->willReturn($file);
		$this->rootFolder->expects($this->once())->method('getUserFolder')->with('alice')->willReturn($folder);
		$this->client->expects($this->once())->method('run')
			->with('python', 'x', 'in', [['name' => 'regels.csv', 'content' => "a,b\n1,2\n"]], $this->anything(), 'alice')
			->willReturn(['exitCode' => 0]);

		$answer = $this->tool()->invoke(
			uid: 'alice',
			toolId: 'hermiq.runCode',
			arguments: ['language' => 'python', 'code' => 'x', 'stdin' => 'in', 'files' => [['path' => 'Begroting/regels.csv']]]
		);

		$this->assertSame('user', $answer['reach']);
	}//end testFilesAreReadAsTheActingUser()

	/**
	 * At a hosted sandbox the reach is external, with or without files.
	 *
	 * @return void
	 */
	public function testAHostedChoiceReachesExternal(): void {
		$this->client->method('run')->willReturn(['exitCode' => 0]);

		$answer = $this->tool(stored: '{"backend":"hosted","hostedSource":"hosted-sandbox"}')
			->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: ['language' => 'node', 'code' => 'console.log(2)']);

		$this->assertSame('external', $answer['reach']);
	}//end testAHostedChoiceReachesExternal()

	/**
	 * A file outside the user's files, a folder, a too large file, a bad list
	 * and an empty path are refused before anything is sent.
	 *
	 * @return void
	 */
	public function testBadFilesAreRefusedBeforeDispatch(): void {
		$folderNode = $this->createMock(Folder::class);
		$big = $this->createMock(File::class);
		$big->method('getSize')->willReturn(RunCodeTool::MAX_FILE_BYTES + 1);
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturnCallback(static fn (string $path): bool => $path !== 'missing.txt');
		$folder->method('get')->willReturnCallback(static fn (string $path): object => $path === 'big.txt' ? $big : $folderNode);
		$this->rootFolder->method('getUserFolder')->willReturn($folder);
		$this->client->expects($this->never())->method('run');
		$tool = $this->tool();
		$call = static fn (mixed $files): array => $tool->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: ['language' => 'python', 'code' => 'x', 'files' => $files]);

		$this->assertSame('not_found', $call([['path' => 'missing.txt']])['error']['code']);
		$this->assertSame('not_a_file', $call([['path' => 'Documents']])['error']['code']);
		$this->assertSame('file_too_large', $call([['path' => 'big.txt']])['error']['code']);
		$this->assertSame('invalid_argument', $call([['path' => '/']])['error']['code']);
		$this->assertSame('invalid_argument', $call(['path' => 'a.txt'])['error']['code']);
		$this->assertSame('invalid_argument', $call(array_fill(0, 11, ['path' => 'a.txt']))['error']['code']);
		$this->assertSame('invalid_argument', $call('a.txt')['error']['code']);
	}//end testBadFilesAreRefusedBeforeDispatch()

	/**
	 * Empty code is refused; the client's refusals become envelopes.
	 *
	 * @return void
	 */
	public function testRefusalsAndFailuresAreEnvelopes(): void {
		$this->assertSame('invalid_argument', $this->tool()->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: ['language' => 'python', 'code' => '  '])['error']['code']);
		$this->assertSame('unknown_tool', $this->tool()->invoke(uid: 'alice', toolId: 'hermiq.other', arguments: [])['error']['code']);

		$this->client->method('run')->willReturnOnConsecutiveCalls(
			$this->throwException(new CodeSandboxUnavailableException(CodeSandboxUnavailableException::NOT_INSTALLED)),
			$this->throwException(new InvalidArgumentException('The sandbox runs python or node.')),
			$this->throwException(new RuntimeException('The code sandbox could not run the code (HTTP 500).'))
		);
		$tool = $this->tool();
		$arguments = ['language' => 'ruby', 'code' => 'puts 1'];

		$this->assertSame(['error' => ['code' => 'code_sandbox_unavailable', 'message' => 'No code sandbox is installed on this instance.']], $tool->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: $arguments));
		$this->assertSame(['error' => ['code' => 'invalid_argument', 'message' => 'The sandbox runs python or node.']], $tool->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: $arguments));
		$this->assertSame(['error' => ['code' => 'code_run_failed', 'message' => 'The code sandbox could not run the code (HTTP 500).']], $tool->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: $arguments));
	}//end testRefusalsAndFailuresAreEnvelopes()

	/**
	 * Files that cannot be opened are an envelope, not an exception.
	 *
	 * @return void
	 */
	public function testUnopenableFilesAreAnEnvelope(): void {
		$this->rootFolder->method('getUserFolder')->willThrowException(new RuntimeException('no storage'));
		$this->client->expects($this->never())->method('run');

		$answer = $this->tool()->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: ['language' => 'python', 'code' => 'x', 'files' => [['path' => 'a']]]);

		$this->assertSame(['error' => ['code' => 'not_found', 'message' => 'Your files could not be opened.']], $answer);
	}//end testUnopenableFilesAreAnEnvelope()
}//end class
