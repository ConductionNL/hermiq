<?php

/**
 * The optional half of one stage request.
 *
 * Everything about a stage beyond its tree, ref, command and user: the tool tree the
 * command comes from, whether it may push, the credentials it may spend, its
 * ceiling, what it reads back and, for an async stage, its job key. Typed fields rather
 * than an array, because a field silently dropped at this boundary has already cost
 * a release once (`toolRepo`): a misspelt field here is a fatal at the call site.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * The options of one stage request.
 *
 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
 */
final class StageOptions {

	/**
	 * The options.
	 *
	 * @param string $credentialId Broker credential, or '' for a public repo.
	 * @param int $timeoutMs Ceiling for the stage; 0 for the default.
	 * @param string $toolRepo Clone URL of the tree the COMMAND comes from, or ''.
	 * @param string $toolRef Ref for the tool tree; its default branch when empty.
	 * @param array $push Push declaration, or [] for a read-only stage.
	 * @param string $pushCredentialId The injectable forge credential, or '' to reuse $credentialId.
	 * @param string $llmCredentialId The injectable model credential, or '' for a stage without a model.
	 * @param array $collect Artefacts to read back out of the clone, or [].
	 * @param string $jobKey A caller-derived handle for an async stage, or ''.
	 */
	public function __construct(
		public readonly string $credentialId = '',
		public readonly int $timeoutMs = 0,
		public readonly string $toolRepo = '',
		public readonly string $toolRef = '',
		public readonly array $push = [],
		public readonly string $pushCredentialId = '',
		public readonly string $llmCredentialId = '',
		public readonly array $collect = [],
		public readonly string $jobKey = '',
	) {
	}//end __construct()

	/**
	 * The stage's ceiling in milliseconds: its own timeout, or the given default.
	 *
	 * @param int $defaultMs The ceiling when the stage names none.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
	 */
	public function ceilingOr(int $defaultMs): int {
		if ($this->timeoutMs > 0) {
			return $this->timeoutMs;
		}

		return $defaultMs;
	}//end ceilingOr()

	/**
	 * The credential asked to INJECT a forge token: the push credential when one is
	 * named, otherwise the broker credential.
	 *
	 * @return string The credential id, or '' for none.
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
	 */
	public function injectCredentialId(): string {
		if ($this->pushCredentialId !== '') {
			return $this->pushCredentialId;
		}

		return $this->credentialId;
	}//end injectCredentialId()

	/**
	 * The ref the tool tree is archived at: its own ref, or HEAD.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/exapp-stage-workload/specs/exapp-stage-workload/spec.md
	 */
	public function toolArchiveRef(): string {
		if ($this->toolRef !== '') {
			return $this->toolRef;
		}

		return 'HEAD';
	}//end toolArchiveRef()
}//end class
