<?php

/**
 * Minimal OpenRegister RepoFilesPublish stub for standalone unit runs and static analysis.
 *
 * Mirrors the constructor of openregister lib/Service/Config/RepoFilesPublish.php.
 * Registered at TEST TIME only; the real class wins whenever OpenRegister is enabled.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

/**
 * Many files published into a repository as one commit.
 */
final class RepoFilesPublish {

	/**
	 * Constructor.
	 *
	 * @param ConfigPublishTarget   $target       Where the files go.
	 * @param array<string, string> $files        The `path => contents`.
	 * @param string                $message      The commit message.
	 * @param string                $credentialId The broker credential.
	 * @param BrokerIdentity|null   $identity     The app and acting user.
	 * @param array<int, string>    $topics       Topics to merge onto the repository.
	 */
	public function __construct(
		public readonly ConfigPublishTarget $target,
		public readonly array $files,
		public readonly string $message,
		public readonly string $credentialId,
		public readonly ?BrokerIdentity $identity = null,
		public readonly array $topics = [],
	) {
	}//end __construct()
}//end class
