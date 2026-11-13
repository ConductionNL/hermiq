<?php

/**
 * Hermiq ScheduleWhenController
 *
 * `POST /api/schedules/parse-when`: plain words to a schedule rule, with the
 * readback and the next three runs (scheduling-overview-and-start-choice D3).
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-a-schedule-can-be-set-in-plain-words-req-sched-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Kernel\Contracts\ProviderFactoryInterface;
use OCA\Hermiq\Service\ScheduleWhenParser;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads when a schedule should run; stores nothing.
 *
 * The deterministic parser answers first. Text it cannot read goes to the
 * configured model, whose answer is accepted only as a valid rule. Anything
 * else is refused with an example, so a person never saves words that were
 * not understood.
 *
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-a-schedule-can-be-set-in-plain-words-req-sched-003
 */
class ScheduleWhenController extends Controller {

	/**
	 * The longest text read; a description of when something runs is short.
	 *
	 * @var integer
	 */
	public const MAX_TEXT = 200;

	/**
	 * Constructor.
	 *
	 * @param string                   $appName     The app id.
	 * @param IRequest                 $request     The request.
	 * @param ScheduleWhenParser       $parser      The deterministic reader.
	 * @param ProviderFactoryInterface $providers   The configured model, for text the parser cannot read.
	 * @param IUserSession             $userSession The caller, for their timezone.
	 * @param IConfig                  $config      The caller's and the instance's timezone.
	 * @param ITimeFactory             $time        Now.
	 * @param LoggerInterface          $logger      Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ScheduleWhenParser $parser,
		private readonly ProviderFactoryInterface $providers,
		private readonly IUserSession $userSession,
		private readonly IConfig $config,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Read a description of when a schedule runs.
	 *
	 * Any signed-in user may ask: it reads text and returns a rule, and touches
	 * no schedule. Saving the rule goes through the schedule's own access rules.
	 *
	 * @param string $text   What the person typed.
	 * @param string $locale The readback language.
	 *
	 * @return JSONResponse `{kind, cronExpr|intervalMinutes|runAt, readback, nextRuns, source}`, or 422 with an example.
	 *
	 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-a-schedule-can-be-set-in-plain-words-req-sched-003
	 */
	#[NoAdminRequired]
	public function parse(string $text = '', string $locale = 'en'): JSONResponse {
		$uid = (string)$this->userSession->getUser()?->getUID();
		if ($uid === '') {
			return new JSONResponse(data: ['message' => 'Sign in to set a schedule.'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$text = trim($text);
		if ($text === '' || mb_strlen($text) > self::MAX_TEXT) {
			return $this->unreadable(locale: $locale);
		}

		$timezone = $this->timezone(uid: $uid);
		$now      = DateTimeImmutable::createFromInterface($this->time->now())->setTimezone($timezone);
		$rule     = $this->parser->parse(text: $text, locale: $locale, now: $now, timezone: $timezone);
		$source   = 'rules';
		if ($rule === null) {
			$rule   = $this->fromModel(text: $text, locale: $locale, uid: $uid, now: $now);
			$source = 'model';
		}

		if ($rule === null) {
			return $this->unreadable(locale: $locale);
		}

		$rule['readback'] .= ', ' . $timezone->getName();

		return new JSONResponse(data: $rule + ['nextRuns' => $this->nextRuns(rule: $rule, now: $now, timezone: $timezone), 'source' => $source]);
	}//end parse()

	/**
	 * The 422 that says what was not understood and gives an example.
	 *
	 * @param string $locale The readback language.
	 *
	 * @return JSONResponse
	 */
	private function unreadable(string $locale): JSONResponse {
		$message = 'I could not read that. Try for example: every weekday at 8:00.';
		if (str_starts_with(strtolower($locale), 'nl') === true) {
			$message = 'Dat kon ik niet lezen. Probeer bijvoorbeeld: elke werkdag om 8:00.';
		}

		return new JSONResponse(data: ['message' => $message], statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
	}//end unreadable()

	/**
	 * The rule a model reads in the text, accepted only when it is a valid rule.
	 *
	 * @param string            $text   The text.
	 * @param string            $locale The readback language.
	 * @param string            $uid    The caller.
	 * @param DateTimeImmutable $now    Now, in the caller's timezone.
	 *
	 * @return array<string, mixed>|null The rule with its readback, or null.
	 */
	private function fromModel(string $text, string $locale, string $uid, DateTimeImmutable $now): ?array {
		$prompt = 'Turn the description of when a task should run into a schedule rule. Answer with JSON only, one of: '
			. '{"kind":"cron","cronExpr":"<5-field cron>"}, {"kind":"interval","intervalMinutes":<integer >= 1>}, '
			. '{"kind":"once","runAt":"<ISO 8601 date-time>"}. It is now ' . $now->format(DATE_ATOM) . '. '
			. 'If the description is not about when something runs, answer {"kind":"none"}. Description: '
			. json_encode($text, JSON_UNESCAPED_UNICODE);

		try {
			$answer = $this->providers->generateText(prompt: $prompt, userId: $uid);
		} catch (Throwable $e) {
			$this->logger->info(message: '[ScheduleWhenController] No model could read the schedule text', context: ['error' => $e->getMessage()]);
			return null;
		}

		$rule = $this->validRule(answer: $answer, now: $now);
		if ($rule === null) {
			return null;
		}

		$lang = 'en';
		if (str_starts_with(strtolower($locale), 'nl') === true) {
			$lang = 'nl';
		}

		return $rule + ['readback' => $this->parser->readback(rule: $rule, lang: $lang)];
	}//end fromModel()

	/**
	 * A model answer as a rule, or null when it is not a valid one.
	 *
	 * @param string            $answer The model's answer.
	 * @param DateTimeImmutable $now    Now.
	 *
	 * @return array<string, mixed>|null
	 */
	private function validRule(string $answer, DateTimeImmutable $now): ?array {
		$json = trim($answer);
		if (preg_match('/\{.*\}/s', $json, $m) === 1) {
			$json = $m[0];
		}

		$data = json_decode($json, true);
		$kind = (string)($data['kind'] ?? '');
		if ($kind === 'cron' && is_string($data['cronExpr'] ?? null) === true && count(explode(' ', trim($data['cronExpr']))) === 5
			&& CronExpression::isValidExpression(trim($data['cronExpr'])) === true
		) {
			return ['kind' => 'cron', 'cronExpr' => trim($data['cronExpr'])];
		}

		if ($kind === 'interval' && is_int($data['intervalMinutes'] ?? null) === true && $data['intervalMinutes'] >= 1) {
			return ['kind' => 'interval', 'intervalMinutes' => $data['intervalMinutes']];
		}

		if ($kind === 'once' && is_string($data['runAt'] ?? null) === true) {
			try {
				$runAt = new DateTimeImmutable($data['runAt'], $now->getTimezone());
			} catch (Throwable) {
				return null;
			}

			if ($runAt > $now) {
				return ['kind' => 'once', 'runAt' => $runAt->format(DATE_ATOM)];
			}
		}

		return null;
	}//end validRule()

	/**
	 * The next three runs of a rule, as ISO 8601 date-times.
	 *
	 * @param array<string, mixed> $rule     The rule.
	 * @param DateTimeImmutable    $now      Now.
	 * @param DateTimeZone         $timezone The caller's timezone.
	 *
	 * @return array<int, string>
	 */
	private function nextRuns(array $rule, DateTimeImmutable $now, DateTimeZone $timezone): array {
		if ($rule['kind'] === 'once') {
			return [(string)$rule['runAt']];
		}

		if ($rule['kind'] === 'interval') {
			$minutes = (int)$rule['intervalMinutes'];
			return array_map(static fn (int $n): string => $now->modify('+' . ($n * $minutes) . ' minutes')->format(DATE_ATOM), [1, 2, 3]);
		}

		$runs = (new CronExpression((string)$rule['cronExpr']))->getMultipleRunDates(3, $now, false, false, $timezone->getName());

		return array_map(
			static fn (\DateTimeInterface $run): string => DateTimeImmutable::createFromInterface($run)->setTimezone($timezone)->format(DATE_ATOM),
			$runs
		);
	}//end nextRuns()

	/**
	 * The caller's timezone, else the instance's, else UTC.
	 *
	 * @param string $uid The caller.
	 *
	 * @return DateTimeZone
	 */
	private function timezone(string $uid): DateTimeZone {
		$name = (string)$this->config->getUserValue($uid, 'core', 'timezone', '');
		if ($name === '') {
			$name = (string)$this->config->getSystemValueString('default_timezone', 'UTC');
		}

		try {
			return new DateTimeZone($name);
		} catch (Throwable) {
			return new DateTimeZone('UTC');
		}
	}//end timezone()
}//end class
