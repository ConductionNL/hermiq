<?php

/**
 * Golden tests for GitHubTemplatePushService's three publish flows.
 *
 * Pins, for push(), pushUpdate() and publishBundle(), the complete sequence of
 * broker calls (credential, app, method, path, headers, raw body, acting user),
 * every log line, the result, and every refusal with its message — before the
 * flows are split into named steps over one run object. A scripted fake broker
 * answers each call; individual answers are overridden per case.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\GitHubTemplatePushService;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden publish flows of GitHubTemplatePushService.
 */
final class GitHubTemplatePushServiceGoldenTest extends TestCase {

	/**
	 * Every broker call, in order.
	 *
	 * @var array<int, string>
	 */
	public array $calls = [];

	/**
	 * Every log line, in order.
	 *
	 * @var array<int, string>
	 */
	public array $logged = [];

	/**
	 * Answers by "METHOD path"; a Throwable is thrown, an array is the response.
	 *
	 * @var array<string, mixed>
	 */
	public array $answers = [];

	/**
	 * How many blobs were posted.
	 *
	 * @var int
	 */
	public int $blobs = 0;

	/**
	 * Reset the fake GitHub: `acme/demo` does not exist.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calls = [];
		$this->logged = [];
		$this->blobs = 0;
		$this->answers = [
			'GET /repos/acme/demo' => ['status' => 404, 'body' => '{"message":"Not Found"}'],
			'POST /orgs/acme/repos' => ['status' => 201, 'body' => '{"default_branch":"trunk","html_url":"https://github.com/acme/demo-html"}'],
			'GET /repos/acme/demo/topics' => ['status' => 200, 'body' => '{"names":["openbuild-app","","hermiq-skill"]}'],
			'PUT /repos/acme/demo/topics' => ['status' => 200, 'body' => '{}'],
			'GET /repos/acme/demo/git/refs/heads/trunk' => ['status' => 200, 'body' => '{"object":{"sha":"base-sha"}}'],
			'GET /repos/acme/demo/git/refs/heads/main' => ['status' => 200, 'body' => '{"object":{"sha":"base-sha"}}'],
			'GET /repos/acme/demo/git/commits/base-sha' => ['status' => 200, 'body' => '{"tree":{"sha":"base-tree"}}'],
			'POST /repos/acme/demo/git/trees' => ['status' => 201, 'body' => '{"sha":"new-tree"}'],
			'POST /repos/acme/demo/git/commits' => ['status' => 201, 'body' => '{"sha":"new-commit"}'],
			'PATCH /repos/acme/demo/git/refs/heads/trunk' => ['status' => 200, 'body' => '{}'],
			'PATCH /repos/acme/demo/git/refs/heads/main' => ['status' => 200, 'body' => '{}'],
		];
	}//end setUp()

	/**
	 * Answer one broker call from the fake GitHub, recording it.
	 *
	 * @param array<int, mixed> $args The request arguments.
	 *
	 * @return mixed The response.
	 */
	public function answer(array $args): mixed {
		[$credentialId, $appId, $method, $path, $headers, $body, $actingUserId] = $args;
		$this->calls[] = implode(' | ', [$credentialId, $appId, $method, $path, json_encode($headers), var_export($body, true), var_export($actingUserId, true)]);
		if ($method === 'POST' && str_ends_with($path, '/git/blobs') === true) {
			$this->blobs++;
			$content = base64_decode((string)(json_decode((string)$body, true)['content'] ?? ''));
			if (str_starts_with($content, 'SHALESS') === true) {
				return ['status' => 201, 'body' => '{}'];
			}

			return ['status' => 201, 'body' => '{"sha":"blob-' . $this->blobs . '"}'];
		}

		$answer = $this->answers[$method . ' ' . $path] ?? ['status' => 500, 'body' => ''];
		if ($answer instanceof \Throwable) {
			throw $answer;
		}

		return $answer;
	}//end answer()

