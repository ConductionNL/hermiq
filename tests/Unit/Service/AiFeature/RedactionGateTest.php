<?php

/**
 * Hermiq redaction-gate unit tests.
 *
 * Covers the boundary this change exists for: a feature that reads no unredacted
 * document is not handed one, hermiq never redacts and never accepts a detection
 * result as a redaction, and an unresolvable filinq refuses rather than proceeds.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\AiFeature
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
 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/woo-llm-anonymisation/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\AiFeature;

use OCA\Hermiq\Service\AiFeature\FeatureProviderResolver;
use OCA\Hermiq\Service\AiFeature\ProviderResidencyRegistry;
use OCA\Hermiq\Service\AiFeature\RedactionOutcomeReader;
use OCA\Hermiq\Service\AiFeature\RedactionRequiredException;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\TenantModelPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The redaction gate in the ordered pre-call path.
 *
 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/woo-llm-anonymisation/spec.md
 */
class RedactionGateTest extends TestCase {

	/**
	 * An AiFeatureService double serving one feature.
	 *
	 * @param array<string, mixed> $data The feature's object data.
	 *
	 * @return AiFeatureService The double.
	 */
	private function features(array $data): AiFeatureService {
		$service = $this->createMock(AiFeatureService::class);
		$service->method('findBySlug')->willReturnCallback(
			static function (string $slug) use ($data): ?ObjectEntity {
				if ($slug !== 'samenvatten') {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setObject(array_merge(['slug' => 'samenvatten'], $data));
				return $entity;
			}
		);

		return $service;
	}//end features()

	/**
	 * A model policy permitting the one pair these tests use.
	 *
	 * @return TenantModelPolicyService The double.
	 */
	private function policy(): TenantModelPolicyService {
		$service = $this->createMock(TenantModelPolicyService::class);
		$service->method('isAllowed')->willReturn(true);
		$service->method('effectivePolicyFor')->willReturn(
			['source' => 'organisation', 'allowed' => [], 'defaultModel' => null]
		);

		return $service;
	}//end policy()

	/**
	 * A residency registry over an empty config.
	 *
	 * @return ProviderResidencyRegistry The registry.
	 */
	private function residency(): ProviderResidencyRegistry {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		return new ProviderResidencyRegistry($config);
	}//end residency()

	/**
	 * A redaction reader over a filinq that is installed and holds the given
	 * anonymisation links.
	 *
	 * @param bool $filinqInstalled Whether filinq resolves at all.
	 * @param array<int, array<string, mixed>> $links The stored anonymizationLink objects.
	 *
	 * @return RedactionOutcomeReader The reader.
	 */
	private function reader(bool $filinqInstalled, array $links = []): RedactionOutcomeReader {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static function (string $app) use ($filinqInstalled): bool {
				if ($filinqInstalled === false) {
					return false;
				}

				return in_array($app, ['filinq', 'docudesk'], true);
			}
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($links): array {
				$out = [];
				foreach ($links as $link) {
					$entity = new ObjectEntity();
					$entity->setObject($link);
					$out[] = $entity;
				}

				return $out;
			}
		);

		return new RedactionOutcomeReader($appManager, $objectService, new NullLogger());
	}//end reader()

	/**
	 * Build the resolver with a given feature and reader.
	 *
	 * @param array<string, mixed> $featureData The feature's object data.
	 * @param RedactionOutcomeReader|null $reader The redaction reader, or null for none at all.
	 *
	 * @return FeatureProviderResolver The resolver.
	 */
	private function resolver(array $featureData, ?RedactionOutcomeReader $reader): FeatureProviderResolver {
		return new FeatureProviderResolver(
			$this->features($featureData),
			$this->policy(),
			$this->residency(),
			$reader
		);
	}//end resolver()

