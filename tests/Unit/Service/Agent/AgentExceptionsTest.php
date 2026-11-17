<?php

/**
 * Unit tests for the Agent service exceptions and the HTTP codes they carry.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Agent
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
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Agent;

use OCA\Hermiq\Service\Agent\AgentDraftUnreadableException;
use OCA\Hermiq\Service\Agent\AgentSwitchedOffException;
use OCA\Hermiq\Service\Agent\StartValuesRejectedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Each exception carries the message and code its controller turns into a response.
 *
 * @covers \OCA\Hermiq\Service\Agent\AgentDraftUnreadableException
 * @covers \OCA\Hermiq\Service\Agent\AgentSwitchedOffException
 * @covers \OCA\Hermiq\Service\Agent\StartValuesRejectedException
 */
class AgentExceptionsTest extends TestCase {

	/**
	 * Rejected start answers are a 422 that keeps every problem, keyed by field.
	 *
	 * @return void
	 */
	public function testRejectedStartValuesKeepTheProblemsPerField(): void {
		$problems = ['department' => 'required', 'deadline' => 'not a date (YYYY-MM-DD)'];
		$exception = new StartValuesRejectedException(problems: $problems);

		$this->assertInstanceOf(RuntimeException::class, $exception);
		$this->assertSame(422, $exception->getCode());
		$this->assertSame('Some answers were not accepted.', $exception->getMessage());
		$this->assertSame($problems, $exception->getProblems());

	}//end testRejectedStartValuesKeepTheProblemsPerField()

	/**
	 * A switched-off agent is a 409 with its own error code.
	 *
	 * @return void
	 */
	public function testASwitchedOffAgentIsAConflict(): void {
		$exception = new AgentSwitchedOffException();

		$this->assertInstanceOf(RuntimeException::class, $exception);
		$this->assertSame(409, $exception->getCode());
		$this->assertSame('This agent is switched off.', $exception->getMessage());
		$this->assertSame('agent_switched_off', AgentSwitchedOffException::ERROR_CODE);

	}//end testASwitchedOffAgentIsAConflict()

	/**
	 * An unreadable draft is a 422.
	 *
	 * @return void
	 */
	public function testAnUnreadableDraftIsUnprocessable(): void {
		$exception = new AgentDraftUnreadableException();

		$this->assertInstanceOf(RuntimeException::class, $exception);
		$this->assertSame(422, $exception->getCode());
		$this->assertSame('This draft could not be read', $exception->getMessage());

	}//end testAnUnreadableDraftIsUnprocessable()
}//end class