	/**
	 * The service over the fake broker.
	 *
	 * @return GitHubTemplatePushService
	 */
	private function service(): GitHubTemplatePushService {
		$test = $this;
		$broker = new class($test) extends CredentialBrokerService {
			/**
			 * @param GitHubTemplatePushServiceGoldenTest $test The fake GitHub.
			 */
			public function __construct(private readonly GitHubTemplatePushServiceGoldenTest $test) {
			}

			/**
			 * Record and answer.
			 *
			 * @param string $credentialId The credential.
			 * @param string $appId The app.
			 * @param string $method The method.
			 * @param string $path The path.
			 * @param array $headers The headers.
			 * @param string|null $body The body.
			 * @param string|null $actingUserId The acting user.
			 *
			 * @return array The response.
			 */
			public function request(
				string $credentialId,
				string $appId,
				string $method,
				string $path,
				array $headers = [],
				?string $body = null,
				?string $actingUserId = null,
			): array {
				return $this->test->answer([$credentialId, $appId, $method, $path, $headers, $body, $actingUserId]);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($broker);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = $level . ': ' . $message . ($context === [] ? '' : ' ' . json_encode($context));
				}
			);
		}

		return new GitHubTemplatePushService(logger: $logger, container: $container);
	}//end service()

	/**
	 * One recorded call line.
	 *
	 * @param string $method The method.
	 * @param string $path The path.
	 * @param array<string, mixed>|null $body The decoded body.
	 *
	 * @return string The line.
	 */
	private function call(string $method, string $path, ?array $body = null): string {
		$encoded = $body === null ? null : (string)json_encode($body);
		return implode(' | ', ['cred-1', 'hermiq', $method, $path, '{"Accept":"application\/vnd.github+json","Content-Type":"application\/json"}', var_export($encoded, true), "'alice'"]);
	}//end call()

	/**
	 * The calls of a commit onto `$branch` with the given tree entries and message.
	 *
	 * @param string $branch The branch.
	 * @param array<int, array<string, string>> $entries The tree entries.
	 * @param string $message The commit message.
	 *
	 * @return array<int, string>
	 */
	private function commitCalls(string $branch, array $entries, string $message): array {
		return [
			$this->call('POST', '/repos/acme/demo/git/trees', ['base_tree' => 'base-tree', 'tree' => $entries]),
			$this->call('POST', '/repos/acme/demo/git/commits', ['message' => $message, 'tree' => 'new-tree', 'parents' => ['base-sha']]),
			$this->call('PATCH', '/repos/acme/demo/git/refs/heads/' . $branch, ['sha' => 'new-commit']),
		];
	}//end commitCalls()

	/**
	 * A blob call for $content.
	 *
	 * @param string $content The content.
	 *
	 * @return string
	 */
	private function blob(string $content): string {
		return $this->call('POST', '/repos/acme/demo/git/blobs', ['content' => base64_encode($content), 'encoding' => 'base64']);
	}//end blob()

	/**
	 * A tree entry.
	 *
	 * @param string $path The path.
	 * @param string $sha The sha.
	 *
	 * @return array<string, string>
	 */
	private function entry(string $path, string $sha): array {
		return ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => $sha];
	}//end entry()

	/**
	 * The refusal message of $publish, or null.
	 *
	 * @param callable $publish The call.
	 *
	 * @return string|null
	 */
	private function refusal(callable $publish): ?string {
		try {
			$publish();
		} catch (RuntimeException $e) {
			return $e->getMessage();
		}

		return null;
	}//end refusal()

	/**
	 * A skill push: create, topics, then one commit with the package and the valid auxiliary files.
	 *
	 * @return void
	 */
	public function testPushOfASkill(): void {
		$result = $this->service()->push(
			package: 'PKG',
			owner: 'acme',
			repo: 'demo',
			visibility: 'public',
			credentialId: 'cred-1',
			actingUserId: 'alice',
			kind: GitHubTemplatePushService::KIND_SKILL,
			auxFiles: ['x', ['name' => ''], ['name' => '../up', 'content' => 'no'], ['name' => 'learnings.md', 'content' => 'L'], ['name' => 'docs/a.md']]
		);

		$this->assertSame(['repoUrl' => 'https://github.com/acme/demo-html', 'commitSha' => 'new-commit'], $result);
		$this->assertSame(
			array_merge(
				[
					$this->call('GET', '/repos/acme/demo'),
					$this->call('POST', '/orgs/acme/repos', ['name' => 'demo', 'private' => false, 'auto_init' => true, 'description' => 'Published from Hermiq — a portable Hermiq agent skill.']),
					$this->call('GET', '/repos/acme/demo/topics'),
					$this->call('PUT', '/repos/acme/demo/topics', ['names' => ['hermiq-skill', 'openbuild-app']]),
					$this->call('GET', '/repos/acme/demo/git/refs/heads/trunk'),
					$this->call('GET', '/repos/acme/demo/git/commits/base-sha'),
					$this->blob('PKG'),
					$this->blob('L'),
					$this->blob(''),
				],
				$this->commitCalls(
					'trunk',
					[$this->entry('hermiq-skill.md', 'blob-1'), $this->entry('learnings.md', 'blob-2'), $this->entry('docs/a.md', 'blob-3')],
					'chore: publish agent skill from Hermiq'
				)
			),
			$this->calls
		);
		$this->assertSame(['info: Hermiq GitHub publish: creating repository {"owner":"acme","repo":"demo","kind":"skill"}'], $this->logged);
	}//end testPushOfASkill()

	/**
	 * An agent-template push with defaults: private, the template's package file and messages, default URL and branch.
	 *
	 * @return void
	 */
	public function testPushOfATemplateWithDefaults(): void {
		$this->answers['POST /orgs/acme/repos'] = ['status' => 201, 'body' => '{}'];
		$this->answers['GET /repos/acme/demo/topics'] = ['status' => 404, 'body' => ''];
		$this->answers['PUT /repos/acme/demo/topics'] = ['status' => 422, 'body' => ''];
		$this->answers['POST /repos/acme/demo/git/trees'] = ['status' => 201, 'body' => '{}'];
		$result = $this->service()->push(package: '{}', owner: 'acme', repo: 'demo', visibility: 'private', credentialId: 'cred-1', actingUserId: 'alice');

		$this->assertSame(['repoUrl' => 'https://github.com/acme/demo', 'commitSha' => 'new-commit'], $result);
		$this->assertSame($this->call('POST', '/orgs/acme/repos', ['name' => 'demo', 'private' => true, 'auto_init' => true, 'description' => 'Published from Hermiq — a portable Hermiq agent template.']), $this->calls[1]);
		$this->assertSame($this->call('PUT', '/repos/acme/demo/topics', ['names' => ['hermiq-agent-template']]), $this->calls[3]);
		$this->assertSame($this->call('GET', '/repos/acme/demo/git/refs/heads/main'), $this->calls[4]);
		$this->assertSame($this->call('POST', '/repos/acme/demo/git/trees', ['base_tree' => 'base-tree', 'tree' => [$this->entry('hermiq-agent-template.json', 'blob-1')]]), $this->calls[7]);
		$this->assertSame($this->call('POST', '/repos/acme/demo/git/commits', ['message' => 'chore: publish agent template from Hermiq', 'tree' => '', 'parents' => ['base-sha']]), $this->calls[8]);
		$this->assertSame(['info: Hermiq GitHub publish: creating repository {"owner":"acme","repo":"demo","kind":"agent-template"}'], $this->logged);
	}//end testPushOfATemplateWithDefaults()

	/**
	 * Every way a push is refused, with its message, its log lines and the calls it made.
	 *
	 * @return void
	 */
	public function testPushRefusals(): void {
		$push = fn (string $credential = 'cred-1', string $owner = 'acme') => fn () => $this->service()->push(
			package: '{}', owner: $owner, repo: 'demo', visibility: 'private', credentialId: $credential, actingUserId: 'alice'
		);

		$this->assertSame('GitHub publish requires a broker credential.', $this->refusal($push(credential: '')));
		$this->assertSame([], $this->calls);
		$this->assertSame('GitHub publish requires a valid owner and repository name.', $this->refusal($push(owner: 'ac me')));
		$this->assertSame([], $this->calls);

		$cases = [
			'exists' => [['GET /repos/acme/demo' => ['status' => 200, 'body' => '{}']], 'Repository acme/demo already exists', 1, []],
			'create fails' => [['POST /orgs/acme/repos' => ['status' => 403, 'body' => '']], 'GitHub create-repo failed.', 2, ['warning: Hermiq GitHub template publish: POST /orgs/acme/repos returned HTTP 403']],
			'no tip' => [['GET /repos/acme/demo/git/refs/heads/trunk' => ['status' => 200, 'body' => '{"object":{}}']], 'GitHub publish: could not resolve the default branch tip.', 5, []],
			'ref unreadable' => [
				['GET /repos/acme/demo/git/refs/heads/trunk' => new RuntimeException('token ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ0123 refused')],
				'GitHub API call failed: GET /repos/acme/demo/git/refs/heads/trunk',
				5,
				['warning: Hermiq GitHub template publish: broker call failed for GET /repos/acme/demo/git/refs/heads/trunk: token [redacted-token] refused'],
			],
			'commit unreadable' => [['GET /repos/acme/demo/git/commits/base-sha' => ['status' => 500, 'body' => '']], 'GitHub API call failed: GET /repos/acme/demo/git/commits/base-sha', 6, ['warning: Hermiq GitHub template publish: GET /repos/acme/demo/git/commits/base-sha returned HTTP 500']],
			'blob fails' => [['__blob' => true], 'GitHub API call failed: POST /repos/acme/demo/git/blobs', 7, []],
			'no commit' => [['POST /repos/acme/demo/git/commits' => ['status' => 201, 'body' => '{}']], 'GitHub publish: commit creation failed.', 9, []],
			'tree fails' => [['POST /repos/acme/demo/git/trees' => ['status' => 422, 'body' => '']], 'GitHub API call failed: POST /repos/acme/demo/git/trees', 8, ['warning: Hermiq GitHub template publish: POST /repos/acme/demo/git/trees returned HTTP 422']],
		];
		foreach ($cases as $name => [$answers, $message, $callCount, $warnings]) {
			$this->setUp();
			if (isset($answers['__blob']) === true) {
				$this->answers = [];
				$this->setUp();
				$service = $this->service();
				$this->answers['GET /repos/acme/demo/git/commits/base-sha'] = ['status' => 200, 'body' => '{"tree":{"sha":"base-tree"}}'];
				$message2 = $this->refusal(fn () => $service->push(package: 'SHALESS', owner: 'acme', repo: 'demo', visibility: 'private', credentialId: 'cred-1', actingUserId: 'alice'));
				$this->assertNull($message2, $name . ': a package blob without a sha is not refused');
				continue;
			}

			$this->answers = array_merge($this->answers, $answers);
			$this->assertSame($message, $this->refusal($push()), $name);
			$this->assertCount($callCount, $this->calls, $name);
			$this->assertSame(array_merge(['info: Hermiq GitHub publish: creating repository {"owner":"acme","repo":"demo","kind":"agent-template"}'], $warnings), $this->logged, $name);
		}
	}//end testPushRefusals()

	/**
	 * A republish reads the existing repository, then commits onto its default branch; a vanished one is refused.
	 *
	 * @return void
	 */
	public function testRepublish(): void {
		$this->answers['GET /repos/acme/demo'] = ['status' => 200, 'body' => '{"default_branch":"trunk"}'];
		$result = $this->service()->pushUpdate(
			package: 'PKG',
			owner: 'acme',
			repo: 'demo',
			credentialId: 'cred-1',
			actingUserId: 'alice',
			auxFiles: [['name' => 'learnings.md', 'content' => 'L']]
		);
		$this->assertSame(['repoUrl' => 'https://github.com/acme/demo', 'commitSha' => 'new-commit'], $result);
		$this->assertSame(
			array_merge(
				[
					$this->call('GET', '/repos/acme/demo'),
					$this->call('GET', '/repos/acme/demo/git/refs/heads/trunk'),
					$this->call('GET', '/repos/acme/demo/git/commits/base-sha'),
					$this->blob('PKG'),
					$this->blob('L'),
				],
				$this->commitCalls('trunk', [$this->entry('hermiq-skill.md', 'blob-1'), $this->entry('learnings.md', 'blob-2')], 'chore: publish agent skill from Hermiq')
			),
			$this->calls
		);
		$this->assertSame(['info: Hermiq GitHub republish: updating repository {"owner":"acme","repo":"demo","kind":"skill"}'], $this->logged);

		$this->setUp();
		$this->assertSame(
			'Repository acme/demo does not exist — cannot republish.',
			$this->refusal(fn () => $this->service()->pushUpdate(package: 'P', owner: 'acme', repo: 'demo', credentialId: 'cred-1', actingUserId: 'alice'))
		);
		$this->assertSame(
			['info: Hermiq GitHub republish: updating repository {"owner":"acme","repo":"demo","kind":"skill"}', 'warning: Hermiq GitHub template publish: GET /repos/acme/demo returned HTTP 404'],
			$this->logged
		);

		$this->setUp();
		$this->assertSame('GitHub republish requires a broker credential.', $this->refusal(fn () => $this->service()->pushUpdate(package: 'P', owner: 'acme', repo: 'demo', credentialId: '')));
		$this->assertSame('GitHub republish requires a valid owner and repository name.', $this->refusal(fn () => $this->service()->pushUpdate(package: 'P', owner: 'acme', repo: '', credentialId: 'c')));
		$this->assertSame('GitHub publish: commit creation failed.', $this->refusal(function () {
			$this->answers['GET /repos/acme/demo'] = ['status' => 200, 'body' => '{}'];
			$this->answers['POST /repos/acme/demo/git/commits'] = ['status' => 201, 'body' => '{}'];
			$this->service()->pushUpdate(package: 'P', owner: 'acme', repo: 'demo', credentialId: 'cred-1', actingUserId: 'alice');
		}));
	}//end testRepublish()

	/**
	 * A bundle into an absent repository creates it; into a present one updates it; both merge topics.
	 *
	 * @return void
	 */
	public function testBundlePublish(): void {
		$files = ['hermiq-skills.json' => '{}', '' => 'empty', '../x' => 'up', 'skills/a/SKILL.md' => 7];
		$result = $this->service()->publishBundle(files: $files, owner: 'acme', repo: 'demo', visibility: 'private', credentialId: 'cred-1', actingUserId: 'alice');
		$this->assertSame(['repoUrl' => 'https://github.com/acme/demo-html', 'commitSha' => 'new-commit', 'created' => true], $result);
		$this->assertSame(
			array_merge(
				[
					$this->call('GET', '/repos/acme/demo'),
					$this->call('POST', '/orgs/acme/repos', ['name' => 'demo', 'private' => true, 'auto_init' => true, 'description' => 'Published from Hermiq — a portable Hermiq agent template.']),
					$this->call('GET', '/repos/acme/demo/topics'),
					$this->call('PUT', '/repos/acme/demo/topics', ['names' => ['hermiq-skill', 'hermiq-skill-bundle', 'openbuild-app']]),
					$this->call('GET', '/repos/acme/demo/git/refs/heads/trunk'),
					$this->call('GET', '/repos/acme/demo/git/commits/base-sha'),
					$this->blob('{}'),
					$this->blob('7'),
				],
				$this->commitCalls('trunk', [$this->entry('hermiq-skills.json', 'blob-1'), $this->entry('skills/a/SKILL.md', 'blob-2')], 'chore: publish agent skill bundle from Hermiq')
			),
			$this->calls
		);
		$this->assertSame(
			[
				'warning: Hermiq bundle publish: skipped unsafe path. {"path":""}',
				'warning: Hermiq bundle publish: skipped unsafe path. {"path":"..\/x"}',
				'info: Hermiq GitHub bundle publish complete {"owner":"acme","repo":"demo","created":true,"entries":4}',
			],
			array_values(array_filter($this->logged, static fn (string $l): bool => str_contains($l, 'bundle')))
		);

		$this->setUp();
		$this->answers['GET /repos/acme/demo'] = ['status' => 200, 'body' => '{"html_url":"https://github.com/acme/x"}'];
		$result = $this->service()->publishBundle(files: ['a.md' => 'a'], owner: 'acme', repo: 'demo', visibility: 'public', credentialId: 'cred-1', actingUserId: 'alice');
		$this->assertSame(['repoUrl' => 'https://github.com/acme/x', 'commitSha' => 'new-commit', 'created' => false], $result);
		$this->assertSame($this->call('GET', '/repos/acme/demo/topics'), $this->calls[1]);
		$this->assertSame($this->call('GET', '/repos/acme/demo/git/refs/heads/main'), $this->calls[3]);
		$this->assertSame(['info: Hermiq GitHub bundle publish complete {"owner":"acme","repo":"demo","created":false,"entries":1}'], $this->logged);
	}//end testBundlePublish()

	/**
	 * Every way a bundle publish is refused.
	 *
	 * @return void
	 */
	public function testBundleRefusals(): void {
		$bundle = fn (array $files, string $credential = 'cred-1', string $repo = 'demo') => fn () => $this->service()->publishBundle(
			files: $files, owner: 'acme', repo: $repo, visibility: 'private', credentialId: $credential, actingUserId: 'alice'
		);
		$this->assertSame('GitHub bundle publish requires a broker credential.', $this->refusal($bundle(['a' => 'a'], credential: '')));
		$this->assertSame('GitHub bundle publish requires a valid owner and repository name.', $this->refusal($bundle(['a' => 'a'], repo: 'x/y')));
		$this->assertSame('GitHub bundle publish requires a non-empty tree.', $this->refusal($bundle([])));
		$this->assertSame([], $this->calls);

		$this->assertSame('GitHub bundle publish: no publishable entries after path validation.', $this->refusal($bundle(['/abs' => 'a'])));
		$this->assertCount(6, $this->calls);

		$this->setUp();
		$this->assertSame('GitHub bundle publish: blob upload returned no sha for "b.md".', $this->refusal($bundle(['a.md' => 'a', 'b.md' => 'SHALESS', 'c.md' => 'c'])));
		$this->assertSame(8, count($this->calls));

		$this->setUp();
		$this->answers['GET /repos/acme/demo/git/refs/heads/trunk'] = ['status' => 200, 'body' => '{}'];
		$this->assertSame('GitHub bundle publish: could not resolve the default branch tip.', $this->refusal($bundle(['a.md' => 'a'])));

		$this->setUp();
		$this->answers['POST /repos/acme/demo/git/commits'] = ['status' => 201, 'body' => '{}'];
		$this->assertSame('GitHub bundle publish: commit creation failed.', $this->refusal($bundle(['a.md' => 'a'])));

		$this->setUp();
		$this->answers['GET /repos/acme/demo'] = new RuntimeException('down');
		$this->answers['POST /orgs/acme/repos'] = ['status' => 422, 'body' => ''];
		$this->assertSame('GitHub create-repo failed.', $this->refusal($bundle(['a.md' => 'a'])));
		$this->assertSame(['warning: Hermiq GitHub template publish: POST /orgs/acme/repos returned HTTP 422'], $this->logged);
	}//end testBundleRefusals()
}//end class
