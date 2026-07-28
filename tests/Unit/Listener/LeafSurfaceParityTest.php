<?php

/**
 * Cross-layer parity test for the `hermiq-agent` leaf's declared render surfaces
 * (hydra-console-agent-leaves).
 *
 * The two halves of a leaf registration live in different languages and different
 * build outputs, so nothing in either compiler can notice when they disagree. They
 * DID disagree: the PHP `LeafDescriptor` declared `['detail-page','single-entity']`
 * while `src/integration-leaf.js` declared no `surfaces` key at all — while shipping
 * a dashboard-sized `widget`. A half that declares by omission has nothing for a
 * parity check to compare, which is precisely how the drift survived.
 *
 * This reads the JS source and compares it to the PHP constant. Reading source text
 * is unusual for a unit test and is the point: the assertion has to hold over the
 * two DECLARATIONS, and there is no runtime in this process where both exist.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#scenario-both-registration-halves-declare-the-same-explicit-surface-set
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Listener\RegisterAgentLeafListener;
use PHPUnit\Framework\TestCase;

/**
 * Tests that both registration halves declare the same explicit surface set.
 *
 * @spec openspec/changes/hydra-console-agent-leaves/tasks.md#task-2-align-the-leaf-surface-vocabulary-across-both-halves
 */
class LeafSurfaceParityTest extends TestCase
{

    /**
     * OpenRegister's authoritative surface vocabulary
     * (`LeafDescriptor::VALID_SURFACES`). Mirrored rather than imported because
     * `OCA\OpenRegister\*` is absent from this repository's analysis environment;
     * a drift in OR's own list is caught live, not here.
     *
     * @var array<int, string>
     */
    private const VALID_SURFACES = ['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity'];

    /**
     * The surfaces the JS half declares, read from its source.
     *
     * @return array<int, string>
     */
    private function jsSurfaces(): array
    {
        $source = file_get_contents(__DIR__.'/../../../src/integration-leaf.js');
        $this->assertIsString($source, 'src/integration-leaf.js must be readable.');

        // The registration object must reference the declared list, not omit it.
        $this->assertMatchesRegularExpression(
            '/\n\tsurfaces:\s*SURFACES,/',
            $source,
            'The JS half must pass an EXPLICIT surfaces list to registerIntegration().'
        );

        $matched = preg_match('/const SURFACES = \[([^\]]*)\]/', $source, $matches);
        $this->assertSame(1, $matched, 'src/integration-leaf.js must declare a SURFACES list.');

        $surfaces = [];
        foreach (explode(',', $matches[1]) as $entry) {
            $entry = trim($entry, " \t\n'\"");
            if ($entry !== '') {
                $surfaces[] = $entry;
            }
        }

        return $surfaces;

    }//end jsSurfaces()

    /**
     * Both halves name the SAME set, in the same order.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#scenario-both-registration-halves-declare-the-same-explicit-surface-set
     */
    public function testBothHalvesDeclareTheSameSurfaceSet(): void
    {
        $this->assertSame(RegisterAgentLeafListener::SURFACES, $this->jsSurfaces());

    }//end testBothHalvesDeclareTheSameSurfaceSet()

    /**
     * Every declared surface is a member of OpenRegister's vocabulary — a typo
     * would otherwise register a surface nothing ever renders.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#scenario-both-registration-halves-declare-the-same-explicit-surface-set
     */
    public function testEverySurfaceIsInTheOpenRegisterVocabulary(): void
    {
        foreach (RegisterAgentLeafListener::SURFACES as $surface) {
            $this->assertContains($surface, self::VALID_SURFACES);
        }

    }//end testEverySurfaceIsInTheOpenRegisterVocabulary()

    /**
     * The dashboard surfaces are included, because the leaf ships a run-history
     * widget with a default grid size and consuming apps place that widget on
     * dashboards. This is the assertion that would have failed before the fix.
     *
     * The widget is asserted by the component it roots rather than by a literal
     * `widget:` key: under renderMode `mount` (openregister#2127, ADR-066) the leaf
     * registers a `mount`/`unmount` pair instead of `tab`/`widget` SFCs, and
     * `componentForSurface()` roots `CnAgentRunsWidget` on the dashboard surfaces.
     * The thing that matters — a dashboard-sized run widget the declared surfaces
     * can host — holds in either registration form.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#scenario-the-agent-widget-is-placeable-on-a-consuming-apps-dashboard
     */
    public function testTheDashboardSurfacesAreDeclaredBecauseTheLeafShipsAWidget(): void
    {
        $source = file_get_contents(__DIR__.'/../../../src/integration-leaf.js');

        $this->assertStringContainsString('CnAgentRunsWidget', (string) $source);
        $this->assertStringContainsString('defaultSize:', (string) $source);

        $this->assertContains('user-dashboard', RegisterAgentLeafListener::SURFACES);
        $this->assertContains('app-dashboard', RegisterAgentLeafListener::SURFACES);

    }//end testTheDashboardSurfacesAreDeclaredBecauseTheLeafShipsAWidget()
}//end class
