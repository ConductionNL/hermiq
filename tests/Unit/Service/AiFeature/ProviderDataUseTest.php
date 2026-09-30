<?php

/**
 * Tests for the provider data-use declaration and the data-use gate
 * (models-no-training-guarantee).
 *
 * The declaration is administered, never inferred; the gate refuses a run on a
 * provider that has not declared it never trains, when the organisation's
 * effective model policy requires that. The ModelPolicy payload is validated
 * against the real register schema fragment with Opis.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\AiFeature;

use InvalidArgumentException;
use OCA\Hermiq\Service\AiFeature\DataUseGate;
use OCA\Hermiq\Service\AiFeature\DataUseViolationException;
use OCA\Hermiq\Service\AiFeature\ProviderDataUseRegistry;
use OCA\Hermiq\Service\Llm\ModelPolicyViolationException;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\TenantModelPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Declaration, policy requirement and gate.
 *
 * @spec openspec/changes/models-no-training-guarantee/specs/provider-data-use/spec.md#requirement-an-organisation-can-require-providers-that-never-train-on-its-data-req-notrain-002
 */
final class ProviderDataUseTest extends TestCase {

	/**
	 * The stored app config value, shared by the registry double.
	 *
	 * @var string
	 */
	private string $stored = '';

	/**
	 * A registry over an in-memory app config.
	 *
	 * @return ProviderDataUseRegistry
	 */
	private function registry(): ProviderDataUseRegistry {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (): string => $this->stored);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame('providerDataUse', $key);
				$this->stored = $value;
				return true;
			}
		);

		return new ProviderDataUseRegistry($config);

	}//end registry()

	/**
	 * A ModelPolicy object.
	 *
	 * @param string $organisation The organisation ('' for the instance default).
	 * @param bool|null $requireNoTraining The flag, or null to leave it out.
	 *
	 * @return ObjectEntity
	 */
	private function policy(string $organisation, ?bool $requireNoTraining): ObjectEntity {
		$data = ['allowed' => [['provider' => 'anthropic', 'models' => []], ['provider' => 'openai', 'models' => []]], 'defaultModel' => null];
		if ($requireNoTraining !== null) {
			$data['requireNoTraining'] = $requireNoTraining;
		}

		$entity = new ObjectEntity();
		$entity->setUuid('p-' . ($organisation === '' ? 'instance' : $organisation));
		$entity->setOrganisation($organisation);
		$entity->setObject($data);

		return $entity;

	}//end policy()

	/**
	 * The policy service over the given stored policies.
	 *
	 * @param array<int, ObjectEntity> $policies The policies.
	 *
	 * @return TenantModelPolicyService
	 */
	private function policies(array $policies): TenantModelPolicyService {
		$objects = new class($policies) extends ObjectService {
			/**
			 * @param array<int, ObjectEntity> $policies The policies.
			 */
			public function __construct(private array $policies) {
			}

			public function setRegister(mixed $register): static {
				return $this;
			}

			public function setSchema(mixed $schema): static {
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->policies;
			}
		};

		return new TenantModelPolicyService(objectService: $objects, settingsHandler: $this->createMock(LlmSettingsHandler::class));

	}//end policies()

	/**
	 * An admin's statement is stored with who made it and when; an undeclared
	 * provider reads `undeclared`; unknown values and providers are refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/models-no-training-guarantee/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
	 */
	public function testAnAdminDeclaresWhatAProviderDoesWithData(): void {
		$registry = $this->registry();

		$this->assertSame('undeclared', $registry->forProvider(provider: 'openai')['dataUse']);

		$stored = $registry->declare(
			provider: 'anthropic',
			dataUse: 'no-training',
			termsReference: 'Anthropic commercial terms, checked 2026-09-01',
			declaredBy: 'admin'
		);

		$this->assertSame('no-training', $stored['dataUse']);
		$this->assertSame('Anthropic commercial terms, checked 2026-09-01', $stored['termsReference']);
		$this->assertSame('admin', $stored['declaredBy']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $stored['declaredAt']);
		$this->assertSame($stored, $this->registry()->forProvider(provider: 'anthropic'));
		$this->assertTrue($registry->neverTrains(provider: 'anthropic'));
		$this->assertFalse($registry->neverTrains(provider: 'openai'));

		foreach ([['anthropic', 'sometimes'], ['skynet', 'no-training']] as [$provider, $value]) {
			try {
				$registry->declare(provider: $provider, dataUse: $value, termsReference: '', declaredBy: 'admin');
				$this->fail("{$provider}/{$value} must be refused");
			} catch (InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}

	}//end testAnAdminDeclaresWhatAProviderDoesWithData()

	/**
	 * Nothing is inferred: a provider name that sounds local is still undeclared.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/models-no-training-guarantee/specs/provider-data-use/spec.md#requirement-a-configured-provider-states-what-it-does-with-data-req-notrain-001
	 */
	public function testNothingIsInferred(): void {
		$this->assertSame('undeclared', $this->registry()->forProvider(provider: 'ollama')['dataUse']);

	}//end testNothingIsInferred()

	/**
	 * An organisation without a policy of its own inherits the instance default's
	 * requirement; an organisation's own policy decides for itself.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/models-no-training-guarantee/specs/provider-data-use/spec.md#requirement-an-organisation-can-require-providers-that-never-train-on-its-data-req-notrain-002
	 */
	public function testTheRequirementIsReadFromTheEffectivePolicy(): void {
		$service = $this->policies([$this->policy('', true), $this->policy('org-own', false)]);

		$this->assertTrue($service->requiresNoTraining(organisation: 'org-without-policy'));
		$this->assertTrue($service->effectivePolicyFor(organisation: 'org-without-policy')['requireNoTraining']);
		$this->assertFalse($service->requiresNoTraining(organisation: 'org-own'));
		$this->assertFalse($this->policies([$this->policy('', null)])->requiresNoTraining(organisation: 'x'), 'Off unless set.');

	}//end testTheRequirementIsReadFromTheEffectivePolicy()

	/**
	 * With the requirement on, an undeclared provider is refused with step
	 * data-use, naming organisation and provider; a zero-retention provider passes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/models-no-training-guarantee/specs/provider-data-use/spec.md#requirement-an-organisation-can-require-providers-that-never-train-on-its-data-req-notrain-002
	 */
	public function testTheGateRefusesAProviderThatMayTrain(): void {
		$registry = $this->registry();
		$registry->declare(provider: 'ollama', dataUse: 'zero-retention', termsReference: 'Own server', declaredBy: 'admin');
		$gate = new DataUseGate($this->policies([$this->policy('', true)]), $registry);

		$gate->enforce(organisation: 'Gemeente Voorbeeld', provider: 'ollama');

		try {
			$gate->enforce(organisation: 'Gemeente Voorbeeld', provider: 'openai');
			$this->fail('openai is undeclared and must be refused');
		} catch (DataUseViolationException $e) {
			$this->assertInstanceOf(ModelPolicyViolationException::class, $e, 'Every existing policy-refusal handler must see it.');
			$this->assertSame('data-use', $e->step());
			$this->assertSame(422, $e->getCode());
			$this->assertSame(
				"Refused by the data-use check: organisation 'Gemeente Voorbeeld' requires providers that never train on its data, "
				. "and provider 'openai' has declared 'undeclared'.",
				$e->getMessage()
			);
		}

		// Without the requirement nothing is refused.
		(new DataUseGate($this->policies([$this->policy('', false)]), $registry))->enforce(organisation: 'x', provider: 'openai');

	}//end testTheGateRefusesAProviderThatMayTrain()

	/**
	 * The ModelPolicy payload the service writes validates against the real
	 * register fragment, and a non-boolean flag does not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/models-no-training-guarantee/specs/provider-data-use/spec.md#requirement-an-organisation-can-require-providers-that-never-train-on-its-data-req-notrain-002
	 */
	public function testThePolicyPayloadMatchesTheRegisterSchema(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/hermiq_register.json'));
		$schema = $register->components->schemas->ModelPolicy;
		$this->assertTrue(isset($schema->properties->requireNoTraining), 'ModelPolicy must declare requireNoTraining.');

		$validator = new Validator();
		$payload = ['allowed' => [['provider' => 'anthropic', 'models' => []]], 'defaultModel' => null, 'requireNoTraining' => true];
		$this->assertTrue($validator->validate(json_decode((string)json_encode(array_filter($payload, static fn ($v): bool => $v !== null))), $schema)->isValid());

		$payload['requireNoTraining'] = 'yes';
		$this->assertFalse($validator->validate(json_decode((string)json_encode(array_filter($payload, static fn ($v): bool => $v !== null))), $schema)->isValid());

	}//end testThePolicyPayloadMatchesTheRegisterSchema()

}//end class
