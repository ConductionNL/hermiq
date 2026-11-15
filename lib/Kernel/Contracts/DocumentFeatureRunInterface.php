<?php

/**
 * Contract of DocumentFeatureRun
 *
 * The public surface of DocumentFeatureRun that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use OCA\Hermiq\Service\GuardrailBlockedException;
use RuntimeException;

/**
 * Contract of DocumentFeatureRun.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DocumentFeatureRunInterface {

	/**
	 * Run one feature on one document, as the caller.
	 *
	 * @param string $uid The caller.
	 * @param string $featureSlug The registered feature.
	 * @param string $documentReference The document's Nextcloud file id.
	 * @param string $instruction What the feature should do with the document.
	 *
	 * @return array{feature: string, documentReference: string, output: string, notices: list<string>} The result.
	 *
	 * @throws RuntimeException 400 on a missing reference or instruction, 404 on an unknown or unreadable
	 *                          document or feature, 403 on a feature that is switched off, 422 on a document
	 *                          with no text hermiq can read.
	 * @throws GuardrailBlockedException When the organisation's guardrail refuses the document text.
	 *
	 * @spec openspec/changes/ai-feature-run-on-a-document/specs/ai-feature-governance/spec.md#scenario-a-run-without-a-document-reference-is-refused-before-anything-is-read
	 */
	public function run(string $uid, string $featureSlug, string $documentReference, string $instruction): array;
}//end interface
