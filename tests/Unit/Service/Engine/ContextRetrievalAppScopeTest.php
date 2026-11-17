<?php

/**
 * An agent tied to an app, with no views of its own, searches that app's registers
 * and names the register of each source (agents-bound-to-their-app, REQ-APPAG-003).
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
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

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\AppRegisterScope;
use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ContextRetrievalAppScopeTest extends TestCase {

	/**
	 * The searches made.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The register filters asked for.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $registerFilters = [];

	private function register(int $id, string $slug, string $title): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug($slug);
		$register->setTitle($title);
		return $register;
	}//end register()

	private function handler(array $registers): ContextRetrievalHandler {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query): array {
				$this->queries[] = $query;
				$byRegister = [
					7 => [['id' => 'app-1', 'name' => 'Application 2026-014', '_score' => 0.4]],
					8 => [['id' => 'dec-1', 'name' => 'Decision 2026-003', '_score' => 0.9]],
				];
				return ['results' => ($byRegister[$query['_register']] ?? []), 'total' => 1];
			}
		);

		$mapper = $this->createMock(RegisterMapper::class);
		$mapper->method('findAll')->willReturnCallback(
			function (...$args) use ($registers): array {
				$this->registerFilters[] = ($args['filters'] ?? $args[2] ?? []);
				return $registers;
			}
		);

		return new ContextRetrievalHandler(
			objectService: $objects,
			logger: new NullLogger(),
			appScope: new AppRegisterScope(registerMapper: $mapper, logger: new NullLogger())
		);
	}//end handler()

	private function agent(array $data): ObjectEntity {
		$agent = new ObjectEntity();
		$agent->setUuid('agent');
		$agent->setObject(array_merge(['ragSearchMode' => 'keyword', 'ragNumSources' => 5, 'views' => []], $data));
		return $agent;
	}//end agent()

	public function testAnAppsAgentSearchesOnlyThatAppsRegistersAndNamesThem(): void {
		$context = $this->handler([$this->register(7, 'subsidies', 'Subsidies'), $this->register(8, 'subsidy-decisions', 'Subsidy decisions')])
			->retrieveContext(query: 'waiting for a decision', agent: $this->agent(['applicationSlug' => 'subsidies']));

		self::assertSame([['application' => 'subsidies']], $this->registerFilters);
		self::assertSame([7, 8], array_column($this->queries, '_register'));
		self::assertSame(['dec-1', 'app-1'], array_column($context['sources'], 'id'), 'merged by score');
		self::assertSame(['Subsidy decisions', 'Subsidies'], array_column($context['sources'], 'register'));
		self::assertStringContainsString('Source: Decision 2026-003 (register: Subsidy decisions)', $context['text']);
	}//end testAnAppsAgentSearchesOnlyThatAppsRegistersAndNamesThem()

	public function testAnAppWithoutRegistersSearchesNothing(): void {
		$context = $this->handler([])->retrieveContext(query: 'x', agent: $this->agent(['applicationSlug' => 'subsidies']));

		self::assertSame([], $this->queries);
		self::assertSame([], $context['sources']);
	}//end testAnAppWithoutRegistersSearchesNothing()

	public function testExplicitViewsWinOverTheApp(): void {
		$this->handler([$this->register(7, 'subsidies', 'Subsidies')])
			->retrieveContext(query: 'x', agent: $this->agent(['applicationSlug' => 'subsidies', 'views' => ['view-1']]));

		self::assertSame([], $this->registerFilters);
		self::assertNull($this->queries[0]['_register']);
	}//end testExplicitViewsWinOverTheApp()

	public function testAnAgentWithoutAppOrViewsStillSearchesNothing(): void {
		$this->handler([$this->register(7, 'subsidies', 'Subsidies')])->retrieveContext(query: 'x', agent: $this->agent([]));

		self::assertSame([], $this->queries);
		self::assertSame([], $this->registerFilters);
	}//end testAnAgentWithoutAppOrViewsStillSearchesNothing()
}//end class
