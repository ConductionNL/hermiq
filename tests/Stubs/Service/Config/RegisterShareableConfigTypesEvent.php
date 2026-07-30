<?php
/**
 * Minimal OpenRegister RegisterShareableConfigTypesEvent stub for standalone unit
 * runs and static analysis.
 *
 * Signatures mirrored from openregister
 * lib/Service/Config/RegisterShareableConfigTypesEvent.php. The real event
 * delegates `registerType()` to a ShareableConfigTypeRegistry; the stub collects
 * types locally so ShareableConfigTypeListener is exercisable without
 * OpenRegister installed. Registered at TEST TIME only by tests/bootstrap.php and
 * scanned (never executed) by phpstan/psalm.
 *
 * Deliberately does NOT stub ShareableConfigTypeRegistry: the constructor
 * dependency is what forces a test to drag in OpenRegister internals, and holding
 * the types locally keeps the stub to the contract Hermiq actually consumes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

use OCP\EventDispatcher\Event;

/**
 * Minimal RegisterShareableConfigTypesEvent stub.
 */
class RegisterShareableConfigTypesEvent extends Event
{

    /**
     * Contributed configuration types.
     *
     * @var array<int, IShareableConfigType>
     */
    private array $types = [];

    /**
     * Contribute a shareable configuration type.
     *
     * @param IShareableConfigType $type The type.
     *
     * @return void
     */
    public function registerType(IShareableConfigType $type): void
    {
        $this->types[] = $type;

    }//end registerType()

    /**
     * Every contributed configuration type.
     *
     * @return array<int, IShareableConfigType> The types.
     */
    public function getTypes(): array
    {
        return $this->types;

    }//end getTypes()
}//end class
