<?php

/**
 * Unit tests for the InitializeSettings repair step.
 *
 * Covers the forced-import decision that keeps existing installs' schemas honest
 * (openregister#2075: `importFromApp(force: false)` advances OpenRegister's stored
 * version WITHOUT applying schema changes, so hermiq keeps its OWN
 * `register_version_applied` bookkeeping): the import is FORCED when the register
 * JSON's `info.version` differs from the last-applied version, skipped (unforced)
 * when they are equal, and the stored key advances only after a successful forced
 * apply — never on a failed import.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
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
 * @spec openspec/specs/configuration-initialization/spec.md#req-init-002-import-configuration-on-install-upgrade
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Repair\InitializeSettings;
use OCA\Hermiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the register-initialization repair step's forced-import bookkeeping.
 *
 * @spec openspec/specs/configuration-initialization/spec.md#req-init-002-import-configuration-on-install-upgrade
 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-skill-schema-carries-maturity-metadata-as-optional-inert-fields
 */
class InitializeSettingsTest extends TestCase
{

    /**
     * The prepared SettingsService mock.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService&MockObject $settingsService;

    /**
     * The prepared IAppConfig mock.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Build the repair step over fresh mocks.
     *
     * @param string $configVersion  The version hermiq_register.json declares.
     * @param string $appliedVersion The version the step last applied ('' = never).
     *
     * @return InitializeSettings
     */
    private function step(string $configVersion, string $appliedVersion): InitializeSettings
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
        $this->settingsService->method('getRegisterConfigVersion')->willReturn($configVersion);

        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')
            ->with(Application::APP_ID, 'register_version_applied', '')
            ->willReturn($appliedVersion);

        return new InitializeSettings(
            settingsService: $this->settingsService,
            appConfig: $this->appConfig,
            logger: $this->createMock(LoggerInterface::class),
        );

    }//end step()

    /**
     * When the register JSON's info.version differs from the stored
     * `register_version_applied`, the import MUST be forced — an unforced import
     * would advance OpenRegister's version without applying schema changes
     * (openregister#2075).
     *
     * @return void
     */
    public function testForcesImportWhenRegisterVersionDiffersFromApplied(): void
    {
        $step = $this->step(configVersion: '0.18.0', appliedVersion: '0.17.0');

        $this->settingsService->expects($this->once())
            ->method('loadConfiguration')
            ->with(true)
            ->willReturn(['success' => true, 'version' => '0.18.0']);

        $step->run($this->createMock(IOutput::class));

    }//end testForcesImportWhenRegisterVersionDiffersFromApplied()

    /**
     * When the stored applied version equals the register JSON's version, the
     * import runs UNFORCED and the bookkeeping key is never rewritten.
     *
     * @return void
     */
    public function testSkipsForceWhenRegisterVersionEqualsApplied(): void
    {
        $step = $this->step(configVersion: '0.18.0', appliedVersion: '0.18.0');

        $this->settingsService->expects($this->once())
            ->method('loadConfiguration')
            ->with(false)
            ->willReturn(['success' => true, 'version' => '0.18.0']);

        $this->appConfig->expects($this->never())->method('setValueString');

        $step->run($this->createMock(IOutput::class));

    }//end testSkipsForceWhenRegisterVersionEqualsApplied()

    /**
     * A successful FORCED apply advances the stored `register_version_applied`
     * key to the just-applied config version; a failed import never does (the
     * next run must force again).
     *
     * @return void
     */
    public function testUpdatesStoredAppliedVersionOnlyAfterSuccessfulApply(): void
    {
        // Success: the key advances to the applied version.
        $step = $this->step(configVersion: '0.18.0', appliedVersion: '0.17.0');
        $this->settingsService->method('loadConfiguration')->willReturn(['success' => true, 'version' => '0.18.0']);
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with(Application::APP_ID, 'register_version_applied', '0.18.0');
        $step->run($this->createMock(IOutput::class));

        // Failure: the key must NOT advance — the next run still forces.
        $step = $this->step(configVersion: '0.18.0', appliedVersion: '0.17.0');
        $this->settingsService->method('loadConfiguration')->willReturn(['success' => false, 'message' => 'import failed']);
        $this->appConfig->expects($this->never())->method('setValueString');
        $step->run($this->createMock(IOutput::class));

    }//end testUpdatesStoredAppliedVersionOnlyAfterSuccessfulApply()
}//end class
