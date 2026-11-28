<?php

/**
 * Hermiq FederatedCatalogRead.
 *
 * The store's install-time repository reads through OpenRegister's shared
 * engine, `FederatedConfigService::fetchPackage()`, when this instance has it:
 * a bundle (its manifest plus `skills/` and `agents/`) and a skill's auxiliary
 * files. The engine reads one tarball where it can, else the tree and one file
 * at a time, names hermiq and the acting user on a brokered read, and retries
 * anonymously when the broker refuses. Without the engine, or when it throws
 * or finds nothing, the answer is null and the store reads GitHub itself.
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
 * Read store packages through OpenRegister's federated config engine.
 *
 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-3
 */
final class FederatedCatalogRead {

	/**
	 * Constructor.
	 *
	 * @param FederatedEngineLocator $locator Finds the engine and builds the broker identity.
	 * @param LoggerInterface        $logger  Where a failed engine read is noted.
	 */
	public function __construct(
		private readonly FederatedEngineLocator $locator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * A bundle's manifest and its `skills/` and `agents/` files.
	 *
	 * @param CatalogFetch $fetch    The repository, ref and caller.
	 * @param string       $manifest The manifest path.
	 * @param array<int, string> $prefixes The folder prefixes the bundle keeps (each ending in `/`).
	 *
	 * @return array{files: array<string, string>, truncated: bool}|null The bundle, or null when the
	 *         engine is absent, failed, or found no manifest.
	 *
	 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-3
	 */
	public function bundle(CatalogFetch $fetch, string $manifest, array $prefixes): ?array {
		$package = $this->package(fetch: $fetch, paths: array_merge([$manifest], $prefixes));
		if ($package === null || isset($package['files'][$manifest]) === false) {
			return null;
		}

		return ['files' => $package['files'], 'truncated' => $package['truncated']];
	}//end bundle()

	/**
	 * A repository's files that `$accept` keeps, collected into the fetch within its file and byte bounds.
	 *
	 * @param CatalogFetch $fetch  The repository, ref, caller and bounds; its files are filled in.
	 * @param callable     $accept Whether a path belongs in the set: `fn (string $path): bool`.
	 *
	 * @return bool True when the engine answered, false when the store must read GitHub itself.
	 *
	 * @spec openspec/changes/store-through-federated-config/tasks.md#task-3-3
	 */
	public function collect(CatalogFetch $fetch, callable $accept): bool {
		$package = $this->package(fetch: $fetch, paths: []);
		if ($package === null) {
			return false;
		}

		foreach ($package['files'] as $path => $contents) {
			if ($accept((string)$path) === false) {
				continue;
			}

			if ($fetch->isFull() === true) {
				$fetch->truncated = true;
				break;
			}

			$fetch->bytes += strlen($contents);
			$fetch->files[(string)$path] = $contents;
		}

		if ($package['truncated'] === true) {
			$fetch->truncated = true;
		}

		return true;
	}//end collect()

	/**
	 * The engine's read of a repository's files, or null when there is none to have.
	 *
	 * @param CatalogFetch       $fetch The repository, ref and caller.
	 * @param array<int, string> $paths Exact paths, and prefixes ending in `/`; [] for every file.
	 *
	 * @return array{files: array<string, string>, truncated: bool}|null The files, or null.
	 */
	private function package(CatalogFetch $fetch, array $paths): ?array {
		$engine = $this->locator->engineWith(method: 'fetchPackage');
		if ($engine === null) {
			return null;
		}

		try {
			$answer = (array)$engine->fetchPackage(
				repo: $fetch->owner . '/' . $fetch->repo,
				ref: $fetch->ref,
				paths: $paths,
				credentialId: $fetch->credentialId,
				identity: $this->locator->identityFor(actingUserId: $fetch->actingUserId)
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[FederatedCatalogRead] engine read failed, reading GitHub directly: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		$files = array_filter((array)($answer['files'] ?? []), 'is_string');
		if ($files === []) {
			// Nothing read: an unreachable repository or an engine that could not get in. The
			// store's own path has its own fallbacks, so it gets the last word.
			return null;
		}

		return ['files' => $files, 'truncated' => (($answer['truncated'] ?? false) === true)];
	}//end package()
}//end class
