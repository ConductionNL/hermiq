# Running agents in parallel

With many scheduled agents, an organisation no longer waits for them one after
the other. This page says how to run several background workers, what the
limits in the admin settings do, and what you see when a worker is lost.

## How a scheduled run gets to a worker

Every five minutes Hermiq's schedule tick looks for schedules that are due. For
each one it checks the kill switch, the budget and approval. It then moves the
schedule on to its next run time and marks this run `queued`. It does not run
the agent itself: it adds one background job for the run to Nextcloud's job
list.

A background worker picks the job up. Before it starts, it claims the run, so
that only one worker can run it. A worker that loses the claim stops without
doing anything. The winner checks the kill switch, the budget and approval
again, because time may have passed since the tick, then marks the run
`running` and runs the agent.

Each run still runs at most once. If two jobs for the same run reach two
workers at the same moment, only one of them runs it.

## Run more than one worker

With only Nextcloud's normal cron job, the jobs run one after another, as
before. To run agents at the same time, start extra workers with:

```bash
occ background-job:worker
```

Two to four extra workers is a good start. Run each one as its own service, for
example a systemd unit:

```ini
[Unit]
Description=Nextcloud background worker %i
After=network.target

[Service]
User=www-data
ExecStart=/usr/bin/php /var/www/html/occ background-job:worker
Restart=always

[Install]
WantedBy=multi-user.target
```

Each worker is a PHP process, and each agent run calls your language model, so
do not start more workers than the machine and the model provider can carry.

## Limit how many runs go at once

In **Administration settings → Hermiq → Scheduled runs in parallel**, an
administrator sets two limits:

- **Scheduled runs at once on this instance** (default 4).
- **Scheduled runs at once per organisation** (default 2). This keeps one
  organisation's nightly agents from holding every worker while another
  organisation's agents wait.

The per-organisation limit cannot be higher than the instance limit. A run that
would go over a limit goes back into the queue and waits until another run
finishes. It is never dropped.

The limits only count scheduled runs. Runs started by a flow, a webhook or a
Talk message already run as their own background jobs, and are not counted.

Set the instance limit no higher than the number of workers you run. A higher
limit does nothing.

## When a worker is lost

A worker can stop in the middle of a run, for example when its process is
killed. Nextcloud does not run that job again, so the run would stay `queued`
or `running` forever. Each tick sweeps for these. A run that is still `queued`
or `running` 30 minutes after it was queued or started, and that no worker
holds, is marked failed with "The worker stopped before the run finished."

The schedule's retry policy then takes over. If the schedule retries, the retry
is a new run with its own attempt number, never the lost run started again. A
run that waits in the queue for more than 30 minutes because every worker is
busy is failed in the same way. If that happens, start more workers or raise
the limits.

## What to watch

On the **Runs** page, a schedule that stays `queued` for a long time is waiting
for a worker. Many of those mean too few workers, or limits that are too low.
A run marked "The worker stopped before the run finished." means a worker
stopped mid-run. Check the logs of the worker processes.
