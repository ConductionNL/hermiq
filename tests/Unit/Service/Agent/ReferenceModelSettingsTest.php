<?php

/**
 * Tests for ReferenceModelSettings: the save-time check of an agent's ensemble.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Agent
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
 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Agent;

use InvalidArgumentException;
use OCA\Hermiq\Service\Agent\ReferenceModelSettings;
use OCA\Hermiq\Service\EffectiveModelPolicy;
use OCA\OpenRegister\Db\OrganisationMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * An ensemble names two or three reference models the policy allows.
 */
#[CoversClass(ReferenceModelSettings::class)]
final class ReferenceModelSettingsTest extends TestCase {

	/**
	 * The policy: anthropic and ollama are allowed in org-1, nothing else.
	 *
	 * @var EffectiveModelPolicy&MockObject
	 */
	private EffectiveModelPolicy $policies;

	/**
	 * The active-organisation lookup.
	 *
	 * @var OrganisationMapper&MockObject
	 */
	private OrganisationMapper $organisations;

	/**
	 * The subject.
	 *
	 * @var ReferenceModelSettings
	 */
	private ReferenceModelSettings $settings;

	/**
	 * Build the subject over a policy allowing anthropic and ollama in org-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->policies = $this->createMock(originalClassName: EffectiveModelPolicy::class);
		$this->policies->method('isAllowed')->willReturnCallback(
			static fn (string $organisation, string $provider, string $model): bool => $organisation === 'org-1'
				&& in_array($provider, ['anthropic', 'ollama'], true)
		);
		$this->organisations = $this->createMock(originalClassName: OrganisationMapper::class);
		$this->settings = new ReferenceModelSettings(policies: $this->policies, organisations: $this->organisations);
	}//end setUp()

	/**
	 * Two allowed reference models are kept in order, trimmed.
	 *
	 * @return void
	 */
	public function testTwoAllowedReferenceModelsAreKeptInOrder(): void {
		$ensemble = $this->settings->normalise(
			raw: [
				'enabled' => true,
				'referenceModels' => [
					['provider' => 'anthropic', 'model' => ' claude-opus-4-8 ', 'extra' => 'dropped'],
					['provider' => 'ollama', 'model' => 'qwen2.5'],
				],
			],
			organisation: 'org-1'
		);

		self::assertSame(
			expected: [
				'enabled' => true,
				'referenceModels' => [
					['provider' => 'anthropic', 'model' => 'claude-opus-4-8'],
					['provider' => 'ollama', 'model' => 'qwen2.5'],
				],
			],
			actual: $ensemble
		);
	}//end testTwoAllowedReferenceModelsAreKeptInOrder()

	/**
	 * A reference model outside the policy is refused with the policy text.
	 *
	 * @return void
	 */
	public function testAModelOutsideThePolicyIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(ReferenceModelSettings::NOT_ALLOWED);

		$this->settings->normalise(
			raw: [
				'enabled' => true,
				'referenceModels' => [
					['provider' => 'ollama', 'model' => 'qwen2.5'],
					['provider' => 'openai', 'model' => 'gpt-4o'],
				],
			],
			organisation: 'org-1'
		);
	}//end testAModelOutsideThePolicyIsRefused()

	/**
	 * The policy of the organisation given is the one asked.
	 *
	 * @return void
	 */
	public function testThePolicyOfTheGivenOrganisationDecides(): void {
		$this->expectExceptionMessage(ReferenceModelSettings::NOT_ALLOWED);

		$this->settings->normalise(
			raw: ['enabled' => false, 'referenceModels' => [['provider' => 'ollama', 'model' => 'qwen2.5']]],
			organisation: 'org-2'
		);
	}//end testThePolicyOfTheGivenOrganisationDecides()

	/**
	 * Malformed or wrongly sized ensembles are refused.
	 *
	 * @param mixed  $raw     The ensemble payload.
	 * @param string $message The refusal.
	 *
	 * @return void
	 */
	#[DataProvider('refusedShapes')]
	public function testMalformedOrWronglySizedEnsemblesAreRefused(mixed $raw, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		$this->settings->normalise(raw: $raw, organisation: 'org-1');
	}//end testMalformedOrWronglySizedEnsemblesAreRefused()

	/**
	 * Ensembles that cannot be saved, with their refusal.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function refusedShapes(): array {
		$one = ['provider' => 'ollama', 'model' => 'qwen2.5'];
		return [
			'not an object' => ['on', 'ensemble must be an object with enabled and referenceModels'],
			'references not a list' => [['enabled' => true, 'referenceModels' => ['a' => $one]], 'referenceModels must be a list of {provider, model} entries'],
			'entry not an object' => [['enabled' => true, 'referenceModels' => [$one, 'ollama']], 'Each reference model must be an object with provider and model'],
			'four references' => [['enabled' => true, 'referenceModels' => [$one, $one, $one, $one]], 'An ensemble names at most 3 reference models.'],
			'enabled with one' => [['enabled' => true, 'referenceModels' => [$one]], 'An ensemble names at least 2 reference models.'],
			'no model' => [['enabled' => false, 'referenceModels' => [['provider' => 'ollama', 'model' => '']]], ReferenceModelSettings::NOT_ALLOWED],
			'provider not text' => [['enabled' => false, 'referenceModels' => [['provider' => ['ollama'], 'model' => 'qwen2.5']]], ReferenceModelSettings::NOT_ALLOWED],
		];
	}//end refusedShapes()

	/**
	 * Null clears the setting; a switched-off ensemble keeps its picks.
	 *
	 * @return void
	 */
	public function testNullClearsAndASwitchedOffEnsembleKeepsItsPicks(): void {
		self::assertSame(
			expected: ['enabled' => false, 'referenceModels' => []],
			actual: $this->settings->normalise(raw: null, organisation: 'org-1')
		);
		self::assertSame(
			expected: ['enabled' => false, 'referenceModels' => [['provider' => 'ollama', 'model' => 'qwen2.5']]],
			actual: $this->settings->normalise(
				raw: ['enabled' => 'yes', 'referenceModels' => [['provider' => 'ollama', 'model' => 'qwen2.5']]],
				organisation: 'org-1'
			)
		);
	}//end testNullClearsAndASwitchedOffEnsembleKeepsItsPicks()

	/**
	 * A new agent's organisation is the person's active one; nobody signed in has none.
	 *
	 * @return void
	 */
	public function testTheActiveOrganisationOfThePersonApplies(): void {
		$this->organisations->expects($this->once())
			->method('getActiveOrganisationWithFallback')
			->with('alice')
			->willReturn('org-1');

		self::assertSame(expected: 'org-1', actual: $this->settings->organisationOf(uid: 'alice'));
		self::assertSame(expected: '', actual: $this->settings->organisationOf(uid: ''));
	}//end testTheActiveOrganisationOfThePersonApplies()
}//end class
