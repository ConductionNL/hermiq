<?php

/**
 * ProviderFactory::generateText() hands the AI feature and the document
 * reference to the pre-call gates, so a run on an unredacted document is
 * refused before any request.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-feature-run-on-a-document/specs/ai-feature-governance/spec.md#scenario-the-document-reference-reaches-the-redaction-gate
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use OCA\Hermiq\Service\AiFeature\FeatureProviderResolver;
use OCA\Hermiq\Service\AiFeature\ProviderResidencyRegistry;
use OCA\Hermiq\Service\AiFeature\RedactionOutcomeReader;
use OCA\Hermiq\Service\AiFeature\RedactionRequiredException;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\TenantModelPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Throwable;

/**
 * @covers \OCA\Hermiq\Service\Llm\ProviderFactory::generateText
 *
 * @uses \OCA\Hermiq\Service\AiFeature\FeatureProviderResolver
 * @uses \OCA\Hermiq\Service\AiFeature\ProviderResidencyRegistry
 * @uses \OCA\Hermiq\Service\AiFeature\RedactionOutcomeReader
 * @uses \OCA\Hermiq\Service\AiFeature\RedactionRequiredException
 * @uses \OCA\Hermiq\Service\Llm\ChatDriver
 * @uses \OCA\Hermiq\Service\Llm\ProviderFactory
 * @uses \OCA\Hermiq\Support\FleetAppId
 */
class ProviderFactoryGenerateTextFeatureTest extends TestCase {

	/**
	 * The real factory over the real resolver; file 1 is redacted, file 2 is not.
	 *
	 * @return ProviderFactory The factory.
	 */
	private function factory(): ProviderFactory {
		$settings = $this->createMock(LlmSettingsHandler::class);
		$settings->method('getLLMSettingsOnly')->willReturn(
			// An address nothing listens on, so a run the gates admit fails at the
			// transport and never reaches a real model.
			['chatProvider' => 'ollama', 'ollamaConfig' => ['url' => 'http://127.0.0.1:9', 'chatModel' => 'llama3']]
		);

		$features = $this->createMock(AiFeatureService::class);
		$features->method('findBySlug')->willReturnCallback(
			static function (string $slug): ?ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setObject(['slug' => $slug, 'requiresRedaction' => true]);
				return $entity;
			}
		);

		$policy = $this->createMock(TenantModelPolicyService::class);
		$policy->method('isAllowed')->willReturn(true);
		$policy->method('effectivePolicyFor')->willReturn(['source' => 'organisation', 'allowed' => [], 'defaultModel' => null]);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(static fn (string $app): bool => in_array($app, ['filinq', 'docudesk'], true));

		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(
			static function (): array {
				$link = new ObjectEntity();
				$link->setObject(['sourceFileId' => '1', 'status' => 'completed', 'anonymizedAt' => '2026-09-01T10:00:00+00:00']);
				return [$link];
			}
		);

		$resolver = new FeatureProviderResolver(
			$features,
			$policy,
			new ProviderResidencyRegistry($config),
			new RedactionOutcomeReader($appManager, $objects, new NullLogger())
		);

		return new ProviderFactory(
			$settings,
			$this->createMock(IManager::class),
			$this->createMock(IUserSession::class),
			new NullLogger(),
			'hermiq',
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			$resolver
		);
	}//end factory()

	/**
	 * An unredacted document is refused by the redaction gate inside generateText().
	 *
	 * @return void
	 */
	public function testAnUnredactedDocumentIsRefusedBeforeAnyRequest(): void {
		$this->expectException(RedactionRequiredException::class);

		$this->factory()->generateText(
			prompt: 'Summarise',
			organisation: 'gemeente-tilburg',
			aiFeature: 'chat-companion',
			documentReference: '2'
		);
	}//end testAnUnredactedDocumentIsRefusedBeforeAnyRequest()

	/**
	 * A redacted document passes the gate; what stops it is the transport.
	 *
	 * @return void
	 */
	public function testARedactedDocumentPassesTheGate(): void {
		try {
			$this->factory()->generateText(
				prompt: 'Summarise',
				organisation: 'gemeente-tilburg',
				aiFeature: 'chat-companion',
				documentReference: '1'
			);
		} catch (Throwable $e) {
			self::assertNotInstanceOf(RedactionRequiredException::class, $e);
			return;
		}

		self::assertTrue(true);
	}//end testARedactedDocumentPassesTheGate()
}//end class
