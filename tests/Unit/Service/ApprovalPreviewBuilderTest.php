<?php

/**
 * Unit tests for ApprovalPreviewBuilder (oversight-what-an-approval-will-do).
 *
 * The catalog descriptors below have the shape of hermiq's own native tool
 * descriptors (lib/Mcp/NcNativeWriteToolDescriptors.php): scope, readOnlyHint,
 * destructiveHint and a declared reach.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/oversight-what-an-approval-will-do/tasks.md#task-1-the-preview-builder
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\ApprovalPreviewBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What a held action will do.
 *
 * @spec openspec/changes/oversight-what-an-approval-will-do/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001
 */
class ApprovalPreviewBuilderTest extends TestCase {

	/**
	 * The catalog.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function catalog(): array {
		return [
			['mcpId' => 'files.deleteFile', 'name' => 'files.deleteFile', 'readOnlyHint' => false, 'destructiveHint' => true, 'scope' => 'delete', 'reach' => 'user'],
			['mcpId' => 'files.listFiles', 'name' => 'files.listFiles', 'readOnlyHint' => true, 'destructiveHint' => false, 'scope' => 'read', 'reach' => 'user'],
			['mcpId' => 'mail.sendMail', 'name' => 'mail.sendMail', 'readOnlyHint' => false, 'destructiveHint' => true, 'scope' => 'create', 'reach' => 'external'],
			['mcpId' => 'calendar.upsertEvent', 'name' => 'calendar.upsertEvent', 'readOnlyHint' => false, 'destructiveHint' => false, 'scope' => 'update', 'reach' => 'user'],
		];
	}//end catalog()

	/**
	 * A builder over the catalog; the agent grants resolve to $resolved.
	 *
	 * @param array<int, string> $resolved The ids the grant resolver returns.
	 * @param int $catalogReads Out-param: how often the catalog was read.
	 *
	 * @return ApprovalPreviewBuilder
	 */
	private function builder(array $resolved = [], int &$catalogReads = 0): ApprovalPreviewBuilder {
		$registry = $this->createMock(ToolRegistryFacade::class);
		$registry->method('listTools')->willReturnCallback(
			function () use (&$catalogReads): array {
				$catalogReads++;
				return $this->catalog();
			}
		);

		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject(['name' => 'Supplier digest', 'tools' => ['files.*', 'mail.sendMail']]);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($agent);

		$resolver = $this->createMock(ToolGrantResolver::class);
		$resolver->method('resolve')->willReturn($resolved);

		return new ApprovalPreviewBuilder($registry, $resolver, $objects, $this->createMock(LoggerInterface::class));
	}//end builder()

	/**
	 * A held deletion names the tool, "deletes", its reach and the redacted path.
	 *
	 * @return void
	 */
	public function testAHeldDeletionSaysWhatItDeletesAndHowFarItReaches(): void {
		$preview = $this->builder()->build(
			[
				'sourceType' => 'toolcall',
				'agentId' => 'agent-1',
				'toolId' => 'files.deleteFile',
				'toolArguments' => ['path' => '/Archive/2019/old-invoices.pdf'],
			]
		);

		$this->assertSame('toolcall', $preview['kind']);
		$this->assertSame(
			[['id' => 'files.deleteFile', 'name' => 'files.deleteFile', 'effect' => 'deletes', 'reach' => 'user', 'arguments' => ['path' => '/Archive/2019/old-invoices.pdf']]],
			$preview['tools']
		);
		$this->assertNotSame('', $preview['heldBecause']);
	}//end testAHeldDeletionSaysWhatItDeletesAndHowFarItReaches()

	/**
	 * A held scheduled run lists the agent's granted tools with the same labels,
	 * and the catalog is read once.
	 *
	 * @return void
	 */
	public function testAHeldRunListsTheGrantedTools(): void {
		$reads = 0;
		$builder = $this->builder(['files.listFiles', 'mail.sendMail', 'calendar.upsertEvent'], $reads);
		$preview = $builder->build(['sourceType' => 'schedule', 'agentId' => 'agent-1', 'prompt' => 'Summarise supplier mail']);
		$builder->build(['sourceType' => 'schedule', 'agentId' => 'agent-1']);

		$this->assertSame('run', $preview['kind']);
		$this->assertSame(
			[['files.listFiles', 'reads', 'user'], ['mail.sendMail', 'sends', 'external'], ['calendar.upsertEvent', 'changes', 'user']],
			array_map(static fn (array $t): array => [$t['id'], $t['effect'], $t['reach']], $preview['tools'])
		);
		$this->assertSame(1, $reads);
	}//end testAHeldRunListsTheGrantedTools()

	/**
	 * A tool the catalog does not know is never described as reading.
	 *
	 * @return void
	 */
	public function testAnUnknownToolIsNotDescribedAsReading(): void {
		$preview = $this->builder()->build(['sourceType' => 'tool', 'agentId' => 'agent-1', 'toolId' => 'gone.tool']);

		$this->assertSame('changes', $preview['tools'][0]['effect']);
	}//end testAnUnknownToolIsNotDescribedAsReading()

	/**
	 * The Talk line names the tool and its reach.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/oversight-what-an-approval-will-do/specs/human-approval-gate/spec.md#requirement-the-talk-request-names-the-tool-and-its-reach-req-apprev-002
	 */
	public function testTheTalkLineNamesTheToolAndItsReach(): void {
		$this->assertSame(
			'The tool files.deleteFile deletes, and reaches your own files and data.',
			$this->builder()->toolLine('files.deleteFile')
		);
	}//end testTheTalkLineNamesTheToolAndItsReach()
}//end class
