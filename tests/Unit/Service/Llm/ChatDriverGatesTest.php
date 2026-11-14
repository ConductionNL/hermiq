<?php

/**
 * Hermiq chat driver gates: what a resolved driver must pass before it is handed out.
 *
 * Golden for the allowed AND refused outcomes of the gates `createChatDriver()` runs:
 * a run that names an AI feature in an organisation takes the feature's binding and
 * the feature's own gates (once for the document, once per attachment); any other run
 * in an organisation takes the model-policy check and then the data-use check; a run
 * outside an organisation takes neither.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
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
 * @spec openspec/changes/ai-feature-run-on-a-document/specs/ai-feature-governance/spec.md#requirement-an-app-can-run-an-ai-feature-on-a-document-it-names-and-the-gates-apply-to-that-document
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use OCA\Hermiq\Service\AiFeature\DataUseGate;
use OCA\Hermiq\Service\AiFeature\DataUseViolationException;
use OCA\Hermiq\Service\AiFeature\FeatureProviderResolver;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ModelPolicyViolationException;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\OrganisationModelPolicyService;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the gates a chat driver passes.
 */
class ChatDriverGatesTest extends TestCase {

	/**
	 * The configured provider: a local Ollama, so no request is ever made.
	 *
	 * @var array<string, mixed>
	 */
	private const LLM_CONFIG = ['chatProvider' => 'ollama', 'ollamaConfig' => ['url' => 'http://localhost:11434', 'chatModel' => 'llama2']];

	/**
	 * The feature resolver double.
	 *
	 * @var FeatureProviderResolver&MockObject
	 */
	private FeatureProviderResolver $features;

	/**
	 * The model-policy double.
	 *
	 * @var OrganisationModelPolicyService&MockObject
	 */
	private OrganisationModelPolicyService $policy;

	/**
	 * The data-use gate double.
	 *
	 * @var DataUseGate&MockObject
	 */
	private DataUseGate $dataUse;

	/**
	 * Fresh doubles for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->features = $this->createMock(FeatureProviderResolver::class);
		$this->policy = $this->createMock(OrganisationModelPolicyService::class);
		$this->dataUse = $this->createMock(DataUseGate::class);
	}//end setUp()

	/**
	 * The factory, with or without the feature resolver.
	 *
	 * @param bool $withFeatures Whether the feature resolver is wired.
	 *
	 * @return ProviderFactory
	 */
	private function factory(bool $withFeatures = true): ProviderFactory {
		$features = null;
		if ($withFeatures === true) {
			$features = $this->features;
		}

		return new ProviderFactory(
			settingsHandler: $this->createMock(LlmSettingsHandler::class),
			taskManager: $this->createMock(IManager::class),
			userSession: $this->createMock(IUserSession::class),
			logger: new NullLogger(),
			modelPolicyService: $this->policy,
			featureResolver: $features,
			dataUseGate: $this->dataUse
		);
	}//end factory()

	/**
	 * A feature run in an organisation takes the feature's binding and gates, per document
	 * and per attachment, and neither the model-policy nor the data-use check.
	 *
	 * @return void
	 */
	public function testAFeatureRunTakesTheBindingAndTheFeatureGates(): void {
		$this->features->expects($this->once())->method('bindingFor')->with('summaries', 'org-a')
			->willReturn(['provider' => 'ollama', 'model' => 'qwen']);
		$calls = [];
		$this->features->expects($this->exactly(3))->method('enforceForRun')->willReturnCallback(
			static function (string $featureSlug, string $organisation, string $provider, string $model, ?string $documentReference) use (&$calls): array {
				$calls[] = [$featureSlug, $organisation, $provider, $model, $documentReference];
				return [];
			}
		);
		$this->policy->expects($this->never())->method('isAllowed');
		$this->dataUse->expects($this->never())->method('enforce');

		$driver = $this->factory()->createChatDriver(
			llmConfig: self::LLM_CONFIG,
			organisation: 'org-a',
			aiFeature: 'summaries',
			documentReference: 'doc-1',
			attachmentReferences: ['att-1', 'att-2']
		);

		$this->assertSame(['ollama', 'qwen'], [$driver->provider, $driver->model]);
		$this->assertSame(
			[['summaries', 'org-a', 'ollama', 'qwen', 'doc-1'], ['summaries', 'org-a', 'ollama', 'qwen', 'att-1'], ['summaries', 'org-a', 'ollama', 'qwen', 'att-2']],
			$calls
		);
	}//end testAFeatureRunTakesTheBindingAndTheFeatureGates()

