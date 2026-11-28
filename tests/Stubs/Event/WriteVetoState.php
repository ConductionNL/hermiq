<?php

/**
 * Minimal OpenRegister WriteVetoState stub for standalone unit runs and
 * static analysis.
 *
 * Mirrored from openregister lib/Event/WriteVetoState.php: the state a
 * pre-save listener uses to refuse a write (stop it, name the errors) or to
 * add fields to it. Keep it identical to the real trait.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

/**
 * Minimal WriteVetoState stub.
 */
trait WriteVetoState {

	/**
	 * Whether a listener stopped the write.
	 *
	 * @var boolean
	 */
	private bool $propagationStopped = false;

	/**
	 * The errors a listener refused the write with.
	 *
	 * @var array<string, mixed>
	 */
	private array $errors = [];

	/**
	 * Fields a listener adds to the write.
	 *
	 * @var array<string, mixed>
	 */
	private array $modifiedData = [];

	/**
	 * Whether a listener stopped the write.
	 *
	 * @return bool
	 */
	public function isPropagationStopped(): bool {
		return $this->propagationStopped;
	}//end isPropagationStopped()

	/**
	 * Stop the write.
	 *
	 * @return void
	 */
	public function stopPropagation(): void {
		$this->propagationStopped = true;
	}//end stopPropagation()

	/**
	 * Set the errors the write is refused with.
	 *
	 * @param array<string, mixed> $errors The errors.
	 *
	 * @return void
	 */
	public function setErrors(array $errors): void {
		$this->errors = $errors;
	}//end setErrors()

	/**
	 * The errors the write is refused with.
	 *
	 * @return array<string, mixed>
	 */
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()

	/**
	 * Set the fields a listener adds to the write.
	 *
	 * @param array<string, mixed> $data The fields.
	 *
	 * @return void
	 */
	public function setModifiedData(array $data): void {
		$this->modifiedData = $data;
	}//end setModifiedData()

	/**
	 * The fields a listener adds to the write.
	 *
	 * @return array<string, mixed>
	 */
	public function getModifiedData(): array {
		return $this->modifiedData;
	}//end getModifiedData()
}//end trait
