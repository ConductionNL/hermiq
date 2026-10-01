<?php

/**
 * Hermiq AppRegisterScope.
 *
 * The registers of an app, for an agent tied to it (agents-bound-to-their-app):
 * the OpenRegister registers whose `application` names the app, which OpenRegister
 * sets to the app id that imported the register. Read with the caller's RBAC and
 * organisation, so a reused slug in another organisation never widens the scope.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
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
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-management-ui/spec.md#requirement-an-apps-agent-answers-from-the-apps-data-first-req-appag-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists an app's registers.
 *
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-management-ui/spec.md#requirement-an-apps-agent-answers-from-the-apps-data-first-req-appag-003
 */
class AppRegisterScope
{

    /**
     * Constructor.
     *
     * @param RegisterMapper  $registerMapper OpenRegister's registers.
     * @param LoggerInterface $logger         Logs a failed lookup.
     *
     * @spec openspec/changes/agents-bound-to-their-app/specs/agent-management-ui/spec.md#requirement-an-apps-agent-answers-from-the-apps-data-first-req-appag-003
     */
    public function __construct(
        private readonly RegisterMapper $registerMapper,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * The app's registers as id and display name, or none.
     *
     * @param string $app The app id or slug.
     *
     * @return array<int, array{id: int, name: string}>
     *
     * @spec openspec/changes/agents-bound-to-their-app/specs/agent-management-ui/spec.md#requirement-an-apps-agent-answers-from-the-apps-data-first-req-appag-003
     */
    public function registersFor(string $app): array
    {
        $app = trim($app);
        if ($app === '') {
            return [];
        }

        try {
            $registers = $this->registerMapper->findAll(filters: ['application' => $app]);
        } catch (Throwable $e) {
            $this->logger->warning('[AppRegisterScope] Registers of '.$app.' could not be read: '.$e->getMessage());
            return [];
        }

        $scope = [];
        foreach ($registers as $register) {
            if (($register instanceof Register) === false || $register->getId() === null) {
                continue;
            }

            $scope[] = [
                'id'   => (int) $register->getId(),
                'name' => (string) ($register->getTitle() ?? $register->getSlug() ?? ''),
            ];
        }

        return $scope;

    }//end registersFor()

    /**
     * The app a register belongs to, as OpenRegister records it on import, or ''.
     *
     * @param string $register The register id or slug.
     *
     * @return string The app id, lower case, or '' when unknown.
     *
     * @spec openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
     */
    public function appOf(string $register): string
    {
        if (trim($register) === '') {
            return '';
        }

        try {
            $found = $this->registerMapper->find($register);
        } catch (Throwable $e) {
            $this->logger->warning('[AppRegisterScope] Register '.$register.' could not be read: '.$e->getMessage());
            return '';
        }

        return strtolower(trim((string) ($found->getApplication() ?? '')));

    }//end appOf()
}//end class
