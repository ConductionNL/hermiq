<?php

/**
 * Unit tests for GitHubBroker — the surface GitHub sees.
 *
 * Everything here is driven through a scripted credential broker, which is the
 * only outside dependency the class has: OpenRegister's broker is resolved by
 * class-string out of the container, so a double placed in that container is the
 * whole seam. No HTTP is made, and none is mocked.
 *
 * What the tests are for is the part the class adds ON TOP of GitHub: label
 * objects flattened to names, an existing branch reported rather than recreated,
 * an existing blob SHA resolved before a write, a patch cut to a length a model
 * can still read, and a refusal that names the method, the path and the status
 * instead of a bare failure.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\GitHubBroker;
use OCA\Hermiq\Tests\Unit\Support\RecordingCredentialBroker;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Tests for {@see GitHubBroker}.
 */
final class GitHubBrokerTest extends TestCase {

	/**
	 * The scripted credential broker behind the last service built.
	 *
	 * @var RecordingCredentialBroker
	 */
	private RecordingCredentialBroker $github;

	/**
	 * Build the class under test over a scripted broker.
	 *
	 * @param array<string,array{status:int,body:mixed}> $routes Keyed `METHOD path`; anything unlisted answers 404.
	 * @param string $credentialId The configured credential uuid, empty for none.
	 *
	 * @return GitHubBroker The service, with its broker recorded on `$this->github`.
	 */
	private function service(array $routes = [], string $credentialId = 'cred-uuid'): GitHubBroker {
		$this->github = new RecordingCredentialBroker(
			static function (string $method, string $path) use ($routes): array {
				$route = $method . ' ' . $path;
				if (isset($routes[$route]) === false) {
					return ['status' => 404, 'body' => '{"message":"Not Found"}'];
				}

				$body = $routes[$route]['body'];

				return [
					'status' => $routes[$route]['status'],
					'body' => is_string($body) ? $body : (string)json_encode($body),
				];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		if ($credentialId === '') {
			// The credential guard runs BEFORE the container is touched, so a
			// container that refuses every lookup is the assertion.
			$container->expects($this->never())->method('get');
		} else {
			$container->method('get')->willReturnCallback(
				fn (string $id): mixed => ($id === CredentialBrokerService::class) ? $this->github : null
			);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string
				=> ($app === 'hermiq' && $key === 'github_credential') ? $credentialId : $default
		);

		return new GitHubBroker($container, $appConfig);
	}//end service()

	/**
	 * An issue is posted to the repository's issues path and answered with its number.
	 *
	 * @return void
	 */
	public function testCreateIssuePostsToTheIssuesPath(): void {
		$service = $this->service(
			[
				'POST /repos/acme/demo/issues' => [
					'status' => 201,
					'body' => ['number' => 42, 'html_url' => 'https://github.com/acme/demo/issues/42'],
				],
			]
		);

		$result = $service->createIssue('acme/demo', 'A title', 'A body', ['bug', 'accepted'], 'alice');

		$this->assertSame(true, $result['success']);
		$this->assertSame(42, $result['number']);
		$this->assertSame('https://github.com/acme/demo/issues/42', $result['html_url']);

		$this->assertSame(['POST /repos/acme/demo/issues'], $this->github->routes());
		$this->assertSame(
			['title' => 'A title', 'body' => 'A body', 'labels' => ['bug', 'accepted']],
			$this->github->decodedBody(0)
		);
	}//end testCreateIssuePostsToTheIssuesPath()

	/**
	 * The credential, the app id and the acting user all reach the broker.
	 *
	 * Those three are what the broker's own guards read, so sending the wrong one
	 * fails at a boundary this app cannot see.
	 *
	 * @return void
	 */
	public function testEveryCallCarriesTheCredentialTheAppIdAndTheActingUser(): void {
		$service = $this->service(['POST /repos/acme/demo/issues' => ['status' => 201, 'body' => ['number' => 1]]]);

		$service->createIssue('acme/demo', 'A title', 'A body', [], 'alice');

		$this->assertSame('cred-uuid', $this->github->calls[0]['credentialId']);
		$this->assertSame('hermiq', $this->github->calls[0]['appId']);
		$this->assertSame('alice', $this->github->calls[0]['userId']);
	}//end testEveryCallCarriesTheCredentialTheAppIdAndTheActingUser()

	/**
	 * No labels means no `labels` key, not an empty one.
	 *
	 * GitHub reads an empty array on create as "no labels", but sending a key the
	 * caller never set is how a later `labels: []` regression goes unnoticed.
	 *
	 * @return void
	 */
	public function testCreateIssueOmitsLabelsWhenThereAreNone(): void {
		$service = $this->service(['POST /repos/acme/demo/issues' => ['status' => 201, 'body' => ['number' => 7]]]);

		$service->createIssue('acme/demo', 'A title', 'A body', [], null);

		$this->assertArrayNotHasKey('labels', $this->github->decodedBody(0));
	}//end testCreateIssueOmitsLabelsWhenThereAreNone()

	/**
	 * The load-bearing one: label OBJECTS come back as a list of plain strings.
	 *
	 * A model asked whether an issue carries `accepted` reads a list of strings
	 * correctly far more often than a list of objects, so this flattening is the
	 * reason the method exists at all.
	 *
	 * @return void
	 */
	public function testGetIssueFlattensLabelObjectsIntoNames(): void {
		$service = $this->service(
			[
				'GET /repos/acme/demo/issues/7' => [
					'status' => 200,
					'body' => [
						'number' => 7,
						'title' => 'A title',
						'body' => 'A body',
						'state' => 'open',
						'html_url' => 'https://github.com/acme/demo/issues/7',
						'labels' => [['name' => 'accepted'], ['name' => 'bug']],
					],
				],
			]
		);

		$result = $service->getIssue('acme/demo', 7, 'alice');

		$this->assertSame(['accepted', 'bug'], $result['labels']);
		$this->assertSame(7, $result['number']);
		$this->assertSame('open', $result['state']);
		$this->assertSame(['GET /repos/acme/demo/issues/7'], $this->github->routes());
	}//end testGetIssueFlattensLabelObjectsIntoNames()

	/**
	 * Bare strings, blank names and padding all survive the same flattening.
	 *
	 * @return void
	 */
	public function testGetIssueDropsEmptyLabelsAndAcceptsBareStrings(): void {
		$service = $this->service(
			[
				'GET /repos/acme/demo/issues/7' => [
					'status' => 200,
					'body' => ['labels' => [['name' => ' accepted '], 'bug', ['name' => ''], '']],
				],
			]
		);

		$result = $service->getIssue('acme/demo', 7, null);

		$this->assertSame(['accepted', 'bug'], $result['labels']);
	}//end testGetIssueDropsEmptyLabelsAndAcceptsBareStrings()

	/**
	 * An existing branch is reported as success, and nothing is written.
	 *
	 * A resumed run reaches this twice, and the second pass must not fail on work
	 * the first pass already did. The assertion that matters is the absence of a
	 * POST: reporting `created => false` while still creating a ref would read
	 * identically in the return value.
	 *
	 * @return void
	 */
	public function testCreateBranchOnAnExistingBranchWritesNothing(): void {
		$service = $this->service(
			[
				'GET /repos/acme/demo/git/ref/heads/feature-x' => [
					'status' => 200,
					'body' => ['ref' => 'refs/heads/feature-x', 'object' => ['sha' => 'deadbeef']],
				],
			]
		);

		$result = $service->createBranch('acme/demo', 'feature-x', 'development', 'alice');

		$this->assertSame(true, $result['success']);
		$this->assertSame(false, $result['created']);
		$this->assertSame('feature-x', $result['branch']);
		$this->assertSame('deadbeef', $result['sha']);
		$this->assertNotContains('POST', $this->github->methods(), 'An existing branch must not be recreated');
		$this->assertSame(['GET /repos/acme/demo/git/ref/heads/feature-x'], $this->github->routes());
	}//end testCreateBranchOnAnExistingBranchWritesNothing()

	/**
	 * A new branch reads the base head and posts a ref carrying exactly that SHA.
	 *
	 * @return void
	 */
	public function testCreateBranchResolvesTheBaseHeadAndPostsTheRef(): void {
		$service = $this->service(
			[
				'GET /repos/acme/demo/git/ref/heads/development' => [
					'status' => 200,
					'body' => ['object' => ['sha' => 'basehead000']],
				],
				'POST /repos/acme/demo/git/refs' => [
					'status' => 201,
					'body' => ['ref' => 'refs/heads/feature-x', 'object' => ['sha' => 'basehead000']],
				],
			]
		);

		$result = $service->createBranch('acme/demo', 'feature-x', 'development', 'alice');

		$this->assertSame(true, $result['created']);
		$this->assertSame('basehead000', $result['sha']);
		$this->assertSame(
			[
				'GET /repos/acme/demo/git/ref/heads/feature-x',
				'GET /repos/acme/demo/git/ref/heads/development',
				'POST /repos/acme/demo/git/refs',
			],
			$this->github->routes()
		);
		$this->assertSame(
			['ref' => 'refs/heads/feature-x', 'sha' => 'basehead000'],
			$this->github->decodedBody(2)
		);
	}//end testCreateBranchResolvesTheBaseHeadAndPostsTheRef()

	/**
	 * An empty base means development, which is the fleet's branch.
	 *
	 * @return void
	 */
	public function testCreateBranchFallsBackToDevelopmentAsTheBase(): void {
		$service = $this->service(
			[
				'GET /repos/acme/demo/git/ref/heads/development' => [
					'status' => 200,
					'body' => ['object' => ['sha' => 'basehead000']],
				],
				'POST /repos/acme/demo/git/refs' => ['status' => 201, 'body' => ['object' => ['sha' => 'basehead000']]],
			]
		);

		$service->createBranch('acme/demo', 'feature-x', '', null);

		$this->assertContains('GET /repos/acme/demo/git/ref/heads/development', $this->github->routes());
	}//end testCreateBranchFallsBackToDevelopmentAsTheBase()

	/**
	 * A base whose head cannot be read is a refusal, not a branch off nothing.
	 *
	 * @return void
	 */
	public function testCreateBranchRefusesWhenTheBaseHeadIsEmpty(): void {
		$service = $this->service(
			['GET /repos/acme/demo/git/ref/heads/development' => ['status' => 200, 'body' => ['object' => []]]]
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Could not resolve the head of 'development'.");

		$service->createBranch('acme/demo', 'feature-x', 'development', null);
	}//end testCreateBranchRefusesWhenTheBaseHeadIsEmpty()

	/**
	 * Replacing a file sends the blob SHA it replaces, resolved here and not asked for.
	 *
	 * @return void
	 */
	public function testPutFileSendsTheExistingBlobShaOnAnUpdate(): void {
		$service = $this->service(
			[
				'GET /repos/acme/demo/contents/docs/readme.md?ref=feature-x' => [
					'status' => 200,
					'body' => ['path' => 'docs/readme.md', 'sha' => 'blob1234'],
				],
				'PUT /repos/acme/demo/contents/docs/readme.md' => [
					'status' => 200,
					'body' => ['commit' => ['sha' => 'commit999'], 'content' => ['html_url' => 'https://github.com/x']],
				],
			]
		);

		$result = $service->putFile('acme/demo', 'feature-x', 'docs/readme.md', 'hello', 'docs: a line', 'alice');

		$body = $this->github->decodedBody(1);
		$this->assertSame('blob1234', $body['sha']);
		$this->assertSame('feature-x', $body['branch']);
		$this->assertSame('docs: a line', $body['message']);
		$this->assertSame('commit999', $result['commit']);
		$this->assertSame('https://github.com/x', $result['html_url']);
	}//end testPutFileSendsTheExistingBlobShaOnAnUpdate()

	/**
	 * A path that does not exist yet carries no `sha` at all.
	 *
	 * GitHub rejects a create that names a blob SHA just as it rejects an update
	 * that omits one, so "absent" has to mean absent rather than empty.
	 *
	 * @return void
	 */
	public function testPutFileOmitsTheShaOnANewPath(): void {
		$service = $this->service(
			[
				'PUT /repos/acme/demo/contents/docs/new.md' => [
					'status' => 201,
					'body' => ['commit' => ['sha' => 'commit999']],
				],
			]
		);

		$result = $service->putFile('acme/demo', 'feature-x', 'docs/new.md', 'hello', 'docs: a line', null);

		$body = $this->github->decodedBody(1);
		$this->assertArrayNotHasKey('sha', $body, 'A create must not name a blob SHA');
		$this->assertSame(true, $result['success']);
		$this->assertSame('docs/new.md', $result['path']);
	}//end testPutFileOmitsTheShaOnANewPath()

	/**
	 * Content goes over the wire base64 encoded, which is the only shape GitHub takes.
	 *
	 * @return void
	 */
	public function testPutFileBase64EncodesTheContent(): void {
		$service = $this->service(
			['PUT /repos/acme/demo/contents/a.txt' => ['status' => 201, 'body' => ['commit' => ['sha' => 'c1']]]]
		);

		$service->putFile('acme/demo', 'feature-x', 'a.txt', "line one\nline two\n", 'chore: a file', null);

		$body = $this->github->decodedBody(1);
		$this->assertSame(base64_encode("line one\nline two\n"), $body['content']);
		$this->assertSame("line one\nline two\n", base64_decode($body['content'], true));
	}//end testPutFileBase64EncodesTheContent()

	/**
	 * A patch longer than the cap is cut, and says so.
	 *
	 * A diff large enough to matter is large enough to push the instruction out of
	 * the model's window, so the flag is the point: a silently unreviewed file is
	 * worse than one the report names as skipped.
	 *
	 * @return void
	 */
	public function testCompareTruncatesAnOversizedPatch(): void {
		$patch = str_repeat('a', 9000);
		$service = $this->service(
			[
				'GET /repos/acme/demo/compare/development...feature-x' => [
					'status' => 200,
					'body' => [
						'files' => [
							['filename' => 'big.php', 'status' => 'modified', 'additions' => 9, 'deletions' => 1, 'patch' => $patch],
						],
					],
				],
			]
		);

		$result = $service->compare('acme/demo', 'development', 'feature-x', 'alice');

		$file = $result['files'][0];
		$this->assertSame(true, $file['patchTruncated']);
		$this->assertSame(str_repeat('a', 8000) . "\n… patch truncated", $file['patch']);
		$this->assertSame('big.php', $file['filename']);
		$this->assertSame(1, $result['total']);
	}//end testCompareTruncatesAnOversizedPatch()

	/**
	 * A patch inside the cap is passed through untouched.
	 *
	 * @return void
	 */
	public function testCompareLeavesAShortPatchAlone(): void {
		$patch = "@@ -1 +1 @@\n-old\n+new";
		$service = $this->service(
			[
				'GET /repos/acme/demo/compare/development...feature-x' => [
					'status' => 200,
					'body' => [
						'files' => [
							['filename' => 'small.php', 'status' => 'modified', 'additions' => 1, 'deletions' => 1, 'patch' => $patch],
						],
					],
				],
			]
		);

		$result = $service->compare('acme/demo', 'development', 'feature-x', null);

		$this->assertSame(false, $result['files'][0]['patchTruncated']);
		$this->assertSame($patch, $result['files'][0]['patch']);
	}//end testCompareLeavesAShortPatchAlone()

	/**
	 * A refusal names the method, the path, the status and GitHub's own message.
	 *
	 * All four are what the model reads before it decides what to try next, and
	 * the last one is the only part carrying why.
	 *
	 * @return void
	 */
	public function testANonSuccessStatusRaisesARuntimeExceptionNamingTheCall(): void {
		$service = $this->service(
			[
				'POST /repos/acme/demo/issues' => [
					'status' => 422,
					'body' => ['message' => 'Validation Failed', 'documentation_url' => 'https://docs.github.com'],
				],
			]
		);

		try {
			$service->createIssue('acme/demo', 'A title', 'A body', [], 'alice');
			$this->fail('A 422 must not be reported as a created issue');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('POST', $e->getMessage());
			$this->assertStringContainsString('/repos/acme/demo/issues', $e->getMessage());
			$this->assertStringContainsString('422', $e->getMessage());
			$this->assertStringContainsString('Validation Failed', $e->getMessage());
		}
	}//end testANonSuccessStatusRaisesARuntimeExceptionNamingTheCall()

	/**
	 * A body that is not JSON is carried into the message verbatim.
	 *
	 * @return void
	 */
	public function testANonJsonRefusalCarriesTheRawBody(): void {
		$service = $this->service(['GET /repos/acme/demo/issues/7' => ['status' => 502, 'body' => 'upstream is down']]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('GitHub refused GET /repos/acme/demo/issues/7 (HTTP 502): upstream is down');

		$service->getIssue('acme/demo', 7, null);
	}//end testANonJsonRefusalCarriesTheRawBody()

	/**
	 * Without a configured credential nothing is called, and the setting is named.
	 *
	 * The container is asserted never to be asked for the broker: a class that
	 * resolved the service first and refused afterwards would return the same
	 * error while still reaching across the app boundary.
	 *
	 * @return void
	 */
	public function testWithoutACredentialNothingReachesTheBroker(): void {
		$service = $this->service([], '');

		try {
			$service->createIssue('acme/demo', 'A title', 'A body', [], 'alice');
			$this->fail('An unconfigured credential must not open an issue');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('github_credential', $e->getMessage());
			$this->assertStringContainsString('hermiq', $e->getMessage());
		}

		$this->assertSame([], $this->github->calls, 'The broker must not be called without a credential');
	}//end testWithoutACredentialNothingReachesTheBroker()

	/**
	 * The same guard holds on a read, not only on a write.
	 *
	 * @return void
	 */
	public function testTheCredentialGuardAlsoHoldsOnReads(): void {
		$service = $this->service([], '');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No GitHub credential is configured.');

		$service->compare('acme/demo', 'development', 'feature-x', null);
	}//end testTheCredentialGuardAlsoHoldsOnReads()

	/**
	 * A file whose whole content is the string "0" comes back as "0", not as empty.
	 *
	 * This is what `?:` got wrong. PHP reads the decoded string "0" as falsy, so
	 * the caller was handed `''` alongside `success => true`: the file exists and
	 * is empty, said the report, about a file holding a zero. A flag file, a
	 * version pin and a feature toggle all look like this, and the model then
	 * writes over a file it believes has nothing in it.
	 *
	 * @return void
	 */
	public function testGetFileReturnsAContentOfZeroRatherThanEmpty(): void {
		$service = $this->service(
			[
				'GET /repos/acme/demo/contents/flag.txt?ref=development' => [
					'status' => 200,
					// MA== is base64 for the single character "0".
					'body' => ['path' => 'flag.txt', 'sha' => 'blob1', 'content' => 'MA=='],
				],
			]
		);

		$result = $service->getFile('acme/demo', 'flag.txt', 'development', 'alice');

		$this->assertSame(true, $result['success']);
		$this->assertSame('0', $result['content']);
		$this->assertSame('flag.txt', $result['path']);
		$this->assertSame('blob1', $result['sha']);
	}//end testGetFileReturnsAContentOfZeroRatherThanEmpty()

	/**
	 * A body that is not base64 at all reads as empty, never as `false`.
	 *
	 * The two natural shorthands both get this wrong in opposite directions, which
	 * is why it is worth its own test beside the "0" one above. `?:` collapses a
	 * decoded "0" to empty; `??` never fires, because base64_decode reports failure
	 * as `false` rather than null, so the boolean itself became the content and
	 * every consumer that concatenated or measured it saw an empty string with no
	 * idea the decode had failed.
	 *
	 * @return void
	 */
	public function testGetFileOnAnUndecodableBodyReadsAsEmptyRatherThanFalse(): void {
		$service = $this->service(
			[
				'GET /repos/acme/demo/contents/broken.txt?ref=development' => [
					'status' => 200,
					'body' => ['path' => 'broken.txt', 'sha' => 'blob9', 'content' => '!!!not base64!!!'],
				],
			]
		);

		$result = $service->getFile('acme/demo', 'broken.txt', 'development', 'alice');

		$this->assertSame(true, $result['success']);
		$this->assertSame('', $result['content']);
		$this->assertIsString($result['content']);
	}//end testGetFileOnAnUndecodableBodyReadsAsEmptyRatherThanFalse()

	/**
	 * A path GitHub says is not there is a failure result, not an exception.
	 *
	 * Absence is an ordinary answer on a read, and the tightened `tryCall()` must
	 * still let it through as one.
	 *
	 * @return void
	 */
	public function testGetFileOnAMissingPathAnswersAFailureRatherThanThrowing(): void {
		$service = $this->service();

		$result = $service->getFile('acme/demo', 'nope.md', 'development', null);

		$this->assertSame(false, $result['success']);
		$this->assertStringContainsString('nope.md', (string)$result['error']);
		$this->assertArrayNotHasKey('content', $result);
	}//end testGetFileOnAMissingPathAnswersAFailureRatherThanThrowing()

	/**
	 * An outage on a read is raised, not reported as "no such file".
	 *
	 * The two are the same shape to the model, and only one of them means the
	 * file is safe to create.
	 *
	 * @return void
	 */
	public function testGetFileOnAServerErrorThrowsRatherThanReportingAbsence(): void {
		$service = $this->service(
			[
				'GET /repos/acme/demo/contents/a.md?ref=development' => [
					'status' => 500,
					'body' => ['message' => 'Server Error'],
				],
			]
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('GitHub refused GET /repos/acme/demo/contents/a.md?ref=development (HTTP 500)');

		$service->getFile('acme/demo', 'a.md', 'development', null);
	}//end testGetFileOnAServerErrorThrowsRatherThanReportingAbsence()

	/**
	 * A refusal carries the HTTP status as its exception code, not zero.
	 *
	 * The code is the whole mechanism behind the 4xx/5xx split: `tryCall()` reads
	 * it to decide between answering null and rethrowing, so a message that names
	 * the status while the code stays 0 would send every outage down the "not
	 * there" branch again, with the tests above still green on their messages.
	 *
	 * @param int $status The status GitHub answered with.
	 *
	 * @return void
	 *
	 * @dataProvider refusalStatuses
	 */
	public function testARefusalCarriesTheHttpStatusAsTheExceptionCode(int $status): void {
		$service = $this->service(
			['GET /repos/acme/demo/issues/7' => ['status' => $status, 'body' => ['message' => 'Nope']]]
		);

		// The exception is captured rather than asserted inside a catch: PHPUnit's
		// own `fail()` throws a RuntimeException too, so a `catch (RuntimeException)`
		// swallows it and reports the wrong line.
		$caught = null;
		try {
			$service->getIssue('acme/demo', 7, null);
		} catch (RuntimeException $e) {
			$caught = $e;
		}

		$this->assertInstanceOf(RuntimeException::class, $caught, 'A ' . $status . ' must not be reported as an issue');
		$this->assertSame($status, $caught->getCode());
	}//end testARefusalCarriesTheHttpStatusAsTheExceptionCode()

	/**
	 * Statuses either side of the 4xx boundary.
	 *
	 * @return array<string,array{0:int}> The cases.
	 */
	public static function refusalStatuses(): array {
		return [
			'a not found' => [404],
			'a validation failure' => [422],
			'a server error' => [500],
			'a gateway timeout' => [504],
		];
	}//end refusalStatuses()

	/**
	 * An outage on the blob-SHA lookup stops the write, rather than creating.
	 *
	 * The old catch-everything made a 500 indistinguishable from a 404, so this
	 * path omitted `sha` and posted a create over a file that exists. GitHub then
	 * answers 422, which reads to the model like its own content being invalid
	 * rather than like the outage it is.
	 *
	 * The assertion that carries the fix is the absence of a PUT: the route is
	 * scripted to succeed here, so a broker that carried on would answer
	 * `success => true` and look identical in the return value.
	 *
	 * @return void
	 */
	public function testPutFileThrowsWhenTheBlobShaLookupFailsWithAServerError(): void {
		$service = $this->service(
			[
				'GET /repos/acme/demo/contents/docs/readme.md?ref=feature-x' => [
					'status' => 500,
					'body' => ['message' => 'Server Error'],
				],
				'PUT /repos/acme/demo/contents/docs/readme.md' => [
					'status' => 201,
					'body' => ['commit' => ['sha' => 'commit999']],
				],
			]
		);

		$caught = null;
		try {
			$service->putFile('acme/demo', 'feature-x', 'docs/readme.md', 'hello', 'docs: a line', 'alice');
		} catch (RuntimeException $e) {
			$caught = $e;
		}

		$this->assertInstanceOf(RuntimeException::class, $caught, 'An outage on the blob lookup must not be read as a new path');
		$this->assertSame(500, $caught->getCode());
		$this->assertStringContainsString('HTTP 500', $caught->getMessage());
		$this->assertNotContains('PUT', $this->github->methods(), 'A failed blob lookup must not be followed by a write');
		$this->assertSame(
			['GET /repos/acme/demo/contents/docs/readme.md?ref=feature-x'],
			$this->github->routes()
		);
	}//end testPutFileThrowsWhenTheBlobShaLookupFailsWithAServerError()

	/**
	 * An outage on the ref lookup stops the branch creation.
	 *
	 * Same shape as the blob lookup: read as "no such branch", this would post a
	 * ref for a branch that already exists, and the 422 back would name the ref
	 * rather than the outage.
	 *
	 * @return void
	 */
	public function testCreateBranchThrowsWhenTheRefLookupFailsWithAServerError(): void {
		$service = $this->service(
			[
				'GET /repos/acme/demo/git/ref/heads/feature-x' => [
					'status' => 500,
					'body' => ['message' => 'Server Error'],
				],
				'GET /repos/acme/demo/git/ref/heads/development' => [
					'status' => 200,
					'body' => ['object' => ['sha' => 'basehead000']],
				],
				'POST /repos/acme/demo/git/refs' => [
					'status' => 201,
					'body' => ['object' => ['sha' => 'basehead000']],
				],
			]
		);

		$caught = null;
		try {
			$service->createBranch('acme/demo', 'feature-x', 'development', 'alice');
		} catch (RuntimeException $e) {
			$caught = $e;
		}

		$this->assertInstanceOf(RuntimeException::class, $caught, 'An outage on the ref lookup must not be read as a missing branch');
		$this->assertSame(500, $caught->getCode());
		$this->assertNotContains('POST', $this->github->methods(), 'A failed ref lookup must not be followed by a create');
		$this->assertSame(['GET /repos/acme/demo/git/ref/heads/feature-x'], $this->github->routes());
	}//end testCreateBranchThrowsWhenTheRefLookupFailsWithAServerError()

	/**
	 * A missing credential propagates through a `tryCall()` path, and writes nothing.
	 *
	 * Its code is 0 rather than a 4xx, which is exactly why the narrowed catch
	 * lets it past: under the old catch-everything a misconfigured app silently
	 * flipped every update into a create.
	 *
	 * @return void
	 */
	public function testAMissingCredentialIsNotSwallowedByATryCallPath(): void {
		$service = $this->service([], '');

		$caught = null;
		try {
			$service->putFile('acme/demo', 'feature-x', 'a.txt', 'hello', 'chore: a file', null);
		} catch (RuntimeException $e) {
			$caught = $e;
		}

		$this->assertInstanceOf(RuntimeException::class, $caught, 'An unconfigured credential must not be read as a new path');
		$this->assertStringContainsString('github_credential', $caught->getMessage());
		$this->assertSame(0, $caught->getCode(), 'The guard carries no HTTP status, which is why it must not be swallowed');
		$this->assertSame([], $this->github->calls, 'The broker must not be called without a credential');
	}//end testAMissingCredentialIsNotSwallowedByATryCallPath()

	/**
	 * A misconfigured app is not reported to the model as a missing file.
	 *
	 * This is the sharpest case of the same fault, because on a read the
	 * `tryCall()` answer IS the result: under the old catch-everything the
	 * credential guard was swallowed and `getFile()` returned
	 * `success => false, "No file at 'a.txt' on 'development'."` — an admin
	 * setting nobody filled in, reported as a file that does not exist. The model
	 * then reasonably creates it.
	 *
	 * @return void
	 */
	public function testAMissingCredentialIsNotReportedAsAMissingFile(): void {
		$service = $this->service([], '');

		$caught = null;
		try {
			$service->getFile('acme/demo', 'a.txt', 'development', null);
		} catch (RuntimeException $e) {
			$caught = $e;
		}

		$this->assertInstanceOf(RuntimeException::class, $caught, 'An unconfigured credential must not read as a missing file');
		$this->assertStringContainsString('github_credential', $caught->getMessage());
		$this->assertSame([], $this->github->calls, 'The broker must not be called without a credential');
	}//end testAMissingCredentialIsNotReportedAsAMissingFile()

	/**
	 * An oversized patch is cut on a character boundary, so the result still encodes.
	 *
	 * The fixture is built so the byte the old `substr` cut at falls INSIDE a
	 * character: one ASCII byte, then 5000 two-byte characters, which puts byte
	 * 8000 on the lead byte of the 4000th one. The control assertion at the end
	 * proves that positioning rather than assuming it, so a fixture that drifted
	 * to a clean boundary would fail here instead of passing vacuously.
	 *
	 * What it costs when this is wrong is the whole comparison, not one
	 * character: the result is JSON-encoded for the model, and json_encode
	 * refuses invalid UTF-8 outright.
	 *
	 * @return void
	 */
	public function testCompareCutsAnOversizedPatchOnACharacterBoundary(): void {
		$patch = 'x' . str_repeat('é', 5000);
		$service = $this->service(
			[
				'GET /repos/acme/demo/compare/development...feature-x' => [
					'status' => 200,
					'body' => [
						'files' => [
							['filename' => 'accenten.php', 'status' => 'modified', 'additions' => 9, 'deletions' => 1, 'patch' => $patch],
						],
					],
				],
			]
		);

		$result = $service->compare('acme/demo', 'development', 'feature-x', 'alice');
		$file = $result['files'][0];

		$this->assertSame(true, $file['patchTruncated']);
		$this->assertTrue(mb_check_encoding($file['patch'], 'UTF-8'), 'The cut must land on a character boundary');
		$this->assertNotFalse(json_encode($result), 'The whole result must survive json_encode');
		$this->assertSame(7999, strlen(str_replace("\n… patch truncated", '', $file['patch'])));
		$this->assertStringEndsWith("\n… patch truncated", $file['patch']);

		// The control: the byte-wise cut really would have split a character here,
		// so a green above is the code working and not the fixture being harmless.
		$this->assertFalse(
			mb_check_encoding(substr($patch, 0, 8000), 'UTF-8'),
			'The fixture must put a character across byte 8000, or it proves nothing'
		);
	}//end testCompareCutsAnOversizedPatchOnACharacterBoundary()
}//end class
