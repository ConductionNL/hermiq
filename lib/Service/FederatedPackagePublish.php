<?php

/**
 * Hermiq FederatedPackagePublish.
 *
 * Commits a store package's files through OpenRegister's shared engine,
 * `FederatedConfigService::publishFiles()`, when this instance has it: one
 * Git Data commit on the branch tip's tree, keeping every path the publish does
 * not write. Hermiq itself still creates the repository, refuses an existing
 * one for a first publish, checks that a republished one exists, and sets its
 * topics, so the engine is handed a repository that is already there and the
 * branch that repository reports. Without the engine, or when it throws, the
 * answer is null and hermiq commits through its own Git Data writer.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-3
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Commit store packages through OpenRegister's federated config engine.
 *
 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-3
 */
final class FederatedPackagePublish {

	/**
	 * The engine's publish target value object.
	 */
	public const TARGET_CLASS = 'OCA\\OpenRegister\\Service\\Config\\ConfigPublishTarget';

	/**
	 * The engine's multi-file publish request.
	 */
	public const REQUEST_CLASS = 'OCA\\OpenRegister\\Service\\Config\\RepoFilesPublish';

	/**
	 * Constructor.
	 *
	 * @param FederatedEngineLocator $locator Finds the engine and builds the broker identity.
	 * @param LoggerInterface        $logger  Where a failed engine commit is noted.
	 */
	public function __construct(
		private readonly FederatedEngineLocator $locator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Commit files onto the run's branch of its existing repository.
	 *
	 * @param GitHubPublishRun      $run     The publish: repository, branch and credential.
	 * @param array<string, string> $files   The `path => contents` to commit.
	 * @param string                $message The commit message.
	 *
	 * @return string|null The commit sha, or null when hermiq must commit itself.
	 *
	 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-3
	 */
	public function commit(GitHubPublishRun $run, array $files, string $message): ?string {
		$engine = $this->engine();
		if ($engine === null || $files === []) {
			return null;
		}

		try {
			$answer = (array)$engine->publishFiles($this->request(run: $run, files: $files, message: $message));
		} catch (Throwable $e) {
			$this->logger->warning(message: '[FederatedPackagePublish] engine commit failed, committing directly: ' . $e->getMessage());
			return null;
		}

		$commit = (string)($answer['commit'] ?? '');
		if ($commit === '') {
			return null;
		}

		return $commit;
	}//end commit()

	/**
	 * The engine, when this instance has it with the request classes the publish needs.
	 *
	 * @return object|null The engine, or null.
	 */
	private function engine(): ?object {
		if (class_exists(self::TARGET_CLASS) === false || class_exists(self::REQUEST_CLASS) === false) {
			return null;
		}

		return $this->locator->engineWith(method: 'publishFiles');
	}//end engine()

	/**
	 * The engine request: the existing repository at the run's branch, no topics
	 * (hermiq has set them), the run's credential and hermiq's identity.
	 *
	 * @param GitHubPublishRun      $run     The publish.
	 * @param array<string, string> $files   The files.
	 * @param string                $message The commit message.
	 *
	 * @return object The engine's RepoFilesPublish.
	 */
	private function request(GitHubPublishRun $run, array $files, string $message): object {
		$targetClass = self::TARGET_CLASS;
		$requestClass = self::REQUEST_CLASS;
		$repo = $run->target->owner . '/' . $run->target->repo;
		$target = new $targetClass(repo: $repo, path: (string)array_key_first($files), branch: $run->branch, private: ($run->visibility === 'private'));
		$identity = $this->locator->identityFor(actingUserId: $run->credential->actingUserId);

		return new $requestClass(
			target: $target->withoutUserNamespaceFallback(),
			files: $files,
			message: $message,
			credentialId: $run->credential->credentialId,
			identity: $identity
		);
	}//end request()
}//end class
