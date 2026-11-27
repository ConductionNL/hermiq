<?php

/**
 * Contract of AgentObjectMatcher
 *
 * The public surface of AgentObjectMatcher that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Contract of AgentObjectMatcher.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AgentObjectMatcherInterface {

	/**
	 * Whether an object is one of Hermiq's agents.
	 *
	 * 🔴 `ObjectEntity::getSchema()` returns the schema's NUMERIC ID, not its
	 * slug: an agent arrives as `'4365'`, never `'agent'`. Comparing it to the
	 * slug can only ever be false. The id is instance-specific, so it cannot be
	 * hard-coded either.
	 *
	 * Resolved per call rather than cached: schemas can be re-imported mid-life,
	 * and the mappers cache.
	 *
	 * @param ObjectEntity $object The object an event carries.
	 *
	 * @return bool True when this is a Hermiq agent object.
	 *
	 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
	 */
	public function isAgent(ObjectEntity $object): bool;
}//end interface
