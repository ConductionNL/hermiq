<?php

/**
 * Unit tests for DocumentFeatureRun's refusals and guardrail filtering: an
 * instruction-less run, an unreadable document, a files backend that throws,
 * and the organisation's input and output guardrails.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\AiFeature
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\AiFeature;

use OCA\Hermiq\Service\AiFeature\AiFeatureLookup;
use OCA\Hermiq\Service\AiFeature\DocumentFeatureRun;
use OCA\Hermiq\Service\Chat\AttachmentTextReader;
use OCA\Hermiq\Service\GuardrailBlockedException;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\Literacy\LiteracyRequirement;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A run on a document is refused cleanly, and the organisation's guardrails filter what goes in and out.
 */
#[CoversClass(DocumentFeatureRun::class)]
#[UsesClass(GuardrailBlockedException::class)]
final class DocumentFeatureRunEdgeTest extends TestCase {

	private ProviderFactory&MockObject $providers;
	private AttachmentTextReader&MockObject $reader;
	private IRootFolder&MockObject $root;

	protected function setUp(): void {
		$this->providers = $this->createMock(ProviderFactory::class);
		$this->reader    = $this->createMock(AttachmentTextReader::class);
		$this->root      = $this->createMock(IRootFolder::class);
	}//end setUp()

	/**
	 * The run with an enabled feature in organisation org-1.
	 *
	 * @param GuardrailPolicyService|null $guardrails The guardrail double, if any.
	 *
	 * @return DocumentFeatureRun
	 */
	private function featureRun(?GuardrailPolicyService $guardrails = null): DocumentFeatureRun {
		$feature = new ObjectEntity();
		$feature->setObject(['slug' => 'document-summary', 'lifecycle' => 'enabled']);
		$features = $this->createMock(AiFeatureLookup::class);
		$features->method('findBySlugForGate')->willReturn($feature);

		$literacy = $this->createMock(LiteracyRequirement::class);
		$literacy->method('organisationOf')->with('alice')->willReturn('org-1');

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
	 * A readable file 42 in alice's Files.
	 *
	 * @return void
	 */
	private function aliceHoldsFile42(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getName')->willReturn('besluit.pdf');
		$file->method('getMimetype')->willReturn('application/pdf');
		$file->method('isReadable')->willReturn(true);

		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->with(42)->willReturn([$file]);
		$this->root->method('getUserFolder')->with('alice')->willReturn($folder);
	}//end aliceHoldsFile42()

	/**
	 * Run the feature on file 42 and return the exception it raises.
	 *
	 * @param DocumentFeatureRun $run The run.
	 * @param string $instruction The instruction.
	 *
	 * @return RuntimeException
	 */
	private function refusal(DocumentFeatureRun $run, string $instruction = 'Summarise'): RuntimeException {
		try {
			$run->run(uid: 'alice', featureSlug: 'document-summary', documentReference: '42', instruction: $instruction);
		} catch (RuntimeException $e) {
			return $e;
		}

		$this->fail('The run was admitted.');
	}//end refusal()

	public function testARunWithoutAnInstructionIsRefusedBeforeAnythingIsRead(): void {
		$this->root->expects($this->never())->method('getUserFolder');
		$this->providers->expects($this->never())->method('generateText');

		$e = $this->refusal($this->featureRun(), '   ');

		$this->assertSame(400, $e->getCode());
		$this->assertSame('A feature run on a document needs an instruction', $e->getMessage());
	}//end testARunWithoutAnInstructionIsRefusedBeforeAnythingIsRead()

	public function testADocumentWithoutReadableTextIsUnprocessable(): void {
		$this->aliceHoldsFile42();
		$this->reader->expects($this->once())
			->method('read')
			->with([['fileId' => 42, 'name' => 'besluit.pdf', 'mimeType' => 'application/pdf']], 'alice')
			->willReturn(['text' => "  \n ", 'notices' => []]);
		$this->providers->expects($this->never())->method('generateText');

		$e = $this->refusal($this->featureRun());

		$this->assertSame(422, $e->getCode());
		$this->assertSame('hermiq cannot read the text of this document', $e->getMessage());
	}//end testADocumentWithoutReadableTextIsUnprocessable()

	public function testAFilesBackendFailureIsNotFound(): void {
		$this->root->method('getUserFolder')->willThrowException(new RuntimeException('storage offline'));
		$this->reader->expects($this->never())->method('read');

		$e = $this->refusal($this->featureRun());

		$this->assertSame(404, $e->getCode());
		$this->assertSame('Document not found', $e->getMessage());
	}//end testAFilesBackendFailureIsNotFound()

	public function testAnUnreadableFileIsNotFound(): void {
		$file = $this->createMock(File::class);
		$file->method('isReadable')->willReturn(false);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn([$this->createMock(Folder::class), $file]);
		$this->root->method('getUserFolder')->willReturn($folder);

		$this->assertSame(404, $this->refusal($this->featureRun())->getCode());
	}//end testAnUnreadableFileIsNotFound()

	public function testABlockingInputGuardrailStopsTheRunWithItsReason(): void {
		$this->aliceHoldsFile42();
		$this->reader->method('read')->willReturn(['text' => 'BSN 123456782', 'notices' => []]);
		$this->providers->expects($this->never())->method('generateText');
		$guardrails = $this->createMock(GuardrailPolicyService::class);
		$guardrails->method('effectivePolicyFor')->with('org-1')->willReturn(['p' => 1]);
		$guardrails->method('filterInput')->with(['p' => 1], 'BSN 123456782')->willReturn(['text' => '', 'blocked' => true, 'reason' => 'bsn']);

		try {
			$this->featureRun($guardrails)->run(uid: 'alice', featureSlug: 'document-summary', documentReference: '42', instruction: 'Summarise');
			$this->fail('The blocked document reached the model.');
		} catch (GuardrailBlockedException $e) {
			$this->assertSame('bsn', $e->getReason());
		}
	}//end testABlockingInputGuardrailStopsTheRunWithItsReason()

	public function testTheGuardrailsFilterTheTextSentAndTheOutputReturned(): void {
		$this->aliceHoldsFile42();
		$this->reader->method('read')->willReturn(['text' => 'Naam: Jan', 'notices' => ['n']]);
		$guardrails = $this->createMock(GuardrailPolicyService::class);
		$guardrails->method('effectivePolicyFor')->with('org-1')->willReturn(['p' => 1]);
		$guardrails->expects($this->once())->method('filterInput')->with(['p' => 1], 'Naam: Jan')
			->willReturn(['text' => 'Naam: [redacted]', 'blocked' => false, 'reason' => '']);
		$guardrails->expects($this->once())->method('filterOutput')->with(['p' => 1], 'Jan is aanvrager')
			->willReturn(['text' => ' [redacted] is aanvrager ']);
		$this->providers->expects($this->once())
			->method('generateText')
			->with("Summarise\nNaam: [redacted]", 'alice', true, 'org-1', 'document-summary', '42')
			->willReturn('Jan is aanvrager');

		$this->assertSame(
			[
				'feature' => 'document-summary',
				'documentReference' => '42',
				'output' => '[redacted] is aanvrager',
				'notices' => ['n'],
			],
			$this->featureRun($guardrails)->run(uid: 'alice', featureSlug: 'document-summary', documentReference: ' 42 ', instruction: ' Summarise ')
		);
	}//end testTheGuardrailsFilterTheTextSentAndTheOutputReturned()
}//end class
