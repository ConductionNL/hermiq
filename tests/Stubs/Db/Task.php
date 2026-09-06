<?php

/**
 * Test stub for OpenRegister Task.
 *
 * Stands in for OCA\OpenRegister\Db\Task when OpenRegister is not installed
 * (standalone CI: php:8.3-cli + OCP stubs). Exposes the accessors hermiq's
 * approval-task convergence reads and writes: the bridge reads the created
 * mirror's uuid, and TaskTerminalListener reads state, outcome, metadata,
 * completedBy, assignee, comment and resultText off a terminal task. The
 * REAL entity is an NC Entity whose getters are `__call` magic declared via
 * `@method` docblocks (which is why the listener reads through
 * `is_callable`, never `method_exists`); this stub declares them concretely
 * with the same signatures. The real entity ships with OpenRegister at
 * runtime.
 *
 * @category Test
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal Task stub for standalone unit runs.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) A value stub is accessors by definition.
 */
class Task {

	/**
	 * The task uuid.
	 *
	 * @var string|null
	 */
	private ?string $uuid = null;

	/**
	 * The lifecycle state.
	 *
	 * @var string|null
	 */
	private ?string $state = null;

	/**
	 * The terminal outcome.
	 *
	 * @var string|null
	 */
	private ?string $outcome = null;

	/**
	 * The metadata payload.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $metadata = null;

	/**
	 * The completing identity.
	 *
	 * @var string|null
	 */
	private ?string $completedBy = null;

	/**
	 * The assigned identity.
	 *
	 * @var string|null
	 */
	private ?string $assignee = null;

	/**
	 * The completion comment.
	 *
	 * @var string|null
	 */
	private ?string $comment = null;

	/**
	 * The completion result text.
	 *
	 * @var string|null
	 */
	private ?string $resultText = null;

	/**
	 * Get the task uuid.
	 *
	 * @return string|null The uuid.
	 */
	public function getUuid(): ?string {
		return $this->uuid;
	}//end getUuid()

	/**
	 * Set the task uuid.
	 *
	 * @param string|null $uuid The uuid.
	 *
	 * @return void
	 */
	public function setUuid(?string $uuid): void {
		$this->uuid = $uuid;
	}//end setUuid()

	/**
	 * Get the lifecycle state.
	 *
	 * @return string|null The state.
	 */
	public function getState(): ?string {
		return $this->state;
	}//end getState()

	/**
	 * Set the lifecycle state.
	 *
	 * @param string|null $state The state.
	 *
	 * @return void
	 */
	public function setState(?string $state): void {
		$this->state = $state;
	}//end setState()

	/**
	 * Get the terminal outcome.
	 *
	 * @return string|null The outcome.
	 */
	public function getOutcome(): ?string {
		return $this->outcome;
	}//end getOutcome()

	/**
	 * Set the terminal outcome.
	 *
	 * @param string|null $outcome The outcome.
	 *
	 * @return void
	 */
	public function setOutcome(?string $outcome): void {
		$this->outcome = $outcome;
	}//end setOutcome()

	/**
	 * Get the metadata payload.
	 *
	 * @return array<string,mixed>|null The metadata.
	 */
	public function getMetadata(): ?array {
		return $this->metadata;
	}//end getMetadata()

	/**
	 * Set the metadata payload.
	 *
	 * @param array<string,mixed>|null $metadata The metadata.
	 *
	 * @return void
	 */
	public function setMetadata(?array $metadata): void {
		$this->metadata = $metadata;
	}//end setMetadata()

	/**
	 * Get the completing identity.
	 *
	 * @return string|null The completer uid.
	 */
	public function getCompletedBy(): ?string {
		return $this->completedBy;
	}//end getCompletedBy()

	/**
	 * Set the completing identity.
	 *
	 * @param string|null $completedBy The completer uid.
	 *
	 * @return void
	 */
	public function setCompletedBy(?string $completedBy): void {
		$this->completedBy = $completedBy;
	}//end setCompletedBy()

	/**
	 * Get the assigned identity.
	 *
	 * @return string|null The assignee uid.
	 */
	public function getAssignee(): ?string {
		return $this->assignee;
	}//end getAssignee()

	/**
	 * Set the assigned identity.
	 *
	 * @param string|null $assignee The assignee uid.
	 *
	 * @return void
	 */
	public function setAssignee(?string $assignee): void {
		$this->assignee = $assignee;
	}//end setAssignee()

	/**
	 * Get the completion comment.
	 *
	 * @return string|null The comment.
	 */
	public function getComment(): ?string {
		return $this->comment;
	}//end getComment()

	/**
	 * Set the completion comment.
	 *
	 * @param string|null $comment The comment.
	 *
	 * @return void
	 */
	public function setComment(?string $comment): void {
		$this->comment = $comment;
	}//end setComment()

	/**
	 * Get the completion result text.
	 *
	 * @return string|null The result text.
	 */
	public function getResultText(): ?string {
		return $this->resultText;
	}//end getResultText()

	/**
	 * Set the completion result text.
	 *
	 * @param string|null $resultText The result text.
	 *
	 * @return void
	 */
	public function setResultText(?string $resultText): void {
		$this->resultText = $resultText;
	}//end setResultText()
}//end class
