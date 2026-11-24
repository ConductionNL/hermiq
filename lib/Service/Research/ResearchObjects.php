<?php

/**
 * Hermiq ResearchObjects.
 *
 * Reads the hermiq objects a research run needs, the agent and the
 * conversation, under the current person's access: an object that person
 * cannot read is not there.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Research
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Research;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Reads a research run's agent and conversation as the current person.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */
class ResearchObjects {

	/**
	 * The agent schema.
	 */
	public const AGENT = 'agent';

	/**
	 * The conversation schema.
	 */
	public const CONVERSATION = 'agentsession';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister's object read, with access checks on.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The object, or null when it is missing or the person may not read it.
	 *
	 * @param string $uuid   The object's uuid.
	 * @param string $schema The hermiq schema slug.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	public function readable(string $uuid, string $schema): ?ObjectEntity {
		$found = null;
		if ($uuid !== '') {
			try {
				$found = $this->objectService->find(id: $uuid, options: new ObjectFindOptions(register: 'hermiq', schema: $schema));
			} catch (Throwable $e) {
				$found = null;
			}
		}

		if ($found instanceof ObjectEntity) {
			return $found;
		}

		return null;
	}//end readable()

	/**
	 * The agent owner's prompt, '' when the agent has none or cannot be read.
	 *
	 * @param string $agentId The agent.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	public function agentPrompt(string $agentId): string {
		$agent = $this->readable(uuid: $agentId, schema: self::AGENT);

		return trim((string)($agent?->getObject()['prompt'] ?? ''));
	}//end agentPrompt()
}//end class