	/**
	 * A binding without a pair leaves the configured provider and model, and the feature
	 * gates still run.
	 *
	 * @return void
	 */
	public function testAnUnboundFeatureKeepsTheConfiguredPairAndItsGates(): void {
		$this->features->method('bindingFor')->willReturn(['provider' => 'ollama', 'model' => null]);
		$this->features->expects($this->once())->method('enforceForRun')->with('summaries', 'org-a', 'ollama', 'llama2', null);
		$this->policy->expects($this->never())->method('isAllowed');

		$driver = $this->factory()->createChatDriver(llmConfig: self::LLM_CONFIG, organisation: 'org-a', aiFeature: 'summaries');

		$this->assertSame('llama2', $driver->model);
	}//end testAnUnboundFeatureKeepsTheConfiguredPairAndItsGates()

	/**
	 * A feature gate that refuses refuses the driver.
	 *
	 * @return void
	 */
	public function testARefusingFeatureGateRefusesTheDriver(): void {
		$this->features->method('bindingFor')->willReturn(['provider' => null, 'model' => null]);
		$refusal = new DataUseViolationException('org-a', 'ollama', 'trains');
		$this->features->method('enforceForRun')->willThrowException($refusal);

		$this->expectExceptionObject($refusal);

		$this->factory()->createChatDriver(llmConfig: self::LLM_CONFIG, organisation: 'org-a', aiFeature: 'summaries');
	}//end testARefusingFeatureGateRefusesTheDriver()

	/**
	 * Without a feature, or without the feature resolver, a run in an organisation takes
	 * the model-policy check and then the data-use check.
	 *
	 * @return void
	 */
	public function testAPlainRunTakesThePolicyThenTheDataUseCheck(): void {
		$order = [];
		$this->policy->expects($this->exactly(2))->method('isAllowed')->with('org-a', 'ollama', 'llama2')
			->willReturnCallback(static function () use (&$order): bool {
				$order[] = 'policy';
				return true;
			});
		$this->dataUse->expects($this->exactly(2))->method('enforce')->with('org-a', 'ollama')
			->willReturnCallback(static function () use (&$order): void {
				$order[] = 'data-use';
			});
		$this->features->expects($this->never())->method('bindingFor');

		$this->assertInstanceOf(ChatDriver::class, $this->factory()->createChatDriver(llmConfig: self::LLM_CONFIG, organisation: 'org-a', aiFeature: ''));
		$this->assertInstanceOf(ChatDriver::class, $this->factory(withFeatures: false)->createChatDriver(llmConfig: self::LLM_CONFIG, organisation: 'org-a', aiFeature: 'summaries'));
		$this->assertSame(['policy', 'data-use', 'policy', 'data-use'], $order);
	}//end testAPlainRunTakesThePolicyThenTheDataUseCheck()

	/**
	 * A pair outside the organisation's policy is refused (422) before the data-use check;
	 * the instance-wide scope is named as such.
	 *
	 * @return void
	 */
	public function testAPairOutsideThePolicyIsRefused(): void {
		$this->policy->method('isAllowed')->willReturn(false);
		$this->dataUse->expects($this->never())->method('enforce');

		foreach (['org-a' => "organisation 'org-a'", '' => "organisation '(instance-wide)'"] as $organisation => $named) {
			try {
				$this->factory()->createChatDriver(llmConfig: self::LLM_CONFIG, organisation: (string)$organisation);
				$this->fail('An out-of-policy pair was handed out.');
			} catch (ModelPolicyViolationException $e) {
				$this->assertSame(422, $e->getCode());
				$this->assertSame("Refused by the model-policy check: {$named} does not permit provider 'ollama' model 'llama2'.", $e->getMessage());
			}
		}
	}//end testAPairOutsideThePolicyIsRefused()

	/**
	 * A data-use refusal refuses the driver.
	 *
	 * @return void
	 */
	public function testADataUseRefusalRefusesTheDriver(): void {
		$this->policy->method('isAllowed')->willReturn(true);
		$refusal = new DataUseViolationException('org-a', 'ollama', 'trains');
		$this->dataUse->method('enforce')->willThrowException($refusal);

		$this->expectExceptionObject($refusal);

		$this->factory()->createChatDriver(llmConfig: self::LLM_CONFIG, organisation: 'org-a');
	}//end testADataUseRefusalRefusesTheDriver()

	/**
	 * A run outside any organisation takes no gate at all, feature or not.
	 *
	 * @return void
	 */
	public function testARunOutsideAnOrganisationTakesNoGate(): void {
		$this->features->expects($this->never())->method('bindingFor');
		$this->features->expects($this->never())->method('enforceForRun');
		$this->policy->expects($this->never())->method('isAllowed');
		$this->dataUse->expects($this->never())->method('enforce');

		$driver = $this->factory()->createChatDriver(llmConfig: self::LLM_CONFIG, aiFeature: 'summaries');

		$this->assertSame(['ollama', 'llama2'], [$driver->provider, $driver->model]);
	}//end testARunOutsideAnOrganisationTakesNoGate()
}//end class
