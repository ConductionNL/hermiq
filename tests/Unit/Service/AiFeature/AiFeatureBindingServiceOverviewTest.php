<?php

/**
 * Unit tests for AiFeatureBindingService: the per-feature overview and the
 * refusals bind() raises before anything is written.
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

use InvalidArgumentException;
use OCA\Hermiq\Service\AiFeature\AiFeatureBindingService;
use OCA\Hermiq\Service\AiFeature\FeatureProviderResolver;
use OCA\Hermiq\Service\AiFeature\ProviderDeclarationStore;
use OCA\Hermiq\Service\AiFeature\ProviderResidencyRegistry;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\OrganisationModelPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The AI-feature overview shows each feature's resolved binding and where its provider runs.
 */
#[CoversClass(AiFeatureBindingService::class)]
#[UsesClass(ProviderResidencyRegistry::class)]
#[UsesClass(ProviderDeclarationStore::class)]
final class AiFeatureBindingServiceOverviewTest extends TestCase {

	private AiFeatureService&MockObject $features;
	private OrganisationModelPolicyService&MockObject $policy;
	private ObjectService&MockObject $objects;
	private IAppConfig&MockObject $config;

	protected function setUp(): void {
		$this->features = $this->createMock(AiFeatureService::class);
		$this->policy   = $this->createMock(OrganisationModelPolicyService::class);
		$this->objects  = $this->createMock(ObjectService::class);
		$this->config   = $this->createMock(IAppConfig::class);
		$this->config->method('getValueString')->willReturn(
			json_encode(['ollama' => ['residency' => 'on-premise', 'location' => 'Rack 4']])
		);
	}//end setUp()

	/**
	 * The service under test, with a real residency registry over the config double.
	 *
	 * @return AiFeatureBindingService
	 */
	private function service(): AiFeatureBindingService {
		return new AiFeatureBindingService(
			$this->features,
			$this->policy,
			new ProviderResidencyRegistry($this->config),
			$this->objects
		);
	}//end service()

