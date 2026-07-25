<?php

/**
 * Test stub for OpenRegister IntegrationProvider.
 *
 * Minimal interface stub so RegisterLeafProvidersEvent's `registerLeaf()` type
 * hint resolves in standalone CI. Hermiq's agent leaf is render-only and passes
 * `null` for the provider, so no method surface is exercised. The real interface
 * ships with OpenRegister (lib/Service/Integration/IntegrationProvider.php).
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

/**
 * Minimal IntegrationProvider interface stub for standalone unit runs.
 */
interface IntegrationProvider
{
    /**
     * Stable id used to address this integration.
     *
     * @return string
     */
    public function getId(): string;
}//end interface
