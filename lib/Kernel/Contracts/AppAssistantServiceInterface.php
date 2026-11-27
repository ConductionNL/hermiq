<?php

/**
 * Contract of AppAssistantService
 *
 * The public surface of AppAssistantService that other components call, so they depend
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
use RuntimeException;

/**
 * Contract of AppAssistantService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AppAssistantServiceInterface {

	/**
	 * Mark (or unmark) an agent as the assistant of the app it serves.
	 *
	 * @param string $agentId The agent.
	 * @param bool $assistant Whether it answers in its app.
	 * @param string $uid Who marks it.
	 *
	 * @return ObjectEntity The stored agent.
	 *
	 * @throws RuntimeException 404 unknown or unreadable, 403 not an admin of its organisation,
	 *                          422 no app, 409 the app already has an assistant.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag IS a boolean.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
	 */
	public function mark(string $agentId, bool $assistant, string $uid): ObjectEntity;
}//end interface
