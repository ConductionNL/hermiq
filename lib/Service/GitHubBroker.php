<?php

/**
 * Every GitHub call Hermiq makes on an agent's behalf, through the broker.
 *
 * {@see \OCA\Hermiq\Tool\GitHubTool} is the surface a model sees; this is the
 * surface GitHub sees. They are separate because the shapes disagree: the model
 * gets one verb per intention, while GitHub needs two or three round trips for
 * several of them — branching reads the base head first, replacing a file reads
 * its blob SHA first. Keeping that here means the tool descriptions stay honest
 * about what the agent must supply, which is the only part it can get wrong.
 *
 * WHY THE BROKER AND NOT A CLIENT. `CredentialBrokerService` holds the token,
 * pins the host to api.github.com and matches every method and path against the
 * `github` provider's allow-rules. Those rules are the real boundary: an agent
 * that invents `DELETE /repos/x/y` is refused by the broker, not by our hoping
 * no function exposes it. The service is resolved by class-string through the
 * container so Hermiq still builds where OpenRegister is absent.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The brokered GitHub client.
 */
class GitHubBroker {

	/**
	 * OpenRegister's broker, named by string so this class survives its absence.
	 *
	 * @var string
	 */
	private const BROKER_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService';

	/**
	 * The app id the broker checks against a credential's allowedApps.
	 *
	 * @var string
	 */
	private const APP_ID = 'hermiq';

	/**
	 * How much of one file may come back to the model.
	 *
	 * Generous enough for almost every source file in this fleet and small enough
	 * that two or three of them still leave a turn room to answer.
	 *
	 * @var int
	 */
	private const MAX_FILE_CHARS = 14000;

	/**
	 * App-config key naming the `github` credential to use.
	 *
	 * @var string
	 */
	private const CREDENTIAL_KEY = 'github_credential';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The server container, for the optional broker.
	 * @param IAppConfig $appConfig App configuration, holding the credential id.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Open an issue.
	 *
	 * @param string $repo Repository as owner/name.
	 * @param string $title Issue title.
	 * @param string $body Issue body.
	 * @param array<int,string> $labels Label names.
	 * @param string|null $userId Acting user.
	 *
	 * @return array<string,mixed>
	 */
	public function createIssue(string $repo, string $title, string $body, array $labels, ?string $userId): array {
		$payload = ['title' => $title, 'body' => $body];
		if ($labels !== []) {
			$payload['labels'] = array_values($labels);
		}

		$issue = $this->call(method: 'POST', path: '/repos/' . $repo . '/issues', payload: $payload, userId: $userId);

		return [
			'success' => true,
			'number' => (int)($issue['number'] ?? 0),
			'html_url' => (string)($issue['html_url'] ?? ''),
		];
	}//end createIssue()

	/**
	 * Read one issue, with its label names flattened.
	 *
	 * The flattening is the point of the method. GitHub returns labels as objects,
	 * and a model asked whether an issue carries `accepted` reads a list of strings
	 * correctly far more often than a list of objects.
	 *
	 * @param string $repo Repository as owner/name.
	 * @param int $number Issue number.
	 * @param string|null $userId Acting user.
	 *
	 * @return array<string,mixed>
	 */
	public function getIssue(string $repo, int $number, ?string $userId): array {
		$issue = $this->call(method: 'GET', path: '/repos/' . $repo . '/issues/' . $number, payload: null, userId: $userId);

		$labels = [];
		foreach (($issue['labels'] ?? []) as $label) {
			$name = is_array($label) ? ($label['name'] ?? '') : $label;
			$name = trim((string)$name);
			if ($name !== '') {
				$labels[] = $name;
			}
		}

		return [
			'success' => true,
			'number' => (int)($issue['number'] ?? $number),
			'title' => (string)($issue['title'] ?? ''),
			'body' => (string)($issue['body'] ?? ''),
			'state' => (string)($issue['state'] ?? ''),
			'labels' => $labels,
			'html_url' => (string)($issue['html_url'] ?? ''),
		];
	}//end getIssue()

	/**
	 * Comment on an issue or pull request.
	 *
	 * @param string $repo Repository as owner/name.
	 * @param int $number Issue or pull request number.
	 * @param string $body Comment body.
	 * @param string|null $userId Acting user.
	 *
	 * @return array<string,mixed>
	 */
	public function commentIssue(string $repo, int $number, string $body, ?string $userId): array {
		$comment = $this->call(
			method: 'POST',
			path: '/repos/' . $repo . '/issues/' . $number . '/comments',
			payload: ['body' => $body],
			userId: $userId
		);

		return ['success' => true, 'html_url' => (string)($comment['html_url'] ?? '')];
	}//end commentIssue()

