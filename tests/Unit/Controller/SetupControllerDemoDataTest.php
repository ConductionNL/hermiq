<?php

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SetupController;
use OCA\Hermiq\Service\DemoDataService;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ADR-042 / ADR-111 — the example-data steps.
 *
 * The assertions here are about what the wizard can OBSERVE. A step the status
 * document never mentions resolves to `done: false` for ever, and an optional
 * step that can never be marked done keeps the wizard open over every page —
 * so "the step is reported" and "a decision closes it" are the contract, not
 * incidental detail.
 *
 * 🔴 THIS FILE EXISTS BECAUSE DECLINING WAS UNSAYABLE. The app implemented a
 * `skip-demo-data` action and no manifest step could reach it: the only step
 * was the run-action that INSTALLS. So an operator who did not want example
 * data had no way to record that, and CnAppRoot reopened the wizard over every
 * page until they imported data they did not want.
 */
class SetupControllerDemoDataTest extends TestCase {
	private IAppConfig $appConfig;
	private DemoDataService $demoData;
	private array $written = [];
	private array $config = [];

	protected function setUp(): void {
		$this->written = [];
		$this->config = [];
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')
			->willReturnCallback(function (string $app, string $key, string $default = ''): string {
				return ($this->config[$key] ?? $default);
			});
		$this->appConfig->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value): bool {
				$this->written[$key] = $value;

				return true;
			});
		$this->demoData = $this->createMock(DemoDataService::class);
	}

	private function controller(array $params = []): SetupController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		// 🔴 BOTH READERS, OR THE VALIDATION IS INVISIBLE TO THE TEST.
		// saveConfig() reads the dataset with getParam() and the rest of the
		// body with getParams(); a fake that answers only the second makes
		// every assertion about the first pass for the wrong reason.
		$request->method('getParam')
			->willReturnCallback(static function (string $key, $default = null) use ($params) {
				return ($params[$key] ?? $default);
			});

		return new SetupController(
			$request,
			$this->createMock(IClientService::class),
			$this->appConfig,
			$this->createMock(LoggerInterface::class),
			$this->demoData
		);
	}

	public function testStatusReportsEveryManifestStepId(): void {
		$this->demoData->method('listChoices')->willReturn([]);

		$steps = $this->controller()->status()->getData()['steps'];

		// Absence is the defect this guards: a step the wizard is never told
		// about stays open and reopens the wizard. The ids come from the
		// manifest the wizard renders, not from a list kept here by hand.
		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);
		$declared = array_column($manifest['setup']['steps'], 'id');
		$reported = array_keys($steps);
		sort($declared);
		sort($reported);
		$this->assertSame($declared, $reported);
		$this->assertFalse($steps['demo-data']['done']);
	}

	public function testTheDatasetStepLoadsFromItsCards(): void {
		// One cards step with `loadAction` replaces the choice step plus the
		// run-action step that loaded the pick (wizard-dataset-card-load).
		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);
		$steps = array_column($manifest['setup']['steps'], null, 'id');

		$this->assertSame('load-demo-data', $steps['demo-data']['loadAction'] ?? null);
		$this->assertArrayNotHasKey('load-demo-data', $steps);
	}

	public function testStatusCarriesTheOptionListTheChoiceStepReads(): void {
		// 🔴 THIS RESPONSE *IS* THE OPTION LIST. The step declares
		// `optionsSource: datasets` and carries no options of its own, so a
		// dataset missing here is a dataset nobody can pick.
		$this->demoData->method('listChoices')->willReturn([
			['id' => 'none', 'label' => 'None', 'description' => 'Nothing.', 'objectCount' => 0, 'icon' => 'CloseCircleOutline'],
			['id' => 'demo', 'label' => 'Example data', 'description' => 'Sample values.', 'objectCount' => 12, 'icon' => 'DatabaseOutline'],
		]);

		$data = $this->controller()->status()->getData();

		$this->assertSame(['none', 'demo'], array_column($data['datasets'], 'id'));
		$this->assertSame(12, $data['datasets'][1]['objectCount']);
	}

	public function testChoosingNoneClosesTheStepWithoutRunningAnything(): void {
		$this->demoData->method('listChoices')->willReturn([]);
		$this->config['demo_dataset'] = 'none';

		$steps = $this->controller()->status()->getData()['steps'];

		$this->assertTrue($steps['demo-data']['done']);
		$this->assertArrayNotHasKey('load-demo-data', $steps);
	}

	public function testTheChoiceIsPersisted(): void {
		$this->demoData->method('listChoices')->willReturn([
			['id' => 'none', 'label' => 'None', 'description' => '', 'objectCount' => 0, 'icon' => ''],
			['id' => 'demo', 'label' => 'Example data', 'description' => '', 'objectCount' => 1, 'icon' => ''],
		]);

		$data = $this->controller(['demo_dataset' => 'demo'])->saveConfig()->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('demo', $this->written['demo_dataset'] ?? null);
	}

	public function testAnUnknownDatasetIsRefusedRatherThanStored(): void {
		// Storing it would leave the load step pointing at nothing, so the
		// failure would surface one step later with no clue why.
		$this->demoData->method('listChoices')->willReturn([
			['id' => 'none', 'label' => 'None', 'description' => '', 'objectCount' => 0, 'icon' => ''],
		]);

		$response = $this->controller(['demo_dataset' => 'atlantis'])->saveConfig();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame([], $this->written);
	}

	public function testSkippingClosesBOTHStepsOrTheWizardNeverCloses(): void {
		$response = $this->controller()->runAction('skip-demo-data');

		$this->assertTrue($response->getData()['success']);
		$this->assertSame('skipped', $this->written['demo_data_decided'] ?? null);
		$this->assertSame('none', $this->written['demo_dataset'] ?? null, 'skipping IS choosing none');
	}

	public function testLoadingWithoutAChoiceRefusesRatherThanGuessing(): void {
		// 🔴 NO SILENT DEFAULT. Importing because the operator clicked Run one
		// step early would plant example objects nobody asked for.
		$this->demoData->expects($this->never())->method('install');

		$response = $this->controller()->runAction('load-demo-data');

		$this->assertSame(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testChoosingNoneAndThenRunningImportsNothing(): void {
		$this->config['demo_dataset'] = 'none';
		$this->demoData->expects($this->never())->method('install');

		$data = $this->controller()->runAction('load-demo-data')->getData();

		$this->assertTrue($data['success']);
		$this->assertStringContainsString('No example data', $data['message']);
	}

	public function testTheLegacyActionStillImportsTheShippedDataset(): void {
		// `install-demo-data` was the id before the step asked WHICH dataset. A
		// runbook or script that still posts it must keep working, and it names
		// the shipped set by naming itself.
		$this->demoData->method('install')->willReturn(['objects' => 7, 'registers' => 1, 'schemas' => 2]);

		$data = $this->controller()->runAction('install-demo-data')->getData();

		$this->assertTrue($data['success']);
		$this->assertStringContainsString('7', $data['message']);
	}

	public function testAFailedLoadIsReportedAndLeavesTheStepUNDECIDED(): void {
		// 🔴 Recording the decision here would close the step for an operator
		// who asked for example data and received none: the wizard would never
		// offer it again, and nothing would have been imported.
		$this->config['demo_dataset'] = 'demo';
		$this->demoData->method('install')->willThrowException(new RuntimeException('OpenRegister is not installed.'));

		$response = $this->controller()->runAction('load-demo-data');

		$this->assertFalse($response->getData()['success']);
		$this->assertArrayNotHasKey('demo_data_decided', $this->written);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function choices(): array {
		return [
			['id' => 'none', 'label' => 'None', 'description' => '', 'objectCount' => 0, 'icon' => ''],
			['id' => 'demo', 'label' => 'Example data', 'description' => '', 'objectCount' => 7, 'icon' => ''],
		];
	}

	public function testTheCardPostsItsDatasetAndTheLoadRecordsTheChoice(): void {
		// The card's Load button posts `{ dataset }` to the step's
		// `loadAction`. Nothing was stored before: the card IS the choice.
		$this->demoData->method('listChoices')->willReturn($this->choices());
		$this->demoData->expects($this->once())->method('install')
			->willReturn(['objects' => 7, 'registers' => 1, 'schemas' => 2]);

		$data = $this->controller(['dataset' => 'demo'])->runAction('load-demo-data')->getData();

		$this->assertTrue($data['success']);
		$this->assertStringContainsString('7', $data['message']);
		$this->assertSame(['demo_dataset' => 'demo', 'demo_data_decided' => 'installed'], $this->written);
	}

	public function testAnUnknownPostedDatasetIsRefusedAndNothingLoads(): void {
		$this->config['demo_dataset'] = 'demo';
		$this->demoData->method('listChoices')->willReturn($this->choices());
		$this->demoData->expects($this->never())->method('install');

		$response = $this->controller(['dataset' => 'atlantis'])->runAction('load-demo-data');

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('atlantis', $response->getData()['message']);
		$this->assertSame([], $this->written);
	}

	public function testAFailedCardLoadStoresNothing(): void {
		$this->demoData->method('listChoices')->willReturn($this->choices());
		$this->demoData->method('install')->willThrowException(new RuntimeException('OpenRegister is not installed.'));

		$response = $this->controller(['dataset' => 'demo'])->runAction('load-demo-data');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame([], $this->written);
	}
}
