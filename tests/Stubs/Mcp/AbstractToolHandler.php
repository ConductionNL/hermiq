<?php

/**
 * Test stub for OCA\OpenRegister\Mcp\AbstractToolHandler.
 *
 * Mirrors the base class signature from openregister (openbuild PR #173).
 * Used only in environments where the openregister runtime is not installed
 * (e.g. bare CI containers). It is replaced by the real class as soon as
 * the openregister app is installed alongside this app.
 *
 * This file is NOT scanned by PHPCS.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Stubs\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp;

use OCP\IGroupManager;
use OCP\IUserSession;

if (class_exists(AbstractToolHandler::class) === false) {
    /**
     * Stub abstract base class for tool handlers.
     *
     * Provides standardised requireWriteRole() and requireAdminUser() helpers
     * used across Conduction app MCP tool providers.
     */
    abstract class AbstractToolHandler
    {

        /**
         * The user session.
         *
         * @var IUserSession
         */
        protected IUserSession $userSession;

        /**
         * The group manager.
         *
         * @var IGroupManager
         */
        protected IGroupManager $groupManager;

        /**
         * Assert that there is an authenticated user with write-role access.
         *
         * @return array<string,mixed>|null Error envelope or null when authorised.
         */
        protected function requireWriteRole(): ?array
        {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return [
                    'error' => [
                        'code'    => 'not_authenticated',
                        'message' => 'You must be signed in to perform this action.',
                    ],
                ];
            }

            return null;

        }//end requireWriteRole()

        /**
         * Assert that there is an authenticated admin user.
         *
         * @return array<string,mixed>|null Error envelope or null when authorised.
         */
        protected function requireAdminUser(): ?array
        {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return [
                    'error' => [
                        'code'    => 'not_authenticated',
                        'message' => 'You must be signed in to perform this action.',
                    ],
                ];
            }

            if ($this->groupManager->isAdmin($user->getUID()) === false) {
                return [
                    'error' => [
                        'code'    => 'forbidden',
                        'message' => 'Admin access required.',
                    ],
                ];
            }

            return null;

        }//end requireAdminUser()

    }//end class
}//end if
