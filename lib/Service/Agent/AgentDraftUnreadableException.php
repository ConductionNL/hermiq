<?php

/**
 * Hermiq AgentDraftUnreadableException.
 *
 * The agent draft from chat is not a JSON object with a name, so no form opens
 * (agents-plain-language-builder). Answered as HTTP 422.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Agent
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
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Agent;

use RuntimeException;

/**
 * An agent draft that could not be read (HTTP 422).
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
 */
class AgentDraftUnreadableException extends RuntimeException {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(message: 'This draft could not be read', code: 422);
	}//end __construct()
}//end class
