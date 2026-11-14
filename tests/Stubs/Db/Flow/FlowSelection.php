<?php

/**
 * Test stub for OCA\OpenRegister\Db\Flow\FlowSelection.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Stubs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\Flow;

/**
 * Which flows a listing covers; same constructor as OpenRegister's.
 */
final class FlowSelection {
	/**
	 * Constructor.
	 *
	 * @param string|null $app             Restrict to one owning app id.
	 * @param string|null $applicationSlug Restrict to one application slug.
	 * @param string|null $organisation    Restrict to one organisation uuid.
	 * @param bool|null   $enabled         Restrict to enabled or disabled flows.
	 */
	public function __construct(
		public readonly ?string $app = null,
		public readonly ?string $applicationSlug = null,
		public readonly ?string $organisation = null,
		public readonly ?bool $enabled = null,
	) {
	}//end __construct()
}//end class