	/**
	 * An unredacted document does not reach the model, and the refusal names the
	 * feature and the reference.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/woo-llm-anonymisation/spec.md#scenario-an-unredacted-document-does-not-reach-the-model
	 */
	public function testAnUnredactedDocumentDoesNotReachTheModel(): void {
		$resolver = $this->resolver(
			['requiresRedaction' => true],
			$this->reader(filinqInstalled: true, links: [])
		);

		try {
			$resolver->enforceForRun(
				featureSlug: 'samenvatten',
				organisation: 'gemeente',
				provider: 'ollama',
				model: 'llama3',
				documentReference: '4711'
			);
			$this->fail('The unredacted document was allowed through.');
		} catch (RedactionRequiredException $refusal) {
			$this->assertSame('redaction', $refusal->step());
			$this->assertSame('samenvatten', $refusal->featureSlug);
			$this->assertSame('4711', $refusal->documentReference);
			$this->assertStringContainsString('samenvatten', $refusal->getMessage());
			$this->assertStringContainsString('4711', $refusal->getMessage());
		}

	}//end testAnUnredactedDocumentDoesNotReachTheModel()

	/**
	 * A note with no document still runs: the requirement attaches to the document,
	 * not to the whole run, so the declaration stays usable on a handler's own text.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/woo-llm-anonymisation/spec.md#scenario-a-note-with-no-document-still-runs
	 */
	public function testANoteWithNoDocumentStillRuns(): void {
		$resolver = $this->resolver(
			['requiresRedaction' => true],
			$this->reader(filinqInstalled: true, links: [])
		);

		$disclosure = $resolver->enforceForRun(
			featureSlug: 'samenvatten',
			organisation: 'gemeente',
			provider: 'ollama',
			model: 'llama3',
			documentReference: null
		);

		$this->assertSame('samenvatten', $disclosure['feature']);
		$this->assertTrue($disclosure['redaction']['satisfied']);
		$this->assertSame('not-applicable', $disclosure['redaction']['status']);
	}//end testANoteWithNoDocumentStillRuns()

	/**
	 * A redacted document proceeds, and the outcome is recorded on the run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/woo-llm-anonymisation/spec.md#scenario-a-redacted-document-proceeds
	 */
	public function testARedactedDocumentProceedsAndIsRecorded(): void {
		$resolver = $this->resolver(
			['requiresRedaction' => true],
			$this->reader(
				filinqInstalled: true,
				links: [
					[
						'sourceFileId' => '4711',
						'status' => 'completed',
						'anonymizedAt' => '2026-09-01T10:00:00+00:00',
					],
				]
			)
		);

		$disclosure = $resolver->enforceForRun(
			featureSlug: 'samenvatten',
			organisation: 'gemeente',
			provider: 'ollama',
			model: 'llama3',
			documentReference: '4711'
		);

		$this->assertTrue($disclosure['redaction']['required']);
		$this->assertTrue($disclosure['redaction']['satisfied']);
		$this->assertSame('2026-09-01T10:00:00+00:00', $disclosure['redaction']['redactedAt']);
	}//end testARedactedDocumentProceedsAndIsRecorded()

	/**
	 * A completed detection does not unlock a redaction-requiring feature. Detection
	 * returns spans over the original text and needs the model to see that text;
	 * accepting it here would mark a run safe for having sent exactly what it must
	 * not have sent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/woo-llm-anonymisation/spec.md#scenario-a-detection-result-does-not-unlock-a-redaction-requiring-feature
	 */
	public function testADetectionResultDoesNotUnlockTheFeature(): void {
		$resolver = $this->resolver(
			['requiresRedaction' => true],
			$this->reader(
				filinqInstalled: true,
				links: [
					[
						'sourceFileId' => '4711',
						// filinq recorded a detection pass over this document, and no
						// anonymisation: entities were found, none were removed.
						'status' => 'detected',
						'anonymizedAt' => '',
					],
				]
			)
		);

		$this->expectException(RedactionRequiredException::class);

		$resolver->enforceForRun(
			featureSlug: 'samenvatten',
			organisation: 'gemeente',
			provider: 'ollama',
			model: 'llama3',
			documentReference: '4711'
		);

	}//end testADetectionResultDoesNotUnlockTheFeature()