	/**
	 * Branch from another branch.
	 *
	 * An existing branch of the same name is reported as success rather than as an
	 * error, because a retried step must not fail on work the first attempt already
	 * did — and a run that reaches here twice is the normal shape of a resumed flow.
	 *
	 * @param string $repo Repository as owner/name.
	 * @param string $name New branch name.
	 * @param string $base Branch to start from.
	 * @param string|null $userId Acting user.
	 *
	 * @return array<string,mixed>
	 */
	public function createBranch(string $repo, string $name, string $base, ?string $userId): array {
		$name = ltrim(trim($name), '/');
		$base = ($base === '') ? 'development' : $base;

		$existing = $this->tryCall(method: 'GET', path: '/repos/' . $repo . '/git/ref/heads/' . rawurlencode($name), userId: $userId);
		if ($existing !== null) {
			return ['success' => true, 'branch' => $name, 'created' => false, 'sha' => (string)($existing['object']['sha'] ?? '')];
		}

		$baseRef = $this->call(
			method: 'GET',
			path: '/repos/' . $repo . '/git/ref/heads/' . rawurlencode($base),
			payload: null,
			userId: $userId
		);
		$sha = (string)($baseRef['object']['sha'] ?? '');
		if ($sha === '') {
			throw new RuntimeException("Could not resolve the head of '{$base}'.");
		}

		$created = $this->call(
			method: 'POST',
			path: '/repos/' . $repo . '/git/refs',
			payload: ['ref' => 'refs/heads/' . $name, 'sha' => $sha],
			userId: $userId
		);

		return [
			'success' => true,
			'branch' => $name,
			'created' => true,
			'sha' => (string)($created['object']['sha'] ?? $sha),
		];
	}//end createBranch()

	/**
	 * Read one text file.
	 *
	 * @param string $repo Repository as owner/name.
	 * @param string $path Path from the repository root.
	 * @param string $ref Branch, tag or SHA.
	 * @param string|null $userId Acting user.
	 *
	 * @return array<string,mixed>
	 */
	public function getFile(string $repo, string $path, string $ref, ?string $userId): array {
		$file = $this->tryCall(
			method: 'GET',
			path: '/repos/' . $repo . '/contents/' . $this->encodePath(path: $path) . '?ref=' . rawurlencode($ref),
			userId: $userId
		);

		if ($file === null) {
			return ['success' => false, 'error' => "No file at '{$path}' on '{$ref}'."];
		}

		// Neither `?:` nor `??` can express this, and both were tried. A file whose
		// entire content is "0" decodes to the string "0", which PHP reads as
		// FALSY, so `?:` reported the file as existing and empty. `??` fires only
		// on null, and base64_decode reports failure as FALSE, so it let the
		// boolean through as the content. Only the explicit comparison separates
		// "decoded to something falsy" from "did not decode".
		$decoded = base64_decode(str_replace("\n", '', (string)($file['content'] ?? '')), true);

		$content = ($decoded === false) ? '' : $decoded;
		$truncated = (strlen($content) > self::MAX_FILE_CHARS);


		return [
			'success' => true,
			'path' => (string)($file['path'] ?? $path),
			'sha' => (string)($file['sha'] ?? ''),
			// A tool result is model INPUT, so an unbounded one is a context bomb.
			// Measured 2026-09-23: a build step asked for a 22 kB component and a
			// 9 kB helper, and the turn that followed ended with the budget spent
			// on reasoning and no content at all — an empty answer that says
			// nothing about why. Capping keeps the turn survivable, and saying so
			// in the result is what stops the model treating a cut file as the
			// whole file and rewriting it short.
			'content' => $truncated ? mb_strcut($content, 0, self::MAX_FILE_CHARS, 'UTF-8') : $content,
			'truncated' => $truncated,
			'totalChars' => strlen($content),
		];
	}//end getFile()

