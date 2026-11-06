<?php

/**
 * Runs one registered AI feature on one document an app names.
 *
 * 🔴 THE DOCUMENT REFERENCE IS REQUIRED, NOT ADDED WHEN CONVENIENT. A feature
 * that declares `requiresRedaction` is refused on a document that has not been
 * redacted, and that gate can only apply to a document it is told about. A run
 * that quietly omits the reference is a run nobody checked, and it looks exactly
 * like success. So a missing reference is a 400 here, before anything is read.
 *
 * 🔴 THE DOCUMENT IS READ AS THE CALLER. The file is resolved in the caller's own
 * Files, so a reference to a file they cannot open is a 404, the same as one that
 * does not exist. hermiq never reads a document on a caller's behalf that the
 * caller could not read.
 *
 * 🔑 EVERY PRE-CALL GATE RUNS ON THIS DOCUMENT. The feature and the reference go
 * to ProviderFactory::generateText(), whose createChatDriver() applies the model
 * policy, data use, residency and redaction gates, in that order, before any
 * request is built. A refusal leaves here as the gate's own exception, so the
 * controller can name the step that refused.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\AiFeature
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-feature-run-on-a-document/specs/ai-feature-governance/spec.md#requirement-an-app-can-run-an-ai-feature-on-a-document-it-names-and-the-gates-apply-to-that-document
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\AiFeature;

use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\Chat\AttachmentTextReader;
use OCA\Hermiq\Service\GuardrailBlockedException;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\Literacy\LiteracyRequirement;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use RuntimeException;
use Throwable;

/**
 * Runs an AI feature on a document reference.
 *
 * @spec openspec/changes/ai-feature-run-on-a-document/specs/ai-feature-governance/spec.md#requirement-an-app-can-run-an-ai-feature-on-a-document-it-names-and-the-gates-apply-to-that-document
 */
class DocumentFeatureRun {

	/**
	 * The longest instruction an app may send with a run.
	 *
	 * @var integer
	 */
	private const MAX_INSTRUCTION = 2000;

	/**
	 * Constructor.
	 *
	 * @param AiFeatureService            $features        Reads the feature register.
	 * @param LiteracyRequirement         $literacy        The course requirement and the caller's organisation.
	 * @param IRootFolder                 $rootFolder      Resolves the document in the caller's Files.
	 * @param AttachmentTextReader        $textReader      Reads a file's text as the caller.
	 * @param ProviderFactory             $providerFactory Generates, after every pre-call gate.
	 * @param GuardrailPolicyService|null $guardrails      The organisation's guardrail filters, when installed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AiFeatureService $features,
		private readonly LiteracyRequirement $literacy,
		private readonly IRootFolder $rootFolder,
		private readonly AttachmentTextReader $textReader,
		private readonly ProviderFactory $providerFactory,
		private readonly ?GuardrailPolicyService $guardrails = null,
	) {
	}//end __construct()

	/**
	 * Run one feature on one document, as the caller.
	 *
	 * @param string $uid               The caller.
	 * @param string $featureSlug       The registered feature.
	 * @param string $documentReference The document's Nextcloud file id.
	 * @param string $instruction       What the feature should do with the document.
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
	public function run(string $uid, string $featureSlug, string $documentReference, string $instruction): array {
		$documentReference = trim($documentReference);
		$instruction = trim($instruction);
		if ($documentReference === '' || ctype_digit($documentReference) === false) {
			throw new RuntimeException('A feature run on a document needs the document reference', 400);
		}

		if ($instruction === '') {
			throw new RuntimeException('A feature run on a document needs an instruction', 400);
		}

		$this->literacy->assertMayUseAgents(uid: $uid);
		$this->assertEnabled(featureSlug: $featureSlug);

		$file = $this->readableFile(uid: $uid, fileId: (int)$documentReference);
		$read = $this->textReader->read(
			attachments: [['fileId' => $file->getId(), 'name' => $file->getName(), 'mimeType' => $file->getMimetype()]],
			speaker: $uid
		);
		if (trim($read['text']) === '') {
			throw new RuntimeException('hermiq cannot read the text of this document', 422);
		}

		$organisation = $this->literacy->organisationOf(uid: $uid);
		$text = $this->filterInput(organisation: $organisation, text: $read['text']);

		$output = $this->providerFactory->generateText(
			prompt: mb_substr($instruction, 0, self::MAX_INSTRUCTION) . "\n" . $text,
			userId: $uid,
			organisation: $organisation,
			aiFeature: $featureSlug,
			documentReference: $documentReference
		);

		return [
			'feature' => $featureSlug,
			'documentReference' => $documentReference,
			'output' => trim($this->filterOutput(organisation: $organisation, text: $output)),
			'notices' => $read['notices'],
		];
	}//end run()

	/**
	 * Refuse a feature that is unknown or switched off.
	 *
	 * @param string $featureSlug The feature.
	 *
	 * @return void
	 *
	 * @throws RuntimeException 404 when unknown, 403 when switched off.
	 */
	private function assertEnabled(string $featureSlug): void {
		$feature = $this->features->findBySlugForGate(slug: $featureSlug);
		if ($feature === null) {
			throw new RuntimeException('AI feature not found', 404);
		}

		if ((string)($feature->getObject()['lifecycle'] ?? '') !== AiFeatureService::RESULT_ENABLED) {
			throw new RuntimeException('The organisation has switched this AI feature off', 403);
		}
	}//end assertEnabled()

	/**
	 * The document as a file in the caller's own Files.
	 *
	 * @param string $uid    The caller.
	 * @param int    $fileId The file id.
	 *
	 * @return File The file.
	 *
	 * @throws RuntimeException 404 when the caller cannot open it.
	 */
	private function readableFile(string $uid, int $fileId): File {
		try {
			$nodes = $this->rootFolder->getUserFolder($uid)->getById($fileId);
		} catch (Throwable $e) {
			$nodes = [];
		}

		foreach ($nodes as $node) {
			if ($node instanceof File && $node->isReadable() === true) {
				return $node;
			}
		}

		throw new RuntimeException('Document not found', 404);
	}//end readableFile()

	/**
	 * Apply the organisation's input guardrail, when one is installed.
	 *
	 * @param string $organisation The organisation.
	 * @param string $text         The document text.
	 *
	 * @return string The text to send.
	 *
	 * @throws GuardrailBlockedException When the guardrail refuses the text.
	 */
	private function filterInput(string $organisation, string $text): string {
		if ($this->guardrails === null) {
			return $text;
		}

		$input = $this->guardrails->filterInput(
			policy: $this->guardrails->effectivePolicyFor(organisation: $organisation),
			text: $text
		);
		if ($input['blocked'] === true) {
			throw new GuardrailBlockedException(reason: (string)$input['reason']);
		}

		return (string)$input['text'];
	}//end filterInput()

	/**
	 * Apply the organisation's output guardrail, when one is installed.
	 *
	 * @param string $organisation The organisation.
	 * @param string $text         The model's output.
	 *
	 * @return string The output to return.
	 */
	private function filterOutput(string $organisation, string $text): string {
		if ($this->guardrails === null) {
			return $text;
		}

		return (string)$this->guardrails->filterOutput(
			policy: $this->guardrails->effectivePolicyFor(organisation: $organisation),
			text: $text
		)['text'];
	}//end filterOutput()
}//end class
