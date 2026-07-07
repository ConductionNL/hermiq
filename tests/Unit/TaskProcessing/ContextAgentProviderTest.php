<?php

/**
 * Unit tests for ContextAgentProvider (contextagent-provider).
 *
 * Covers the thin adapter contract: task-type id, and that process() parses the NC
 * ContextAgent input (input/confirmation/conversation_token) and forwards it to the
 * interaction service, returning the service result.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\TaskProcessing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contextagent-provider/tasks.md#task-1-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\TaskProcessing;

use OCA\Hermiq\Service\ContextAgentInteractionService;
use OCA\Hermiq\TaskProcessing\ContextAgentProvider;
use OCP\TaskProcessing\TaskTypes\ContextAgentInteraction;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ContextAgentProvider.
 *
 * @spec openspec/changes/contextagent-provider/tasks.md#task-1-1
 */
class ContextAgentProviderTest extends TestCase
{
    /**
     * The provider reports the contextagent interaction task-type id.
     *
     * @return void
     *
     * @spec openspec/changes/contextagent-provider/tasks.md#task-1-1
     */
    public function testTaskTypeId(): void
    {
        $provider = new ContextAgentProvider($this->createMock(ContextAgentInteractionService::class));
        $this->assertSame(ContextAgentInteraction::ID, $provider->getTaskTypeId());
        $this->assertSame('core:contextagent:interaction', $provider->getTaskTypeId());
    }//end testTaskTypeId()

    /**
     * process() parses the input and forwards it to the interaction service,
     * returning the service's result verbatim.
     *
     * @return void
     *
     * @spec openspec/changes/contextagent-provider/tasks.md#task-1-2
     */
    public function testProcessForwardsToService(): void
    {
        $expected = ['output' => 'hi', 'conversation_token' => 'conv-1', 'actions' => '{}'];

        $service = $this->createMock(ContextAgentInteractionService::class);
        $service->expects($this->once())
            ->method('interact')
            ->with('bob', 'hello', 1, 'conv-1')
            ->willReturn($expected);

        $provider = new ContextAgentProvider($service);
        $result   = $provider->process(
            'bob',
            ['input' => 'hello', 'confirmation' => 1, 'conversation_token' => 'conv-1'],
            static fn (float $p): bool => true
        );

        $this->assertSame($expected, $result);
    }//end testProcessForwardsToService()

    /**
     * An absent confirmation slot is forwarded as null (not answering a prior request).
     *
     * @return void
     *
     * @spec openspec/changes/contextagent-provider/tasks.md#task-1-2
     */
    public function testProcessTreatsAbsentConfirmationAsNull(): void
    {
        $service = $this->createMock(ContextAgentInteractionService::class);
        $service->expects($this->once())
            ->method('interact')
            ->with('bob', 'hello', null, '')
            ->willReturn(['output' => 'x', 'conversation_token' => 'c', 'actions' => '{}']);

        $provider = new ContextAgentProvider($service);
        $provider->process('bob', ['input' => 'hello'], static fn (float $p): bool => true);
    }//end testProcessTreatsAbsentConfirmationAsNull()
}//end class
