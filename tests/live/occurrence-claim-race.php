<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Two-process race on the occurrence claim, run against a live instance.
 *
 * Usage (from anywhere, as the web server user):
 *   php tests/live/occurrence-claim-race.php /path/to/nextcloud [rounds]
 *
 * The parent starts two worker processes. Both wait for the same start time,
 * then claim the same schedule's occurrence at the same moment, once per round,
 * each round on a new schedule id. The parent reads both answers and fails
 * unless exactly one worker held each claim. Exit code 0 means every round had
 * one winner.
 *
 * @spec openspec/changes/operations-parallel-run-workers/specs/agent-schedule/spec.md#requirement-each-occurrence-is-claimed-once-whatever-the-number-of-workers-req-prun-001
 */

declare(strict_types=1);

$ncRoot = ($argv[1] ?? '');
if ($ncRoot === '' || is_file($ncRoot . '/occ') === false) {
	fwrite(STDERR, "usage: php occurrence-claim-race.php /path/to/nextcloud [rounds]\n");
	exit(2);
}

if (($argv[2] ?? '') === '--worker') {
	// Worker: claim each round's schedule at the round's start time.
	$runId = (string)$argv[3];
	$start = (float)$argv[4];
	$rounds = (int)$argv[5];
	$name = (string)$argv[6];
	require_once $ncRoot . '/lib/base.php';
	$claim = \OCP\Server::get(\OCA\Hermiq\Service\Run\OccurrenceClaim::class);
	if ($claim->isAvailable() === false) {
		fwrite(STDERR, "OpenRegister offers no advisory lock\n");
		exit(3);
	}

	for ($i = 0; $i < $rounds; $i++) {
		$at = $start + ($i * 0.25);
		$wait = $at - microtime(true);
		if ($wait > 0) {
			usleep((int)($wait * 1000000));
		}

		$held = $claim->claim(scheduleUuid: "race-$runId-$i", occurrenceKey: "$name-$i", ttlSeconds: 60);
		echo $i . ' ' . ($held === true ? '1' : '0') . "\n";
	}

	exit(0);
}

$rounds = max(1, (int)($argv[2] ?? 40));
$runId = bin2hex(random_bytes(4));
// Both workers boot Nextcloud first; give them time before the first round.
$start = microtime(true) + 8.0;
$procs = [];
foreach (['a', 'b'] as $name) {
	$cmd = [PHP_BINARY, __FILE__, $ncRoot, '--worker', $runId, (string)$start, (string)$rounds, $name];
	$procs[$name] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$name]);
}

$held = [];
$failed = false;
foreach ($procs as $name => $proc) {
	$out = stream_get_contents($pipes[$name][1]);
	$err = stream_get_contents($pipes[$name][2]);
	$code = proc_close($proc);
	if ($code !== 0) {
		fwrite(STDERR, "worker $name exited $code: $err\n");
		$failed = true;
	}

	foreach (preg_split('/\R/', trim((string)$out)) as $line) {
		if (preg_match('/^(\d+) ([01])$/', $line, $m) === 1) {
			$held[(int)$m[1]][$name] = (int)$m[2];
		}
	}
}

$bad = 0;
$wins = ['a' => 0, 'b' => 0];
for ($i = 0; $i < $rounds; $i++) {
	$winners = array_sum($held[$i] ?? []);
	foreach (($held[$i] ?? []) as $name => $won) {
		$wins[$name] += $won;
	}

	if ($winners !== 1 || count($held[$i] ?? []) !== 2) {
		$bad++;
		fwrite(STDERR, "round $i: " . json_encode($held[$i] ?? []) . "\n");
	}
}

echo "rounds=$rounds one-winner=" . ($rounds - $bad) . " wrong=$bad won-by-a={$wins['a']} won-by-b={$wins['b']}\n";
exit(($failed === true || $bad > 0) ? 1 : 0);
