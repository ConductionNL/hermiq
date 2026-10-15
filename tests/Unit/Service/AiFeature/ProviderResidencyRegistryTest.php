<?php

/**
 * Hermiq ProviderResidencyRegistry unit tests.
 *
 * Covers the administered-not-inferred rule, the free-text location an enum cannot
 * carry, and the two refusals on the write path
 * (a-provider-and-a-place-per-ai-feature).
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
 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-configured-provider-must-declare-where-it-runs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\AiFeature;

use InvalidArgumentException;
use OCA\Hermiq\Service\AiFeature\ProviderResidencyRegistry;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * ProviderResidencyRegistry unit tests.
 *
 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-configured-provider-must-declare-where-it-runs
 */
class ProviderResidencyRegistryTest extends TestCase {

	/**
	 * An IAppConfig double holding one string key in memory, so a declaration can be
	 * written and read back in the same test.
	 *
	 * @param string $initial The initial stored JSON.
	 *
	 * @return IAppConfig The double.
	 */
	private function appConfig(string $initial = ''): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$store = ['value' => $initial];

		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$store): string {
				if ($store['value'] === '') {
					return $default;
				}

				return $store['value'];
			}
		);

		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$store): bool {
				$store['value'] = $value;
				return true;
			}
		);

		return $config;
	}//end appConfig()

	/**
	 * A provider nobody has spoken for reads `undeclared`, never a guess. The
	 * registry is given no endpoint and no hostname at all, so there is no input a
	 * guess could even be made from.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#scenario-residency-is-a-statement-not-a-guess
	 */
	public function testAnUndeclaredProviderIsUndeclaredNotGuessed(): void {
		$registry = new ProviderResidencyRegistry($this->appConfig());

		$declaration = $registry->forProvider(provider: 'openai');

		$this->assertSame(ProviderResidencyRegistry::RESIDENCY_UNDECLARED, $declaration['residency']);
		$this->assertSame('', $declaration['location']);
	}//end testAnUndeclaredProviderIsUndeclaredNotGuessed()

	/**
	 * The free-text location an administrator typed comes back with the residency.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#scenario-the-detail-an-enum-cannot-carry-is-kept
	 */
	public function testTheLocationAnEnumCannotCarryIsKept(): void {
		$registry = new ProviderResidencyRegistry($this->appConfig());

		$registry->declareResidency(
			provider: 'openai',
			residency: ProviderResidencyRegistry::RESIDENCY_EU,
			location: 'Frankfurt, AWS eu-central-1'
		);

		$declaration = $registry->forProvider(provider: 'openai');

		$this->assertSame(ProviderResidencyRegistry::RESIDENCY_EU, $declaration['residency']);
		$this->assertSame('Frankfurt, AWS eu-central-1', $declaration['location']);
	}//end testTheLocationAnEnumCannotCarryIsKept()

	/**
	 * A residency outside the vocabulary is refused, naming what is allowed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-configured-provider-must-declare-where-it-runs
	 */
	public function testAnUnknownResidencyIsRefused(): void {
		$registry = new ProviderResidencyRegistry($this->appConfig());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/Unsupported residency/');

		$registry->declareResidency(provider: 'openai', residency: 'somewhere-nice');
	}//end testAnUnknownResidencyIsRefused()

	/**
	 * A provider that is not one of the supported chat drivers is refused, so the
	 * map cannot fill with labels nothing ever reads.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-configured-provider-must-declare-where-it-runs
	 */
	public function testAnUnknownProviderIsRefused(): void {
		$registry = new ProviderResidencyRegistry($this->appConfig());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/Unsupported provider/');

		$registry->declareResidency(provider: 'llama-in-a-shed', residency: ProviderResidencyRegistry::RESIDENCY_EU);
	}//end testAnUnknownProviderIsRefused()

	/**
	 * Every declaration is listed, each carrying its provider id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-configured-provider-must-declare-where-it-runs
	 */
	public function testEveryDeclarationIsListed(): void {
		$registry = new ProviderResidencyRegistry(
			$this->appConfig(
				(string)json_encode(
					[
						'ollama' => ['residency' => 'on-premise', 'location' => 'Serverruimte Stadskantoor'],
						'openai' => ['residency' => 'outside-eu', 'location' => 'us-east-1'],
					]
				)
			)
		);

		$all = $registry->all();

		$this->assertSame(['ollama', 'openai'], array_keys($all));
		$this->assertSame('on-premise', $all['ollama']['residency']);
		$this->assertSame('us-east-1', $all['openai']['location']);
	}//end testEveryDeclarationIsListed()

	/**
	 * A stored value outside the vocabulary (hand-edited config, an older key) is
	 * read as undeclared rather than trusted, so a broken write cannot quietly
	 * become a residency somebody relies on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#scenario-residency-is-a-statement-not-a-guess
	 */
	public function testAStoredValueOutsideTheVocabularyReadsUndeclared(): void {
		$registry = new ProviderResidencyRegistry(
			$this->appConfig((string)json_encode(['openai' => ['residency' => 'probably-europe']]))
		);

		$this->assertSame(
			ProviderResidencyRegistry::RESIDENCY_UNDECLARED,
			$registry->forProvider(provider: 'openai')['residency']
		);

	}//end testAStoredValueOutsideTheVocabularyReadsUndeclared()
}//end class
