<?php

/**
 * Every file attached to a turn passes the AI feature's checks at the single
 * chokepoint, one call per file, before the driver is returned and so before any
 * request reaches a model (chat-attachments-and-images, task 3).
 *
 * Built over the real FeatureProviderResolver and RedactionOutcomeReader, so a
 * drift between the factory and the resolver fails here.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
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

/**
 * Tests the per-attachment enforcement in ProviderFactory::createChatDriver().
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
 */
class ProviderFactoryAttachmentsTest extends TestCase {

	/**
	 * A factory whose chat companion feature requires redaction; filinq has
	 * recorded a completed redaction for file 1 only.
	 *
	 * @return ProviderFactory
	 */
	private function factory(): ProviderFactory {
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
			$this->createMock(LlmSettingsHandler::class),
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
	 * Resolve the companion's driver with the given attachments.
	 *
	 * @param array<int, string> $references The attached file ids.
	 *
	 * @return string The resolved provider.
	 */
	private function driverFor(array $references): string {
		return $this->factory()->createChatDriver(
			llmConfig: ['chatProvider' => 'ollama', 'ollamaConfig' => ['url' => 'http://localhost:11434', 'chatModel' => 'llama3']],
			organisation: 'gemeente-tilburg',
			aiFeature: 'chat-companion',
			attachmentReferences: $references
		)->provider;
	}//end driverFor()

	/**
	 * A redacted attachment and no attachment both run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
	 */
	public function testRedactedOrAbsentAttachmentsRun(): void {
		$this->assertSame('ollama', $this->driverFor([]));
		$this->assertSame('ollama', $this->driverFor(['1']));

	}//end testRedactedOrAbsentAttachmentsRun()

	/**
	 * The second of two files has no redaction: the run is refused on that file,
	 * so a clean first file cannot carry an unredacted second one through.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#scenario-a-feature-that-requires-redaction-refuses-an-unredacted-attachment
	 */
	public function testEachAttachmentIsCheckedOnItsOwn(): void {
		try {
			$this->driverFor(['1', '4711']);
			$this->fail('The unredacted second attachment reached the driver.');
		} catch (RedactionRequiredException $refusal) {
			$this->assertSame('4711', $refusal->documentReference);
		}

	}//end testEachAttachmentIsCheckedOnItsOwn()
}//end class
