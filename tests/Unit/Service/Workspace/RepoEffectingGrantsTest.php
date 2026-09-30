<?php

/**
 * The push resolves only from an argument-scoped grant, with the real
 * OpenRegister grant resolver parsing the grant strings, and narrowing never
 * downgrades its classification.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Workspace
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

namespace OCA\Hermiq\Tests\Unit\Service\Workspace;

use OCA\Hermiq\Mcp\WorkspaceToolDescriptors;
use OCA\Hermiq\Service\Workspace\RepoEffectingGrants;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-repo-effecting-tools-resolve-only-from-an-argument-scoped-grant
 */
final class RepoEffectingGrantsTest extends TestCase {

	/**
	 * A bare push grant does not resolve, and so is not listed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-a-bare-push-grant-does-not-resolve
	 */
	public function testABarePushGrantDoesNotResolve(): void {
		self::assertSame([WorkspaceToolDescriptors::OPEN], $this->listed(grants: [WorkspaceToolDescriptors::OPEN, WorkspaceToolDescriptors::PUSH]));
	}//end testABarePushGrantDoesNotResolve()

	/**
	 * A grant that pins the repository and constrains the branch resolves.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-an-argument-scoped-push-grant-resolves-and-is-enforced
	 */
	public function testAScopedPushGrantResolvesAndItsBranchIsEnforced(): void {
		$grants = [WorkspaceToolDescriptors::PUSH . '?repository=example-org/example-app&branch=in:feature-a,feature-b'];
		self::assertSame([WorkspaceToolDescriptors::PUSH], $this->listed(grants: $grants));

		$sets = $this->resolver()->argumentConstraints(grants: $grants)[WorkspaceToolDescriptors::PUSH];
		self::assertNull(ToolGrantResolver::violationFor($sets, ['repository' => 'example-org/example-app', 'branch' => 'feature-a']));
		self::assertSame('branch', ToolGrantResolver::violationFor($sets, ['repository' => 'example-org/example-app', 'branch' => 'main'])['argument'] ?? null);
		self::assertSame('repository', ToolGrantResolver::violationFor($sets, ['repository' => 'other/app', 'branch' => 'feature-a'])['argument'] ?? null);
	}//end testAScopedPushGrantResolvesAndItsBranchIsEnforced()

	/**
	 * A grant that pins only the repository, or sits beside a bare one, does not resolve.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-a-bare-push-grant-does-not-resolve
	 */
	public function testAHalfScopedOrWidenedGrantDoesNotResolve(): void {
		self::assertSame([], $this->listed(grants: [WorkspaceToolDescriptors::PUSH . '?repository=example-org/example-app']));
		self::assertSame([], $this->listed(grants: [WorkspaceToolDescriptors::PUSH . '?branch=feature-a']));
		self::assertSame([], $this->listed(grants: [WorkspaceToolDescriptors::PUSH . '?repository=in:a/b,c/d&branch=feature-a']));
		self::assertSame(
			[],
			$this->listed(grants: [WorkspaceToolDescriptors::PUSH . '?repository=example-org/example-app&branch=feature-a', WorkspaceToolDescriptors::PUSH])
		);
	}//end testAHalfScopedOrWidenedGrantDoesNotResolve()

	/**
	 * Narrowing does not downgrade the classification: the push stays destructive.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-narrowing-does-not-downgrade-classification
	 */
	public function testNarrowingDoesNotDowngradeClassification(): void {
		$push = $this->descriptor(id: WorkspaceToolDescriptors::PUSH);
		self::assertTrue($push['destructiveHint']);
		self::assertTrue(ToolGrantResolver::isWriteOrDestructive(id: WorkspaceToolDescriptors::PUSH, descriptor: $push));
		self::assertContains(WorkspaceToolDescriptors::PUSH, WorkspaceToolDescriptors::WRITE_IDS, 'The push passes the approval gate.');
	}//end testNarrowingDoesNotDowngradeClassification()

	/**
	 * Read, edit and push are separately grantable: an inspection-only agent is offered no write and no push.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-read-edit-and-push-are-separately-grantable
	 */
	public function testReadEditAndPushAreSeparatelyGrantable(): void {
		$read = [WorkspaceToolDescriptors::OPEN, WorkspaceToolDescriptors::STATUS, WorkspaceToolDescriptors::READ_FILE];
		self::assertSame($read, $this->listed(grants: $read));
		self::assertSame([WorkspaceToolDescriptors::WRITE_FILE], $this->listed(grants: [WorkspaceToolDescriptors::WRITE_FILE]));
	}//end testReadEditAndPushAreSeparatelyGrantable()

	/**
	 * The tool ids the agent is offered for these grants, through the real resolver and the rule.
	 *
	 * @param array<int, string> $grants The Agent.tools entries.
	 *
	 * @return array<int, string>
	 */
	private function listed(array $grants): array {
		$resolver = $this->resolver();
		$catalog = [];
		foreach (WorkspaceToolDescriptors::ALL as $descriptor) {
			$catalog[] = $descriptor + ['mcpId' => $descriptor['id']];
		}

		$descriptors = [];
		$resolved = array_flip($resolver->resolve(grants: $grants, catalog: $catalog));
		foreach ($catalog as $descriptor) {
			if (isset($resolved[$descriptor['mcpId']]) === true) {
				$descriptors[] = $descriptor;
			}
		}

		$kept = RepoEffectingGrants::filterDescriptors(descriptors: $descriptors, constraints: $resolver->argumentConstraints(grants: $grants));
		self::assertSame(
			array_column($kept, 'mcpId'),
			RepoEffectingGrants::filterIds(resolvedIds: array_column($descriptors, 'mcpId'), constraints: $resolver->argumentConstraints(grants: $grants))
		);
		return array_column($kept, 'mcpId');
	}//end listed()

	/**
	 * One descriptor by id.
	 *
	 * @param string $id The tool id.
	 *
	 * @return array<string, mixed>
	 */
	private function descriptor(string $id): array {
		foreach (WorkspaceToolDescriptors::ALL as $descriptor) {
			if ($descriptor['id'] === $id) {
				return $descriptor;
			}
		}

		self::fail('No descriptor ' . $id);
	}//end descriptor()

	/**
	 * The real OpenRegister grant resolver.
	 *
	 * @return ToolGrantResolver
	 */
	private function resolver(): ToolGrantResolver {
		return new ToolGrantResolver();
	}//end resolver()
}//end class