	/**
	 * List one directory.
	 *
	 * @param string $repo Repository as owner/name.
	 * @param string $path Directory from the repository root.
	 * @param string $ref Branch, tag or SHA.
	 * @param string|null $userId Acting user.
	 *
	 * @return array<string,mixed>
	 */
	public function listFiles(string $repo, string $path, string $ref, ?string $userId): array {
		$entries = $this->tryCall(
			method: 'GET',
			path: '/repos/' . $repo . '/contents/' . $this->encodePath(path: $path) . '?ref=' . rawurlencode($ref),
			userId: $userId
		);

		if ($entries === null) {
			return ['success' => false, 'error' => "No directory at '{$path}' on '{$ref}'."];
		}

		if (array_is_list($entries) === false) {
			return ['success' => false, 'error' => "'{$path}' is a file, not a directory."];
		}

		$out = [];
		foreach ($entries as $entry) {
			$out[] = ['path' => (string)($entry['path'] ?? ''), 'type' => (string)($entry['type'] ?? '')];
		}

		return ['success' => true, 'entries' => $out];
	}//end listFiles()

	/**
	 * Create or replace one file and commit it.
	 *
	 * @param string $repo Repository as owner/name.
	 * @param string $branch Branch to commit on.
	 * @param string $path Path from the repository root.
	 * @param string $content File content, as plain text.
	 * @param string $message Commit message.
	 * @param string|null $userId Acting user.
	 *
	 * @return array<string,mixed>
	 */
	public function putFile(string $repo, string $branch, string $path, string $content, string $message, ?string $userId): array {
		$payload = [
			'message' => $message,
			'content' => base64_encode($content),
			'branch' => $branch,
		];

		// An update needs the blob SHA it replaces, and GitHub rejects the write
		// without it. Resolving it here rather than asking the model is the whole
		// reason this is a method and not a passthrough.
		$existing = $this->tryCall(
			method: 'GET',
			path: '/repos/' . $repo . '/contents/' . $this->encodePath(path: $path) . '?ref=' . rawurlencode($branch),
			userId: $userId
		);
		if ($existing !== null && isset($existing['sha']) === true) {
			$payload['sha'] = (string)$existing['sha'];
		}

		$written = $this->call(
			method: 'PUT',
			path: '/repos/' . $repo . '/contents/' . $this->encodePath(path: $path),
			payload: $payload,
			userId: $userId
		);

		return [
			'success' => true,
			'path' => $path,
			'commit' => (string)($written['commit']['sha'] ?? ''),
			'html_url' => (string)($written['content']['html_url'] ?? ''),
		];
	}//end putFile()

	/**
	 * Open a pull request.
	 *
	 * @param string $repo Repository as owner/name.
	 * @param string $head Branch holding the changes.
	 * @param string $base Branch to merge into.
	 * @param string $title Pull request title.
	 * @param string $body Pull request body.
	 * @param bool $draft Open as a draft.
	 * @param string|null $userId Acting user.
	 *
	 * @return array<string,mixed>
	 */
	public function createPullRequest(string $repo, string $head, string $base, string $title, string $body, bool $draft, ?string $userId): array {
		$pr = $this->call(
			method: 'POST',
			path: '/repos/' . $repo . '/pulls',
			payload: [
				'title' => $title,
				'body' => $body,
				'head' => $head,
				'base' => ($base === '') ? 'development' : $base,
				'draft' => $draft,
			],
			userId: $userId
		);

		return [
			'success' => true,
			'number' => (int)($pr['number'] ?? 0),
			'html_url' => (string)($pr['html_url'] ?? ''),
			'draft' => (bool)($pr['draft'] ?? $draft),
		];
	}//end createPullRequest()

	/**
	 * Compare two refs and return each changed file with its patch.
	 *
	 * Patches are truncated per file. A review reads hunks, and a diff large enough
	 * to matter is large enough to push the instruction out of the model's window —
	 * a silently unreviewed file is worse than one the report names as skipped.
	 *
	 * @param string $repo Repository as owner/name.
	 * @param string $base Ref to compare from.
	 * @param string $head Ref to compare to.
	 * @param string|null $userId Acting user.
	 *
	 * @return array<string,mixed>
	 */
	public function compare(string $repo, string $base, string $head, ?string $userId): array {
		$comparison = $this->call(
			method: 'GET',
			path: '/repos/' . $repo . '/compare/' . rawurlencode($base) . '...' . rawurlencode($head),
			payload: null,
			userId: $userId
		);

		$files = [];
		foreach (($comparison['files'] ?? []) as $file) {
			$patch = (string)($file['patch'] ?? '');
			$truncated = (strlen($patch) > 8000);
			$files[] = [
				'filename' => (string)($file['filename'] ?? ''),
				'status' => (string)($file['status'] ?? ''),
				'additions' => (int)($file['additions'] ?? 0),
				'deletions' => (int)($file['deletions'] ?? 0),
				// mb_strcut, not substr: the result is JSON-encoded for the model, and
				// json_encode FAILS on invalid UTF-8. A byte-wise cut through a
				// multibyte character would therefore lose the whole comparison
				// rather than one character, and only for diffs containing
				// non-ASCII near the 8000th byte.
				'patch' => $truncated ? (mb_strcut($patch, 0, 8000, 'UTF-8') . "\n… patch truncated") : $patch,
				'patchTruncated' => $truncated,
			];
		}

		return ['success' => true, 'files' => $files, 'total' => count($files)];
	}//end compare()

