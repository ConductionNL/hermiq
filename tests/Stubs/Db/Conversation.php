<?php

/**
 * Test stub for OpenRegister Conversation entity.
 *
 * Stands in for OCA\OpenRegister\Db\Conversation when OpenRegister is not
 * installed (standalone CI). Exposes only the setters ScheduleService uses to
 * open an agent-bound conversation, plus getId. The real entity ships with
 * OpenRegister at runtime.
 *
 * @category Test
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal Conversation stub for standalone unit runs.
 */
class Conversation {

	/**
	 * Conversation id.
	 *
	 * @var int|null
	 */
	private ?int $id = null;

	/**
	 * Get the conversation id.
	 *
	 * @return int|null
	 */
	public function getId(): ?int {
		return $this->id;
	}//end getId()

	/**
	 * Set the conversation id.
	 *
	 * @param int|null $id The id.
	 *
	 * @return void
	 */
	public function setId(?int $id): void {
		$this->id = $id;
	}//end setId()

	/**
	 * Set the user id.
	 *
	 * @param string|null $userId The user id.
	 *
	 * @return void
	 */
	public function setUserId(?string $userId): void {
	}//end setUserId()

	/**
	 * Set the owner.
	 *
	 * @param string|null $owner The owner.
	 *
	 * @return void
	 */
	public function setOwner(?string $owner): void {
	}//end setOwner()

	/**
	 * Set the bound agent id.
	 *
	 * @param int|null $agentId The agent id.
	 *
	 * @return void
	 */
	public function setAgentId(?int $agentId): void {
	}//end setAgentId()

	/**
	 * Set the conversation title.
	 *
	 * @param string|null $title The title.
	 *
	 * @return void
	 */
	public function setTitle(?string $title): void {
	}//end setTitle()
}//end class
