<?php
/**
 * Minimal OpenRegister ObjectDeletedEvent stub for standalone unit runs
 * and static analysis.
 *
 * Signatures mirrored from openregister lib/Event/ObjectDeletedEvent.php.
 * See ObjectCreatedEvent alongside this file for the stub discipline.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Minimal ObjectDeletedEvent stub.
 */
class ObjectDeletedEvent extends Event
{

    /**
     * Constructor.
     *
     * @param ObjectEntity $object The deleted object.
     */
    public function __construct(private readonly ObjectEntity $object)
    {
        parent::__construct();

    }//end __construct()

    /**
     * The deleted object.
     *
     * @return ObjectEntity The object.
     */
    public function getObject(): ObjectEntity
    {
        return $this->object;

    }//end getObject()
}//end class
