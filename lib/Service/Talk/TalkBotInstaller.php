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
use Psr\Container\ContainerInterface;
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
     * Spreed's BotServerMapper — read-only, to resolve a bot row by URL.
     *
     * @var string
     */
    private const BOT_SERVER_MAPPER = 'OCA\\Talk\\Model\\BotServerMapper';

    /**
     * Spreed's BotConversationMapper — writes the per-room enablement row.
     *
     * @var string
     */
    private const BOT_CONVERSATION_MAPPER = 'OCA\\Talk\\Model\\BotConversationMapper';

    /**
     * Spreed's BotConversation entity.
     *
     * @var string
     */
    private const BOT_CONVERSATION = 'OCA\\Talk\\Model\\BotConversation';

    /**
     * Spreed's Bot::STATE_ENABLED.
     *
     * @var int
     */
    private const BOT_STATE_ENABLED = 1;

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
     * @param ContainerInterface $container    Resolves spreed mappers lazily.
     * @param IEventDispatcher   $dispatcher   Dispatches spreed's lifecycle events.
     * @param IAppConfig         $appConfig    Stores the generated bot secret.
     * @param ISecureRandom      $secureRandom Generates the bot secret.
     * @param TalkBridge         $bridge       Talk availability probe.
     * @param LoggerInterface    $logger       PSR-3 logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
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

            // 🔴 (secret, url) — NOT (url, secret). BotUninstallEvent takes the
            // secret FIRST, and this call had the two swapped since it was
            // written, so it never uninstalled anything: spreed looked up
            // url_hash=sha1(secret) AND secret=url, missed, threw
            // DoesNotExistException — and its handler swallows that silently.
            // A no-op that reports success. Verified live against spreed 24.0.1.
            $event = new $eventClass($this->secret(), TalkBridge::BOT_URL);

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
     * Install or rename the bot that carries ONE agent's identity in Talk.
     *
     * 🔴 Install and rename are the SAME call, because spreed's
     * `BotListener::handleBotInstallEvent()` is an upsert keyed on (url, secret):
     * it looks the row up by both, and on a hit calls `setName()` / `update()`.
     * There is no rename API — `BotService` exposes none — so re-dispatching an
     * install with a new name IS the rename.
     *
     * 🔴 That upsert key is also why the secret must stay STABLE. A different
     * secret against the same URL is not an update; spreed rejects it outright.
     * The single stored app secret is reused for every agent, so only the URL
     * varies — which is exactly what makes each agent its own row.
     *
     * Renaming re-signs history: `MessageParser` resolves a bot's display name
     * at READ time from the record, so past messages start rendering under the
     * new name. That is spreed's model rather than something Hermiq can choose.
     *
     * @param string $agentId   The agent's uuid.
     * @param string $agentName The agent's display name, shown in Talk.
     *
     * @return bool True when the bot was installed or renamed.
     *
     * @psalm-suppress UndefinedClass See install(): spreed is optional and absent from analysis.
     *
     * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-each-talk-enabled-agent-has-its-own-talk-bot-identity
     *
     * @SuppressWarnings(PHPMD.StaticAccess) TalkBridge::botUrlFor() is a pure
     * function of the agent id — see TalkBridge for why the URL helpers are
     * static rather than injectable.
     */
    public function installForAgent(string $agentId, string $agentName): bool
    {
        if ($agentId === '' || $this->bridge->isAvailable() === false || class_exists(self::INSTALL_EVENT) === false) {
            return false;
        }

        // Talk stores the name in a 64-char column, and an empty name would
        // leave the agent rendering as its raw actor id.
        $name = trim($agentName);
        if ($name === '') {
            $name = TalkBridge::BOT_NAME;
        }

        $name = mb_substr($name, 0, 64);

        try {
            $eventClass = self::INSTALL_EVENT;
            $this->dispatcher->dispatchTyped(
                new $eventClass(
                    $name,
                    $this->secretForAgent(agentId: $agentId),
                    TalkBridge::botUrlFor(agentId: $agentId),
                    'Converse with this Hermiq agent from this conversation.',
                    self::FEATURES
                )
            );

            return true;
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[TalkBotInstaller] Could not install or rename the agent bot',
                context: [
                    'file'    => __FILE__,
                    'line'    => __LINE__,
                    'agentId' => $agentId,
                    'error'   => $e->getMessage(),
                ]
            );
            return false;
        }//end try

    }//end installForAgent()

    /**
     * Remove one agent's bot, stopping all inbound dispatch for it.
     *
     * ⚠️ This DEGRADES that agent's history. The `url_hash` lookup behind the
     * displayed name is scoped to the conversation, so once the record is gone
     * its past messages render as `<actorId>-bot` rather than the agent's name.
     * That is the accepted, documented price of removing the bot when an agent
     * is deleted; the alternative was orphan bot rows living forever.
     *
     * @param string $agentId The agent's uuid.
     *
     * @return bool True when the bot was removed.
     *
     * @psalm-suppress UndefinedClass See install(): spreed is optional and absent from analysis.
     *
     * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
     *
     * @SuppressWarnings(PHPMD.StaticAccess) See installForAgent().
     */
    public function uninstallForAgent(string $agentId): bool
    {
        if ($agentId === '' || $this->bridge->isAvailable() === false || class_exists(self::UNINSTALL_EVENT) === false) {
            return false;
        }

        try {
            $eventClass = self::UNINSTALL_EVENT;
            $this->dispatcher->dispatchTyped(
                // (secret, url) — see uninstall() for why this order is not the
                // one it looks like it should be.
                new $eventClass($this->secretForAgent(agentId: $agentId), TalkBridge::botUrlFor(agentId: $agentId))
            );

            return true;
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[TalkBotInstaller] Could not remove the agent bot',
                context: [
                    'file'    => __FILE__,
                    'line'    => __LINE__,
                    'agentId' => $agentId,
                    'error'   => $e->getMessage(),
                ]
            );
            return false;
        }//end try

    }//end uninstallForAgent()

    /**
     * Enable an agent's bot IN one room.
     *
     * 🔴 spreed's opt-in is two-sided and installing is only half of it. A bot
     * that exists on the instance but is not enabled in a room is never invoked
     * for that room's messages — the agent would sit in its own session room
     * and answer nothing, with no error anywhere to say why.
     *
     * There is no event for this half: `BotEnabledEvent` only NOTIFIES an
     * already-enabled bot (spreed's own controller inserts the row first, then
     * dispatches). So the row is written through `BotConversationMapper`, which
     * is what that controller does, and the event is dispatched afterwards in
     * the same order so anything listening sees a consistent state.
     *
     * Idempotent: a bot already enabled in the room is left alone, matching the
     * controller's own behaviour.
     *
     * @param string $agentId   The agent whose bot to enable.
     * @param string $roomToken The room to enable it in.
     *
     * @return bool True when the bot is enabled in the room.
     *
     * @psalm-suppress UndefinedClass See install(): spreed is optional and absent from analysis.
     *
     * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-creating-a-chat-session-creates-and-owns-its-talk-room
     *
     * @SuppressWarnings(PHPMD.StaticAccess) See installForAgent().
     */
    public function enableInRoom(string $agentId, string $roomToken): bool
    {
        if ($agentId === '' || $roomToken === '' || $this->bridge->isAvailable() === false) {
            return false;
        }

        try {
            $botMapper          = $this->container->get(self::BOT_SERVER_MAPPER);
            $conversationMapper = $this->container->get(self::BOT_CONVERSATION_MAPPER);

            $bot   = $botMapper->findByUrl(TalkBridge::botUrlFor(agentId: $agentId));
            $botId = (int) $bot->getId();

            foreach ($conversationMapper->findForToken($roomToken) as $enabled) {
                if ((int) $enabled->getBotId() === $botId) {
                    return true;
                }
            }

            $conversationClass = self::BOT_CONVERSATION;
            $row = new $conversationClass();
            $row->setBotId($botId);
            $row->setToken($roomToken);
            $row->setState(self::BOT_STATE_ENABLED);
            $conversationMapper->insert($row);

            return true;
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[TalkBotInstaller] Could not enable the agent bot in the room',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'agentId'   => $agentId,
                    'roomToken' => $roomToken,
                    'error'     => $e->getMessage(),
                ]
            );
            return false;
        }//end try

    }//end enableInRoom()

    /**
     * The per-agent bot secret: unique to the agent, stable for its lifetime.
     *
     * 🔴 Both properties are forced by the database, and getting either wrong
     * fails in a way a single-agent test cannot see:
     *
     * - UNIQUE, because `oc_talk_bots_server` carries a unique index on
     *   `secret` ALONE (`talk_bots_server_secret`). Reusing one app-wide secret
     *   across agents installs the FIRST agent happily and then dies on the
     *   second with a duplicate-key violation. Verified live — this is not a
     *   theoretical constraint.
     * - STABLE, because spreed's install is an upsert keyed on (url, secret).
     *   A rotating secret makes every rename look like a new bot on a URL that
     *   already exists, and spreed rejects it.
     *
     * Derived rather than stored, so there is no per-agent row to keep in sync
     * with the agent's own lifecycle: HMAC of the agent id under the app secret
     * satisfies both properties by construction. The app secret never leaves
     * the server and the derivation is one-way, so the per-agent value leaks
     * nothing about it.
     *
     * @param string $agentId The agent's uuid.
     *
     * @return string The agent's bot secret.
     *
     * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-each-talk-enabled-agent-has-its-own-talk-bot-identity
     */
    private function secretForAgent(string $agentId): string
    {
        return hash_hmac('sha256', $agentId, $this->secret());

    }//end secretForAgent()

    /**
     * The stable app-wide bot secret, generated once on first use.
     *
     * Used directly by the legacy shared bot, and as the derivation key for
     * every per-agent secret.
     *
     * @return string The bot secret.
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
