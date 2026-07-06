<?php

/**
 * Hermiq Initialize Settings Repair Step
 *
 * Repair step that initializes Hermiq register and schemas on install/upgrade.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that initializes Hermiq configuration via SettingsService.
 *
 * @spec openspec/specs/configuration-initialization/spec.md#REQ-INIT-002
 */
class InitializeSettings implements IRepairStep
{
    /**
     * Constructor for InitializeSettings.
     *
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger          The logger interface
     *
     * @return void
     */
    public function __construct(
        private SettingsService $settingsService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     *
     * @spec exclude Trivial IRepairStep display-name accessor; no behavioural spec.
     */
    public function getName(): string
    {
        return 'Initialize Hermiq register and schemas via ConfigurationService';
    }//end getName()

    /**
     * Run the repair step to initialize Hermiq configuration.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     *
     * @spec openspec/specs/configuration-initialization/spec.md#REQ-INIT-002
     */
    public function run(IOutput $output): void
    {
        $output->info('Initializing Hermiq configuration...');

        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning(
                'OpenRegister is not installed or enabled. Skipping auto-configuration.'
            );
            $this->logger->warning(
                'Hermiq: OpenRegister not available, skipping register initialization'
            );
            return;
        }

        try {
            $result = $this->settingsService->loadConfiguration(force: false);

            if ($result['success'] === true) {
                $version = ($result['version'] ?? 'unknown');
                $output->info(
                    'Hermiq configuration imported successfully (version: '.$version.')'
                );
                return;
            }

            $message = ($result['message'] ?? 'unknown error');
            $output->warning(
                'Hermiq configuration import issue: '.$message
            );
        } catch (\Throwable $e) {
            $output->warning('Could not auto-configure Hermiq: '.$e->getMessage());
            $this->logger->error(
                'Hermiq initialization failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end run()
}//end class
