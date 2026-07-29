<?php

/**
 * Hermiq InstallTalkBot repair step.
 *
 * Registers Hermiq's Talk bot on install/upgrade so an administrator can enable
 * it per conversation from Talk's own UI without running any `occ` command.
 *
 * Installing the bot activates NOTHING on its own: a Talk moderator must still
 * enable it in a conversation, and the agent bound to that room must be
 * Talk-enabled in Hermiq. Both default to off.
 *
 * A no-op — never a failure — when Talk is absent.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/talk-chat-bridge/tasks.md#1-bot-registration-and-lifecycle
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\Service\Talk\TalkBotInstaller;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Installs the Hermiq Talk bot when Talk is available.
 *
 * @psalm-api
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-hermiq-registers-as-an-in-process-talk-bot
 */
class InstallTalkBot implements IRepairStep
{
    /**
     * Constructor.
     *
     * @param TalkBotInstaller $installer Installs the bot through spreed's lifecycle.
     * @param LoggerInterface  $logger    PSR-3 logger.
     */
    public function __construct(
        private readonly TalkBotInstaller $installer,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * The repair step's human-readable name.
     *
     * @return string The step name.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-hermiq-registers-as-an-in-process-talk-bot
     */
    public function getName(): string
    {
        return 'Register the Hermiq Talk bot';

    }//end getName()

    /**
     * Run the step.
     *
     * Never throws: Talk is an optional runtime dependency, so a failure here
     * must not be able to break an install or upgrade.
     *
     * @param IOutput $output Migration output channel.
     *
     * @return void
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-hermiq-registers-as-an-in-process-talk-bot
     */
    public function run(IOutput $output): void
    {
        try {
            if ($this->installer->install() === true) {
                $output->info('Hermiq Talk bot registered — enable it per conversation in Talk.');
                return;
            }

            $output->info('Talk is not available — skipping the Hermiq Talk bot registration.');
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[InstallTalkBot] Could not register the Hermiq Talk bot',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
            $output->warning('Could not register the Hermiq Talk bot: '.$e->getMessage());
        }//end try

    }//end run()
}//end class
