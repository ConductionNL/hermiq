<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Hermiq\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * The app ids one app has carried, so records stored under an old id stay findable.
 *
 * A record keeps the origin app id it was written with; stored data is never
 * rewritten when an app is renamed. A lookup by either id therefore searches
 * every id of the same app.
 *
 * @spec openspec/changes/ai-oversight-subject-access/specs/ai-oversight/spec.md
 */
class OriginAppAliases {

    /**
     * Current app id => the ids it carried before.
     *
     * @var array<string, string[]>
     */
    private const FORMER_IDS = [
        'dossiq'    => ['procest'],
        'integriq'  => ['openconnector'],
        'filinq'    => ['docudesk'],
        'thematiq'  => ['nldesign'],
        'stackiq'   => ['softwarecatalog'],
        'larpinq'   => ['larpingapp'],
        'learniq'   => ['scholiq'],
        'decidiq'   => ['decidesk'],
        'buildiq'   => ['openbuild'],
        'keepiq'    => ['doriath'],
        'humaniq'   => ['hrmq'],
        'versioniq' => ['app-versions'],
        'planninq'  => ['planix'],
        'launchpad' => ['mydash'],
    ];


    /**
     * Every id of the app the given id belongs to, the given id first.
     *
     * @param string $appId A current or former app id.
     *
     * @return string[] The ids, without duplicates.
     *
     * @spec openspec/changes/ai-oversight-subject-access/specs/ai-oversight/spec.md
     */
    public function idsOf(string $appId): array {
        foreach (self::FORMER_IDS as $current => $former) {
            $family = array_merge([$current], $former);
            if (in_array($appId, $family, true) === true) {
                return array_values(array_unique(array_merge([$appId], $family)));
            }
        }

        return [$appId];

    }//end idsOf()


}//end class
