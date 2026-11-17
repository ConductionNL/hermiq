<?php

/**
 * Characterisation of WorkloadStageRun: how one flow item's configuration is
 * rendered into the stage request the workload broker receives, field by
 * field, including the placeholder rendering of credentials, the push
 * declaration and the async job key.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Flow
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

namespace OCA\Hermiq\Tests\Unit\Flow;

use OCA\Hermiq\Flow\WorkloadStageRun;
use PHPUnit\Framework\TestCase;

/**
 * Rendering of a workload stage from flow configuration.
 *
 * @covers \OCA\Hermiq\Flow\WorkloadStageRun
 * @uses   \OCA\Hermiq\Flow\ItemPlaceholders
 * @uses   \OCA\Hermiq\Service\StageRequest
 * @uses   \OCA\Hermiq\Service\StageOptions
 */
final class WorkloadStageRunTest extends TestCase {

	private const ITEM = [
		'repo' => 'example-org/app',
		'branch' => 'feature-a',
		'cred' => ['forge' => 'cred-9', 'push' => 'cred-push', 'llm' => 'cred-llm'],
		'paths' => ['lib', 'src'],
	];

	/**
	 * Every field of the request and its options is rendered from the item.
	 *
	 * @return void
	 */
	public function testTheStageRequestIsRenderedFromTheItem(): void {
		$run = new WorkloadStageRun(
			config: [
				'repo' => 'https://forge.example/{{repo}}.git',
				'ref' => '{{ branch }}',
				'command' => ['gate', '--app={{repo}}', 'paths={{paths}}'],
				'credentialId' => ' {{cred.forge}} ',
				'pushCredentialId' => '{{cred.push}}',
				'llmCredentialId' => '{{cred.llm}}',
				'toolRepo' => 'example-org/hydra',
				'toolRef' => 'main',
				'timeoutMs' => '120000',
				'push' => [
					'branch' => 'bot/{{branch}}',
					'paths' => ['{{paths}}', ['dropped'], 'docs'],
				],
				'collect' => ['report.json'],
			],
			json: self::ITEM
		);

		$request = $run->stageRequest(owner: 'alice');

		self::assertSame(
			['https://forge.example/example-org/app.git', 'feature-a', ['gate', '--app=example-org/app', 'paths=["lib","src"]'], 'alice'],
			[$request->repo, $request->ref, $request->command, $request->uid]
		);
		$options = $request->options;
		self::assertSame(
			[
				'credentialId' => 'cred-9',
				'timeoutMs' => 120000,
				'toolRepo' => 'example-org/hydra',
				'toolRef' => 'main',
				'push' => ['branch' => 'bot/feature-a', 'paths' => ['["lib","src"]', 'docs']],
				'pushCredentialId' => 'cred-push',
				'llmCredentialId' => 'cred-llm',
				'collect' => ['report.json'],
				'jobKey' => '',
			],
			[
				'credentialId' => $options->credentialId,
				'timeoutMs' => $options->timeoutMs,
				'toolRepo' => $options->toolRepo,
				'toolRef' => $options->toolRef,
				'push' => $options->push,
				'pushCredentialId' => $options->pushCredentialId,
				'llmCredentialId' => $options->llmCredentialId,
				'collect' => $options->collect,
				'jobKey' => $options->jobKey,
			]
		);
	}//end testTheStageRequestIsRenderedFromTheItem()

	/**
	 * A minimal configuration gives an empty, read-only, synchronous stage.
	 *
	 * @return void
	 */
	public function testAMinimalConfigurationIsReadOnlyAndSynchronous(): void {
		$run = new WorkloadStageRun(config: ['repo' => '{{repo}}', 'jobKey' => 'ignored'], json: self::ITEM);

		$request = $run->stageRequest(owner: 'bob');

		self::assertFalse($run->isAsync());
		self::assertSame(['example-org/app', '', []], [$request->repo, $request->ref, $request->command]);
		self::assertSame(
			['', 0, [], '', '', [], ''],
			[
				$request->options->credentialId,
				$request->options->timeoutMs,
				$request->options->push,
				$request->options->pushCredentialId,
				$request->options->llmCredentialId,
				$request->options->collect,
				$request->options->jobKey,
			]
		);
	}//end testAMinimalConfigurationIsReadOnlyAndSynchronous()

	/**
	 * Only a literal true makes a stage async, and only then is its job key rendered.
	 *
	 * @return void
	 */
	public function testOnlyAnAsyncStageCarriesAJobKey(): void {
		$async = new WorkloadStageRun(config: ['async' => true, 'jobKey' => ' gate-{{repo}} '], json: self::ITEM);
		$truthy = new WorkloadStageRun(config: ['async' => 'yes', 'jobKey' => 'gate-{{repo}}'], json: self::ITEM);

		self::assertTrue($async->isAsync());
		self::assertSame('gate-example-org/app', $async->stageRequest(owner: 'alice')->options->jobKey);
		self::assertFalse($truthy->isAsync());
		self::assertSame('', $truthy->stageRequest(owner: 'alice')->options->jobKey);
	}//end testOnlyAnAsyncStageCarriesAJobKey()

	/**
	 * A push declaration that is not a non-empty list declares no push.
	 *
	 * @return void
	 */
	public function testAnEmptyOrScalarPushDeclaresNoPush(): void {
		foreach ([[], 'branch', null] as $push) {
			$run = new WorkloadStageRun(config: ['push' => $push], json: self::ITEM);
			self::assertSame([], $run->stageRequest(owner: 'alice')->options->push, var_export($push, true));
		}
	}//end testAnEmptyOrScalarPushDeclaresNoPush()

	/**
	 * rendered() and trimmed() read one configured value through the item.
	 *
	 * @return void
	 */
	public function testRenderedAndTrimmedValues(): void {
		$run = new WorkloadStageRun(config: ['title' => '  Gate {{repo}}  '], json: self::ITEM);

		self::assertSame(['  Gate example-org/app  ', 'Gate example-org/app', ''], [$run->rendered(key: 'title'), $run->trimmed(key: 'title'), $run->rendered(key: 'missing')]);
	}//end testRenderedAndTrimmedValues()
}//end class
