<?php

/**
 * Minimal OpenRegister ConfigPublishTarget stub for standalone unit runs and static analysis.
 *
 * Mirrors the constructor and the user-namespace switch of openregister
 * lib/Service/Config/ConfigPublishTarget.php. Registered at TEST TIME only; the
 * real class wins whenever OpenRegister is enabled.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

/**
 * Where a publish goes: the repository, a path in it, the branch and the visibility.
 */
final class ConfigPublishTarget {

	/**
	 * Whether creation may retry under the user's own namespace.
	 *
	 * @var bool
	 */
	private bool $userNamespaceFallback = true;

	/**
	 * Constructor.
	 *
	 * @param string $repo    The `owner/repo`.
	 * @param string $path    A path within the repository.
	 * @param string $branch  The branch.
	 * @param bool   $private Whether a created repository is private.
	 */
	public function __construct(
		public readonly string $repo,
		public readonly string $path,
		public readonly string $branch='main',
		public readonly bool $private=false,
	) {
	}//end __construct()

	/**
	 * A copy that never retries under the user's namespace.
	 *
	 * @return self
	 */
	public function withoutUserNamespaceFallback(): self {
		$copy = clone $this;
		$copy->userNamespaceFallback = false;

		return $copy;
	}//end withoutUserNamespaceFallback()

	/**
	 * Whether creation may retry under the user's namespace.
	 *
	 * @return bool
	 */
	public function allowsUserNamespaceFallback(): bool {
		return $this->userNamespaceFallback;
	}//end allowsUserNamespaceFallback()
}//end class
