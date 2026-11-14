<?php

/**
 * Contract of TalkBotInstaller
 *
 * The public surface of TalkBotInstaller that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

/**
 * Contract of TalkBotInstaller.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TalkBotInstallerInterface {

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
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-hermiq-registers-as-an-in-process-talk-bot
	 */
	public function install(): bool;

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
	 * @param string $agentId The agent's uuid.
	 * @param string $agentName The agent's display name, shown in Talk.
	 *
	 * @return bool True when the bot was installed or renamed.
	 *
	 * @psalm-suppress UndefinedClass See install(): spreed is optional and absent from analysis.
	 *
	 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-each-talk-enabled-agent-has-its-own-talk-bot-identity
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) TalkBridge::botUrlFor() is a pure
	 * function of the agent id — see TalkBridge for why the URL helpers are
	 * static rather than injectable.
	 */
	public function installForAgent(string $agentId, string $agentName): bool;

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
	 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) See installForAgent().
	 */
	public function uninstallForAgent(string $agentId): bool;
}//end interface
