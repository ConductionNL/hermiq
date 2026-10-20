<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Hermiq\Tests\Unit\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\OriginAppAliases;
use PHPUnit\Framework\TestCase;

/**
 * Covers the ids one app has carried.
 *
 * @spec openspec/changes/ai-oversight-subject-access/specs/ai-oversight/spec.md
 */
class OriginAppAliasesTest extends TestCase {


    /**
     * The current id finds its former id, and the former id finds the current one.
     *
     * @return void
     */
    public function testBothIdsOfARenamedAppFindEachOther(): void {
        $aliases = new OriginAppAliases();

        $this->assertSame(['dossiq', 'procest'], $aliases->idsOf(appId: 'dossiq'));
        $this->assertSame(['procest', 'dossiq'], $aliases->idsOf(appId: 'procest'));

    }//end testBothIdsOfARenamedAppFindEachOther()


    /**
     * An app that was never renamed is only itself.
     *
     * @return void
     */
    public function testUnknownAppIsOnlyItself(): void {
        $this->assertSame(['pipelinq'], (new OriginAppAliases())->idsOf(appId: 'pipelinq'));

    }//end testUnknownAppIsOnlyItself()


}//end class