	/**
	 * A stored AI feature.
	 *
	 * @param string $uuid The feature uuid.
	 * @param array<string, mixed> $data The feature object.
	 * @param string|null $organisation The owning organisation.
	 *
	 * @return ObjectEntity
	 */
	private function feature(string $uuid, array $data, ?string $organisation): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOrganisation($organisation);
		$entity->setObject($data);
		return $entity;
	}//end feature()

	public function testOverviewListsEachFeatureWithItsBindingAndResidency(): void {
		$this->features->method('listFeatures')->willReturn([
			$this->feature('f-1', ['slug' => 'samenvatten', 'name' => 'Samenvatten'], 'gemeente'),
			$this->feature('f-2', ['slug' => 'vertalen', 'name' => 'Vertalen'], null),
		]);
		$resolver = $this->createMock(FeatureProviderResolver::class);
		$resolver->expects($this->exactly(2))->method('bindingFor')->willReturnCallback(
			static function (string $featureSlug, string $organisation): array {
				if ($featureSlug === 'samenvatten') {
					TestCase::assertSame('gemeente', $organisation);
					return ['provider' => 'ollama', 'model' => 'llama3', 'requiredResidency' => 'on-premise', 'requiresRedaction' => false, 'source' => 'feature'];
				}

				TestCase::assertSame('', $organisation);
				return ['provider' => null, 'model' => null, 'requiredResidency' => null, 'requiresRedaction' => false, 'source' => 'instance'];
			}
		);

		$this->assertSame(
			[
				[
					'id' => 'f-1',
					'slug' => 'samenvatten',
					'name' => 'Samenvatten',
					'provider' => 'ollama',
					'model' => 'llama3',
					'source' => 'feature',
					'requiredResidency' => 'on-premise',
					'residency' => 'on-premise',
					'location' => 'Rack 4',
				],
				[
					'id' => 'f-2',
					'slug' => 'vertalen',
					'name' => 'Vertalen',
					'provider' => null,
					'model' => null,
					'source' => 'instance',
					'requiredResidency' => null,
					'residency' => 'undeclared',
					'location' => '',
				],
			],
			$this->service()->overview($resolver)
		);
	}//end testOverviewListsEachFeatureWithItsBindingAndResidency()

	public function testOverviewOfAnUndeclaredProviderSaysUndeclared(): void {
		$this->features->method('listFeatures')->willReturn([$this->feature('f-3', [], 'gemeente')]);
		$resolver = $this->createMock(FeatureProviderResolver::class);
		$resolver->method('bindingFor')->with('', 'gemeente')->willReturn(
			['provider' => 'openai', 'model' => 'gpt-4o', 'requiredResidency' => null, 'requiresRedaction' => false, 'source' => 'organisation']
		);

		$rows = $this->service()->overview($resolver);

		$this->assertSame('', $rows[0]['name']);
		$this->assertSame('undeclared', $rows[0]['residency']);
		$this->assertSame('', $rows[0]['location']);
	}//end testOverviewOfAnUndeclaredProviderSaysUndeclared()

	public function testOverviewOfNoFeaturesIsEmpty(): void {
		$this->features->method('listFeatures')->willReturn([]);

		$this->assertSame([], $this->service()->overview($this->createMock(FeatureProviderResolver::class)));
	}//end testOverviewOfNoFeaturesIsEmpty()

	public function testBindingAnUnknownFeatureWritesNothing(): void {
		$this->features->method('getFeature')->with('missing')->willReturn(null);
		$this->objects->expects($this->never())->method('saveObject');

		$this->assertNull($this->service()->bind(id: 'missing', provider: 'ollama', model: 'llama3'));
	}//end testBindingAnUnknownFeatureWritesNothing()

	public function testAnUnsupportedResidencyIsRefused(): void {
		$this->features->method('getFeature')->willReturn($this->feature('f-1', [], 'gemeente'));
		$this->objects->expects($this->never())->method('saveObject');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Unsupported residency 'mars' — must be one of: on-premise, eu, outside-eu");

		$this->service()->bind(id: 'f-1', provider: 'ollama', model: 'llama3', requiredResidency: ' mars ');
	}//end testAnUnsupportedResidencyIsRefused()

	public function testARefusalWithoutAnOrganisationNamesTheInstanceWidePolicy(): void {
		$this->features->method('getFeature')->willReturn($this->feature('f-1', [], null));
		$this->policy->method('isAllowed')->with('', 'openai', 'gpt-4o')->willReturn(false);
		$this->policy->method('effectivePolicyFor')->with('')->willReturn([]);
		$this->objects->expects($this->never())->method('saveObject');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(
			"Refused by the model-policy check: the effective model policy for '(instance-wide)' does not permit provider 'openai' model 'gpt-4o'."
		);

		$this->service()->bind(id: 'f-1', provider: ' openai ', model: ' gpt-4o ');
	}//end testARefusalWithoutAnOrganisationNamesTheInstanceWidePolicy()

	public function testAPermittedBindingStoresTheTrimmedPairAndResidencyOnTheFeature(): void {
		$this->features->method('getFeature')->willReturn($this->feature('f-1', ['slug' => 'samenvatten'], 'gemeente'));
		$this->policy->method('isAllowed')->willReturn(true);
		$saved = new ObjectEntity();
		$this->objects->expects($this->once())
			->method('saveObject')
			->with(
				['slug' => 'samenvatten', 'id' => 'f-1', 'provider' => 'ollama', 'model' => 'llama3', 'requiredResidency' => 'eu'],
				[],
				'hermiq',
				'agentaifeature',
				'f-1'
			)
			->willReturn($saved);

		$this->assertSame($saved, $this->service()->bind(id: 'f-1', provider: 'ollama ', model: ' llama3', requiredResidency: 'eu'));
	}//end testAPermittedBindingStoresTheTrimmedPairAndResidencyOnTheFeature()
}//end class