	/**
	 * Without filinq, a redaction-requiring feature does not run, and the refusal
	 * says the client is unavailable rather than inventing a verdict.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/woo-llm-anonymisation/spec.md#scenario-without-filinq-a-redaction-requiring-feature-does-not-run
	 */
	public function testWithoutFilinqTheFeatureDoesNotRun(): void {
		$resolver = $this->resolver(
			['requiresRedaction' => true],
			$this->reader(filinqInstalled: false)
		);

		try {
			$resolver->enforceForRun(
				featureSlug: 'samenvatten',
				organisation: 'gemeente',
				provider: 'ollama',
				model: 'llama3',
				documentReference: '4711'
			);
			$this->fail('The run proceeded with no redaction client.');
		} catch (RedactionRequiredException $refusal) {
			$this->assertStringContainsString('filinq', $refusal->getMessage());
			$this->assertStringContainsString('not installed', $refusal->getMessage());
		}

	}//end testWithoutFilinqTheFeatureDoesNotRun()

	/**
	 * A resolver with no reader at all refuses too. A collaborator that was never
	 * wired is the same fact as a client that is not installed, and the fail-closed
	 * answer must not depend on which of the two happened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/woo-llm-anonymisation/spec.md#requirement-a-missing-redaction-client-must-fail-closed
	 */
	public function testAResolverWithNoReaderRefusesToo(): void {
		$resolver = $this->resolver(['requiresRedaction' => true], null);

		$this->expectException(RedactionRequiredException::class);

		$resolver->enforceForRun(
			featureSlug: 'samenvatten',
			organisation: 'gemeente',
			provider: 'ollama',
			model: 'llama3',
			documentReference: '4711'
		);

	}//end testAResolverWithNoReaderRefusesToo()

	/**
	 * A feature that requires nothing is unaffected on the same instance, filinq
	 * present or not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/woo-llm-anonymisation/spec.md#scenario-features-that-require-nothing-are-unaffected
	 */
	public function testAFeatureThatRequiresNothingIsUnaffected(): void {
		$resolver = $this->resolver(
			['requiresRedaction' => false],
			$this->reader(filinqInstalled: false)
		);

		$disclosure = $resolver->enforceForRun(
			featureSlug: 'samenvatten',
			organisation: 'gemeente',
			provider: 'ollama',
			model: 'llama3',
			documentReference: '4711'
		);

		$this->assertFalse($disclosure['redaction']['required']);
		$this->assertTrue($disclosure['redaction']['satisfied']);
	}//end testAFeatureThatRequiresNothingIsUnaffected()

	/**
	 * hermiq ships no redactor. The only thing the codebase does with redaction is
	 * read filinq's outcome, so this asserts over the source rather than over a
	 * behaviour: an app that grew its own redactor would pass every behavioural
	 * test above and still be wrong.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/woo-llm-anonymisation/spec.md#scenario-hermiq-ships-no-redactor
	 */
	public function testHermiqShipsNoRedactor(): void {
		$lib = realpath(__DIR__ . '/../../../../lib');
		$this->assertIsString($lib);

		$offenders = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib));
		foreach ($files as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$name = $file->getBasename('.php');

			// A class whose NAME claims the act is the shape this guards against:
			// hermiq may read a redaction outcome, never produce one.
			if (preg_match('/^(Redactor|Anonymiser|Anonymizer|DocumentRedaction\w*)$/', $name) === 1) {
				$offenders[] = $file->getPathname();
			}
		}

		$this->assertSame([], $offenders, 'hermiq must not ship a redactor: redaction belongs to filinq (decision D13).');
	}//end testHermiqShipsNoRedactor()
}//end class
