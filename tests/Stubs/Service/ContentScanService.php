<?php

/**
 * Test stub for OpenRegister ContentScanService.
 *
 * Stands in for OCA\OpenRegister\Service\ContentScanService when OpenRegister is not
 * installed (standalone CI: php:8.3-cli + OCP stubs). Mirrors the constant surface and the
 * scan() signature Hermiq's SkillMarketplaceService consumes; unit tests mock this class to
 * drive verdicts. The real heuristic scanner ships with OpenRegister at runtime.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Minimal ContentScanService stub for standalone unit runs.
 */
class ContentScanService
{

    /**
     * Verdict: no known-bad pattern matched.
     *
     * @var string
     */
    public const SEVERITY_CLEAN = 'clean';

    /**
     * Verdict: warrants a human review before trust.
     *
     * @var string
     */
    public const SEVERITY_SUSPICIOUS = 'suspicious';

    /**
     * Verdict: must not be auto-trusted.
     *
     * @var string
     */
    public const SEVERITY_DANGEROUS = 'dangerous';

    /**
     * Scan text for dangerous patterns.
     *
     * @param string               $content  The primary text.
     * @param array<string, mixed> $metadata Optional structured metadata folded in.
     *
     * @return array{safe: bool, severity: string, findings: array<int, array<string, string>>, scannedBytes: int, truncated: bool}
     */
    public function scan(string $content, array $metadata=[]): array
    {
        return [
            'safe'         => true,
            'severity'     => self::SEVERITY_CLEAN,
            'findings'     => [],
            'scannedBytes' => strlen($content),
            'truncated'    => false,
        ];
    }//end scan()
}//end class
