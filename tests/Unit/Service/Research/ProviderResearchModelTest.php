<?php

/**
 * Unit tests for ProviderResearchModel (tools-deep-research-report).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Research
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Research;

use OCA\Hermiq\Kernel\Contracts\ProviderFactoryInterface;
use OCA\Hermiq\Service\Research\ProviderResearchModel;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\Research\ProviderResearchModel
 */
final class ProviderResearchModelTest extends TestCase {

	public function testAGenerationIsCheckedAgainstTheOrganisationsModelPolicy(): void {
		$providers = $this->createMock(ProviderFactoryInterface::class);
		$providers->expects(self::once())->method('generateText')
			->with('the prompt', 'anne', true, 'org-1')
			->willReturn('the answer');

		self::assertSame('the answer', (new ProviderResearchModel($providers))->generate('the prompt', 'anne', 'org-1'));
	}
}