	/**
	 * Percent-encode a repository path, keeping its separators.
	 *
	 * @param string $path The path.
	 *
	 * @return string
	 */
	private function encodePath(string $path): string {
		$path = trim($path, '/');
		if ($path === '') {
			return '';
		}

		return implode('/', array_map('rawurlencode', explode('/', $path)));
	}//end encodePath()

	/**
	 * One brokered call, decoded, throwing on anything that is not a 2xx.
	 *
	 * @param string $method HTTP method.
	 * @param string $path GitHub-relative path.
	 * @param array<string,mixed>|null $payload JSON body, or null.
	 * @param string|null $userId Acting user.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws RuntimeException When the broker denies the call or GitHub refuses it.
	 */
	private function call(string $method, string $path, ?array $payload, ?string $userId): array {
		$credentialId = trim($this->appConfig->getValueString(self::APP_ID, self::CREDENTIAL_KEY, ''));
		if ($credentialId === '') {
			throw new RuntimeException(
				'No GitHub credential is configured. Create one of provider `github` in OpenRegister and set '
				. 'its uuid as the `github_credential` app setting on hermiq.'
			);
		}

		if (class_exists(self::BROKER_CLASS) === false) {
			throw new RuntimeException('OpenRegister\'s credential broker is not available.');
		}

		$broker = $this->container->get(self::BROKER_CLASS);
		$response = $broker->request(
			$credentialId,
			self::APP_ID,
			$method,
			$path,
			['Accept' => 'application/vnd.github+json', 'Content-Type' => 'application/json'],
			($payload === null) ? null : json_encode($payload),
			$userId
		);

		$status = (int)($response['status'] ?? 0);
		$body = (string)($response['body'] ?? '');

		if ($status < 200 || $status >= 300) {
			$decoded = json_decode($body, true);
			$message = is_array($decoded) ? (string)($decoded['message'] ?? $body) : $body;
			throw new RuntimeException("GitHub refused {$method} {$path} (HTTP {$status}): {$message}", $status);
		}

		$decoded = json_decode($body, true);

		return is_array($decoded) ? $decoded : [];
	}//end call()

	/**
	 * The same call, but a 4xx answers null instead of throwing.
	 *
	 * Used where absence is an expected answer — does this branch exist, does this
	 * file exist — and must not end the agent's turn.
	 *
	 * ONLY a 4xx. Catching everything made "absent" and "broken" the same answer,
	 * and both callers then take the wrong branch in silence: `putFile()` reads a
	 * 500 on the blob-SHA lookup as "this path is new", omits the `sha`, and gets
	 * a 422 back that reads as a validation problem rather than the outage it is;
	 * `createBranch()` reads it as "no such branch" and tries to create one that
	 * exists. A missing credential funnelled through the same catch, so a
	 * misconfiguration silently flipped a create into an update.
	 *
	 * @param string $method HTTP method.
	 * @param string $path GitHub-relative path.
	 * @param string|null $userId Acting user.
	 *
	 * @return array<string,mixed>|null Null when GitHub said the thing is not there.
	 *
	 * @throws RuntimeException When the call failed for any other reason.
	 */
	private function tryCall(string $method, string $path, ?string $userId): ?array {
		try {
			return $this->call(method: $method, path: $path, payload: null, userId: $userId);
		} catch (RuntimeException $e) {
			$status = $e->getCode();
			if ($status >= 400 && $status < 500) {
				return null;
			}

			throw $e;
		}
	}//end tryCall()
}//end class
