<?php

/**
 * Tests for DataUseViolationException.
 *
 * Pins the exact message (with the instance-wide label for an empty
 * organisation), the 422 code, the carried fields and the step name.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\AiFeature;

use OCA\Hermiq\Service\AiFeature\DataUseViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The data-use refusal.
 */
#[CoversClass(DataUseViolationException::class)]
final class DataUseViolationExceptionTest extends TestCase {

	/**
	 * A named organisation appears in the message as given.
	 *
	 * @return void
	 */
	public function testNamedOrganisation(): void {
		$e = new DataUseViolationException(organisation: 'Gemeente Voorbeeld', provider: 'openai', dataUse: 'undeclared');

		$this->assertSame(
			"Refused by the data-use check: organisation 'Gemeente Voorbeeld' requires providers that never train on its data, and provider 'openai' has declared 'undeclared'.",
			$e->getMessage()
		);
		$this->assertSame(422, $e->getCode());
		$this->assertSame('Gemeente Voorbeeld', $e->organisation);
		$this->assertSame('openai', $e->provider);
		$this->assertSame('undeclared', $e->dataUse);
		$this->assertSame('data-use', $e->step());
		$this->assertSame('data_use_refused', DataUseViolationException::ERROR_CODE);
	}//end testNamedOrganisation()

	/**
	 * An empty organisation is the instance-wide policy, labelled as such, while the
	 * carried field stays empty.
	 *
	 * @return void
	 */
	public function testEmptyOrganisationIsLabelledInstanceWide(): void {
		$e = new DataUseViolationException(organisation: '', provider: 'mistral', dataUse: 'may-train');

		$this->assertSame(
			"Refused by the data-use check: organisation '(instance-wide)' requires providers that never train on its data, and provider 'mistral' has declared 'may-train'.",
			$e->getMessage()
		);
		$this->assertSame('', $e->organisation);
		$this->assertSame(422, $e->getCode());
	}//end testEmptyOrganisationIsLabelledInstanceWide()
}//end class
