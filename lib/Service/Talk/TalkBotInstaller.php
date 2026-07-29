<?php

/**
 * Hermiq TalkBotInstaller.
 *
 * Registers (and removes) Hermiq's Talk bot through spreed's own bot
 * lifecycle, by dispatching `BotInstallEvent` / `BotUninstallEvent`.
 *
 * Using the `nextcloudapp://` URL scheme means spreed dispatches
 * `BotInvokeEvent` in-process for this bot: no reachable callback URL, no
 * shared secret to rotate, and no outbound network access. spreed still
 * requires a secret on the row, so one is generated and stored — it is never
 * used to sign anything on this path.
 *
 * Installing the bot does NOT make any agent reachable: a Talk moderator must
 * still enable it per conversation, and the agent must be Talk-enabled in
 * Hermiq. Uninstalling is the rollback lever — it stops all inbound dispatch
 * without a code change.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Talk
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

namespace OCA\Hermiq\Service\Talk;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Installs and removes the Hermiq Talk bot.
 *
 * @psalm-api
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-hermiq-registers-as-an-in-process-talk-bot
 */
class TalkBotInstaller
{

    /**
     * IAppConfig key holding the generated bot secret.
     *
     * @var string
     */
    private const SECRET_KEY = 'talk_bot_secret';

    /**
     * Spreed's BotInstallEvent class, referenced by name so Hermiq does not
     * hard-depend on spreed being installed.
     *
     * @var string
     */
    private const INSTALL_EVENT = 'OCA\\Talk\\Events\\BotInstallEvent';

    /**
     * Spreed's BotUninstallEvent class.
     *
     * @var string
     */
    private const UNINSTALL_EVENT = 'OCA\\Talk\\Events\\BotUninstallEvent';

    /**
     * Feature bits: in-process invocation, may post, may react.
     *
     * FEATURE_EVENT (4) is what makes spreed dispatch BotInvokeEvent at all;
     * FEATURE_RESPONSE (2) and FEATURE_REACTION (8) cover answering and the
     * acknowledgement. FEATURE_WEBHOOK is deliberately absent — and spreed
     * strips it for app-prefixed URLs anyway.
     *
     * @var int
     */
    private const FEATURES = (4 | 2 | 8);

    /**
     * Constructor.
     *
     * @param IEventDispatcher $dispatcher   Dispatches spreed's lifecycle events.
     * @param IAppConfig       $appConfig    Stores the generated bot secret.
     * @param ISecureRandom    $secureRandom Generates the bot secret.
     * @param TalkBridge       $bridge       Talk availability probe.
     * @param LoggerInterface  $logger       PSR-3 logger.
     */
    public function __construct(
        private readonly IEventDispatcher $dispatcher,
        private readonly IAppConfig $appConfig,
        private readonly ISecureRandom $secureRandom,
        private readonly TalkBridge $bridge,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Install the bot, if Talk is present.
     *
     * Idempotent: spreed updates the existing row when the same URL+secret is
     * installed again. A no-op — never an error — when Talk is absent.
     *
     * @return bool True when the bot was installed.
     *
     * @psalm-suppress UndefinedClass Spreed is an OPTIONAL runtime dependency, so its event
     * classes are absent from static analysis; the class_exists() guard is what makes the
     * dynamic instantiation safe at runtime.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-hermiq-registers-as-an-in-process-talk-bot
     */
    public function install(): bool
    {
        if ($this->bridge->isAvailable() === false || class_exists(self::INSTALL_EVENT) === false) {
            return false;
        }

        try {
            $eventClass = self::INSTALL_EVENT;

            $event = new $eventClass(
                TalkBridge::BOT_NAME,
                $this->secret(),
                TalkBridge::BOT_URL,
                'Converse with a Hermiq agent from this conversation.',
                self::FEATURES
            );

            $this->dispatcher->dispatchTyped($event);

            $this->logger->info(
                message: '[TalkBotInstaller] Hermiq Talk bot installed',
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'url'  => TalkBridge::BOT_URL,
                ]
            );

            return true;
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[TalkBotInstaller] Could not install the Hermiq Talk bot',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
            return false;
        }//end try

    }//end install()

    /**
     * Remove the bot, stopping all inbound dispatch.
     *
     * @return bool True when the bot was removed.
     *
     * @psalm-suppress UndefinedClass See install(): spreed is optional and absent from analysis.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-hermiq-registers-as-an-in-process-talk-bot
     */
    public function uninstall(): bool
    {
        if ($this->bridge->isAvailable() === false || class_exists(self::UNINSTALL_EVENT) === false) {
            return false;
        }

        try {
            $eventClass = self::UNINSTALL_EVENT;

            $event = new $eventClass(TalkBridge::BOT_URL, $this->secret());

            $this->dispatcher->dispatchTyped($event);

            return true;
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[TalkBotInstaller] Could not remove the Hermiq Talk bot',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
            return false;
        }//end try

    }//end uninstall()

    /**
     * The stable bot secret, generated once on first use.
     *
     * Spreed requires a secret on the bot row even for in-process bots, where
     * nothing is ever signed with it. It must stay STABLE, because spreed
     * matches an existing bot by URL+secret — a rotating secret would make
     * every install attempt look like a different bot and be rejected.
     *
     * @return string The bot secret.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-hermiq-registers-as-an-in-process-talk-bot
     */
    private function secret(): string
    {
        $secret = $this->appConfig->getValueString('hermiq', self::SECRET_KEY, '');
        if ($secret !== '') {
            return $secret;
        }

        $secret = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
        $this->appConfig->setValueString('hermiq', self::SECRET_KEY, $secret, sensitive: true);

        return $secret;

    }//end secret()
}//end class
