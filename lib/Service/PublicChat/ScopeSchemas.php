<?php

/**
 * Hermiq ScopeSchemas.
 *
 * The schemas of a whole-register pair in a public chat channel's source
 * scope. OpenRegister's search finds nothing in a register without a schema,
 * so a scope that declares a whole register names its schemas.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\PublicChat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\PublicChat;

use OCA\OpenRegister\Db\RegisterMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists the schemas of a register named in a public scope.
 */
class ScopeSchemas {

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper  $registers OpenRegister's registers, read as the system.
	 * @param LoggerInterface $logger    Records a register that could not be read.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterMapper $registers,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The schemas of a whole-register scope pair, or [] for a pair that names its schema.
	 *
	 * Read as the system: the register's schema list is configuration the
	 * channel's administrator declared, not content. Which OBJECTS come back is
	 * still decided by the anonymous read. [] when the register cannot be read.
	 *
	 * @param array{register: string, schema: string} $pair The scope pair.
	 *
	 * @return list<int|string>
	 *
	 * @spec openspec/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 */
	public function schemasOf(array $pair): array {
		if ($pair['schema'] !== '') {
			return [];
		}

		try {
			$schemas = $this->registers->find(id: $pair['register'], _rbac: false, _multitenancy: false)->getSchemas();
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ScopeSchemas] The schemas of a register in a public scope could not be read',
				context: ['file' => __FILE__, 'line' => __LINE__, 'register' => $pair['register'], 'error' => $e->getMessage()]
			);
			return [];
		}

		return array_values(array_filter((array)$schemas, static fn (mixed $id): bool => is_int($id) === true || is_string($id) === true));
	}//end schemasOf()
}//end class
