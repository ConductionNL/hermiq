<?php

/**
 * Hermiq: whether an OpenRegister object is one of Hermiq's agents.
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
 * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Agent;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Throwable;

/**
 * Matches an object on Hermiq's register and its `agent` schema.
 *
 * @psalm-api
 *
 * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
 */
class AgentObjectMatcher {

	/**
	 * Schema slug for agents.
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * Register slug that owns Hermiq's agents.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper $schemaMapper Resolves the object's schema slug.
	 * @param RegisterMapper $registerMapper Resolves the object's register slug.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly RegisterMapper $registerMapper,
	) {
	}//end __construct()

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
	 * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
	 */
	public function isAgent(ObjectEntity $object): bool {
		$schema = (string)$object->getSchema();
		if ($schema === '') {
			return false;
		}

		// Slug, when a caller hands one over instead of an id.
		if ($schema === self::AGENT_SCHEMA) {
			return true;
		}

		try {
			if ((string)$this->schemaMapper->find((int)$schema)->getSlug() !== self::AGENT_SCHEMA) {
				return false;
			}

			// 🔴 The register check is NOT redundant. Schema slugs are not
			// unique across the instance: this fleet has TWO schemas slugged
			// `agent` (hermiq's and another app's). Matching on the slug alone
			// would treat another app's objects as Hermiq agents.
			return ((string)$this->registerMapper->find((int)$object->getRegister())->getSlug() === self::REGISTER_SLUG);
		} catch (Throwable $e) {
			return false;
		}

	}//end isAgent()
}//end class
