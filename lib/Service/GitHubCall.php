<?php

/**
 * Hermiq GitHubCall.
 *
 * One GitHub API call routed through the broker: method and path, and
 * optionally a JSON body and whether a failure is logged. withBody() and
 * quietly() return a changed copy; a call never changes once built.
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
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * One GitHub API call.
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
 */
final class GitHubCall {

	/**
	 * The JSON body, or null for none.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $body = null;

	/**
	 * Whether a failure of this call goes unlogged.
	 *
	 * @var bool
	 */
	private bool $quiet = false;

	/**
	 * Build the call.
	 *
	 * @param string $method The HTTP method.
	 * @param string $path The GitHub-relative path.
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $path,
	) {
	}//end __construct()

	/**
	 * This call with a JSON body.
	 *
	 * @param array<string, mixed> $body The body.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
	 */
	public function withBody(array $body): self {
		$call = clone $this;
		$call->body = $body;
		return $call;
	}//end withBody()

	/**
	 * This call with its failure left unlogged.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
	 */
	public function quietly(): self {
		$call = clone $this;
		$call->quiet = true;
		return $call;
	}//end quietly()

	/**
	 * Whether a failure of this call goes unlogged.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
	 */
	public function isQuiet(): bool {
		return $this->quiet;
	}//end isQuiet()

	/**
	 * The body as JSON, or null when there is none.
	 *
	 * @return string|null The encoded body.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
	 */
	public function encodedBody(): ?string {
		if ($this->body === null) {
			return null;
		}

		return (string)json_encode($this->body);
	}//end encodedBody()
}//end class
