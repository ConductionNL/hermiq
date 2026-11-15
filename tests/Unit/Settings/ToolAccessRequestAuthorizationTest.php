<?php

/**
 * Tests for the ToolAccessRequest schema's authorization block.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Settings
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
 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The ToolAccessRequest schema's write rules.
 *
 * @coversNothing
 *
 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
 */
class ToolAccessRequestAuthorizationTest extends TestCase {

	/**
	 * Only the request's owner (admitted before any rule) and admins may update a
	 * request through the object API; hermiq decides requests on the system path.
	 * The block used to read `["authenticated"]`, which let any signed-in user mark
	 * someone else's request granted.
	 *
	 * @return void
	 */
	public function testUpdateIsTheOwnerOrAnAdmin(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'), true);
		$authorization = ($register['components']['schemas']['ToolAccessRequest']['authorization'] ?? null);

		$this->assertIsArray(actual: $authorization);
		$this->assertSame(expected: ['admin'], actual: ($authorization['update'] ?? null));
		$this->assertSame(expected: ['admin'], actual: ($authorization['delete'] ?? null));

	}//end testUpdateIsTheOwnerOrAnAdmin()
}//end class
