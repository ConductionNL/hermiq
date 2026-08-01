## ADDED Requirements

### Requirement: The runner ExApp can run a command over a checked-out ref

The runner ExApp SHALL expose `POST /stage`, which clones `{repo, ref}` into a
throwaway scratch directory, runs a command over the clone, and returns
`{exitCode, output, ref}`. The scratch directory SHALL be removed on every exit
path, success or failure, because it is where the askpass helper lives. The
clone SHALL NOT be shallow: the gates diff against a base, and with no history
`--scope-to-diff` sees an empty change set and reports zero failures —
indistinguishable from a clean run.

#### Scenario: A ref is checked out and a command runs over it

- **GIVEN** a repository and a ref
- **WHEN** a stage is dispatched with an allowlisted command
- **THEN** the command runs inside the clone and its output is returned

#### Scenario: A branch that exists only on the remote still checks out

- **GIVEN** a repository whose default branch is `main`
- **WHEN** a stage asks for `development`
- **THEN** the checkout resolves it as `origin/development` and succeeds

#### Scenario: The scratch tree does not survive a failure

- **GIVEN** a stage whose clone fails
- **WHEN** the stage returns
- **THEN** no scratch directory remains

### Requirement: The command is an allowlist, checked before the clone

The runner SHALL execute only commands on a configured allowlist, matched
against the first argv token, and SHALL refuse a command that is not on it
BEFORE cloning anything. The caller is a flow — authored data — so a free
command string would make authoring a flow equivalent to remote code execution
inside the ExApp. A refusal after the fetch is a refusal that has already paid
for the attack.

#### Scenario: An unlisted command is refused

- **GIVEN** a stage naming a command that is not on the allowlist
- **WHEN** it is dispatched
- **THEN** it is refused, and nothing is cloned

#### Scenario: Arguments are not part of the match

- **GIVEN** an allowlisted command with flags appended
- **WHEN** it is dispatched
- **THEN** it runs, and the flags are passed through unexamined

### Requirement: A forge token never reaches argv or a persistent file

When a stage needs a credential, the runner SHALL take it in the request body,
place it in the child ENVIRONMENT, and give git access to it through
`GIT_ASKPASS`. It SHALL NOT appear on any command line — the process table is
world-readable — and SHALL NOT be written to a file that outlives the run.

#### Scenario: git receives the token through the askpass helper

- **GIVEN** a stage carrying a forge token
- **WHEN** the command inspects its own environment
- **THEN** `GIT_ASKPASS` points at the helper and the token is in the environment

### Requirement: A flow can dispatch a workload as a step

hermiq SHALL contribute `hermiq.workload-step` as an OpenRegister `IFlowNode`
through `RegisterFlowNodesEvent`. The step SHALL run once per item, render
`{{dotted.path}}` placeholders into the repo, the ref AND each argv element, and
place the result under the configured output key (default `stage`). A step
naming no repo, no ref or no command SHALL be refused both at save time and at
execution time, because a flow that is imported or seeded reaches execution
unvalidated.

#### Scenario: The workload node is in the shared palette

- **GIVEN** hermiq and OpenRegister both installed
- **WHEN** OpenRegister builds its flow palette
- **THEN** `hermiq.workload-step` is in it

#### Scenario: A placeholder in an argument is rendered

- **GIVEN** a step whose command carries `{{base}}`
- **WHEN** it runs against an item holding a base
- **THEN** the command receives the base, not the placeholder

#### Scenario: An unconfigured step does not pass items through

- **GIVEN** a seeded flow with a workload step naming no command
- **WHEN** the step executes
- **THEN** the step fails rather than returning the items unchanged

### Requirement: A command that ran and failed is data, not a step failure

The step SHALL return a non-zero exit code as part of the item, and SHALL throw
only when the workload could not be run at all — an unreachable ExApp, a refused
command, a failed clone, or an answer that is not a stage result. A malformed
answer SHALL NOT be mapped to `exitCode: 0`.

The two are different questions and a flow needs both: hydra's gate runner uses
its exit code as a failure COUNT, so a router is meant to read it; while a
downstream router cannot tell "the gates found nothing" from "the gates never
ran", and both would otherwise look like a clean tick.

#### Scenario: Failing gates are routable

- **GIVEN** a workload whose command exits 18
- **WHEN** the step completes
- **THEN** the item carries exit code 18 and the run continues

#### Scenario: An unreachable ExApp fails the step

- **GIVEN** a workload step whose ExApp is not running
- **WHEN** the step executes
- **THEN** the step fails, so the run's `onError` policy decides

#### Scenario: An answer that is not a stage result is never a pass

- **GIVEN** a runner answering with an error object or non-JSON
- **WHEN** the dispatcher maps it
- **THEN** the step fails rather than reporting exit code 0

### Requirement: A workload step is attributable or it does not run

The step SHALL determine an owner from its own `owner` config or the run's
triggering user, and SHALL REFUSE to dispatch when neither yields one. The
result SHALL carry `owner`, and — only when a credential was used —
`credential_owner` and `credential_name`; with no credential both SHALL be null
rather than defaulted to the owner.

Attribution SHALL travel on the result itself rather than beside it, so that a
step fanned out over several repositories attributes each one.

Two reasons, and the second is the security one. hydra's durable record answers
"who ran this, on whose credential" out of `cycles[].owner` and
`stages[].credential_owner`; a stage that cannot say costs the record that
answer permanently, because the run is durable and the missing attribution is
not recoverable afterwards. And an unattributed stage is the shape a credential
misuse takes — a subscription serves its owner and never a pool, so "no owner"
is precisely the state in which none may be selected.

#### Scenario: An unattributable stage is refused before dispatch

- **GIVEN** a step naming no owner, and a run recording none
- **WHEN** the step executes
- **THEN** it is refused, and nothing is dispatched

#### Scenario: The run's owner attributes the stage

- **GIVEN** a step naming no owner and a run triggered by a user
- **WHEN** the step completes
- **THEN** the result carries that user as its owner

#### Scenario: A public clone records no credential attribution

- **GIVEN** a step configured with no credential
- **WHEN** the step completes
- **THEN** `credential_owner` and `credential_name` are null

#### Scenario: A fan-out attributes every item

- **GIVEN** a step running over several items
- **WHEN** it completes
- **THEN** every item's result carries the attribution
