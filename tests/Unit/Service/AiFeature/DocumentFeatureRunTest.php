<?php

/**
 * Unit tests for DocumentFeatureRun: the reference is required before anything
 * is read, the document is read as the caller, and the feature and the
 * reference reach the gated generation.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\AiFeature
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

namespace OCA\Hermiq\Tests\Unit\Service\AiFeature;

use OCA\Hermiq\Service\AiFeature\DocumentFeatureRun;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\Chat\AttachmentTextReader;
use OCA\Hermiq\Service\GuardrailBlockedException;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\Literacy\LiteracyRequirement;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\AiFeature\DocumentFeatureRun
 */
class DocumentFeatureRunTest extends TestCase {

	/**
	 * The generation double.
	 *
	 * @var ProviderFactory&MockObject
	 */
	private ProviderFactory&MockObject $providers;

	/**
	 * The text reader double.
	 *
	 * @var AttachmentTextReader&MockObject
	 */
	private AttachmentTextReader&MockObject $reader;

	/**
	 * The root folder double.
	 *
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder&MockObject $root;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->providers = $this->createMock(ProviderFactory::class);
		$this->reader = $this->createMock(AttachmentTextReader::class);
		$this->root = $this->createMock(IRootFolder::class);
	}//end setUp()

	/**
	 * Build the run.
	 *
	 * @param string                      $lifecycle  The feature's lifecycle, or '' for an unknown feature.
	 * @param GuardrailPolicyService|null $guardrails The guardrails, when installed.
	 *
	 * @return DocumentFeatureRun The run.
	 */
	private function featureRun(string $lifecycle = 'enabled', ?GuardrailPolicyService $guardrails = null): DocumentFeatureRun {
		$features = $this->createMock(AiFeatureService::class);
		$features->method('findBySlugForGate')->willReturnCallback(
			static function () use ($lifecycle): ?ObjectEntity {
				if ($lifecycle === '') {
					return null;
				}

				$feature = new ObjectEntity();
				$feature->setObject(['slug' => 'document-summary', 'lifecycle' => $lifecycle]);
				return $feature;
			}
		);

		$literacy = $this->createMock(LiteracyRequirement::class);
		$literacy->method('organisationOf')->willReturn('org-1');

		return new DocumentFeatureRun(
			features: $features,
			literacy: $literacy,
			rootFolder: $this->root,
			textReader: $this->reader,
			providerFactory: $this->providers,
			guardrails: $guardrails,
		);
	}//end featureRun()

	/**
	 * The caller's Files hold file 42.
	 *
	 * @return void
	 */
	private function callerHoldsTheFile(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getName')->willReturn('besluit.pdf');
		$file->method('getMimetype')->willReturn('application/pdf');
		$file->method('isReadable')->willReturn(true);

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback(static fn (int $id): array => ($id === 42 ? [$file] : []));
		$this->root->method('getUserFolder')->with('alice')->willReturn($folder);
	}//end callerHoldsTheFile()

	/**
	 * A run without a reference is refused before anything is read or sent.
	 *
	 * @return void
	 */
	public function testARunWithoutAReferenceIsRefusedBeforeAnythingIsRead(): void {
		$this->root->expects($this->never())->method('getUserFolder');
		$this->providers->expects($this->never())->method('generateText');

		foreach (['', '   ', 'not-a-file-id'] as $reference) {
			try {
				$this->featureRun()->run(uid: 'alice', featureSlug: 'document-summary', documentReference: $reference, instruction: 'Summarise');
				self::fail(message: 'A run without a usable reference was admitted: '.$reference);
			} catch (RuntimeException $e) {
				self::assertSame(expected: 400, actual: $e->getCode());
			}
		}
	}//end testARunWithoutAReferenceIsRefusedBeforeAnythingIsRead()

	/**
	 * The feature and the reference reach the gated generation, with the document's text.
	 *
	 * @return void
	 */
	public function testTheFeatureAndTheReferenceReachTheGatedGeneration(): void {
		$this->callerHoldsTheFile();
		$this->reader->method('read')->willReturn(['text' => 'De aanvraag wordt verleend.', 'notices' => ['hermiq used the text']]);
		$this->providers->expects($this->once())->method('generateText')
			->with(
				$this->stringContains('De aanvraag wordt verleend.'),
				'alice',
				true,
				'org-1',
				'document-summary',
				'42'
			)
			->willReturn(' Verleend. ');

		$result = $this->featureRun()->run(uid: 'alice', featureSlug: 'document-summary', documentReference: '42', instruction: 'Summarise');

		self::assertSame(expected: 'Verleend.', actual: $result['output']);
		self::assertSame(expected: '42', actual: $result['documentReference']);
		self::assertSame(expected: ['hermiq used the text'], actual: $result['notices']);
	}//end testTheFeatureAndTheReferenceReachTheGatedGeneration()

	/**
	 * A document the caller cannot open is not found, and nothing is sent.
	 *
	 * @return void
	 */
	public function testADocumentTheCallerCannotOpenIsNotFound(): void {
		$this->callerHoldsTheFile();
		$this->providers->expects($this->never())->method('generateText');

		$this->expectExceptionCode(404);
		$this->featureRun()->run(uid: 'alice', featureSlug: 'document-summary', documentReference: '7', instruction: 'Summarise');
	}//end testADocumentTheCallerCannotOpenIsNotFound()

	/**
	 * An unknown feature is 404, a switched-off one 403, and neither reads the document.
	 *
	 * @return void
	 */
	public function testAnUnknownOrSwitchedOffFeatureReadsNothing(): void {
		$this->root->expects($this->never())->method('getUserFolder');

		foreach (['' => 404, 'disabled' => 403] as $lifecycle => $status) {
			try {
				$this->featureRun(lifecycle: (string)$lifecycle)->run(uid: 'alice', featureSlug: 'x', documentReference: '42', instruction: 'Summarise');
				self::fail(message: 'The run was admitted.');
			} catch (RuntimeException $e) {
				self::assertSame(expected: $status, actual: $e->getCode());
			}
		}
	}//end testAnUnknownOrSwitchedOffFeatureReadsNothing()

	/**
	 * The organisation's guardrail can refuse the document text before it is sent.
	 *
	 * @return void
	 */
	public function testTheGuardrailCanRefuseTheDocumentText(): void {
		$this->callerHoldsTheFile();
		$this->reader->method('read')->willReturn(['text' => 'BSN 123456782', 'notices' => []]);
		$this->providers->expects($this->never())->method('generateText');

		$guardrails = $this->createMock(GuardrailPolicyService::class);
		$guardrails->method('effectivePolicyFor')->willReturn([]);
		$guardrails->method('filterInput')->willReturn(['text' => '', 'blocked' => true, 'reason' => 'bsn']);

		$this->expectException(GuardrailBlockedException::class);
		$this->featureRun(guardrails: $guardrails)->run(uid: 'alice', featureSlug: 'document-summary', documentReference: '42', instruction: 'Summarise');
	}//end testTheGuardrailCanRefuseTheDocumentText()
}//end class
