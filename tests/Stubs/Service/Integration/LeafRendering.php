<?php

/**
 * Test stub for OpenRegister LeafRendering.
 *
 * Stands in for OCA\OpenRegister\Service\Integration\LeafRendering when
 * OpenRegister is not installed (standalone CI). Mirrors the real value
 * object's constructor signature. The real class ships with OpenRegister (lib/Service/Integration/LeafRendering.php).
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

final class LeafRendering {
	/**
	 * @param array<int, string> $surfaces Surfaces.
	 */
	public function __construct(
		public readonly array $surfaces = [],
		public readonly ?string $referenceType = null,
		public readonly string $renderMode = 'component',
		public readonly ?string $loadStrategy = null,
	) {
	}
}
