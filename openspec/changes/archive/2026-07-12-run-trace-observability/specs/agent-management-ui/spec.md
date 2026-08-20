# agent-management-ui (delta)

Extends the existing Run history section of the agent detail view with a per-run step timeline and
a "Download trace (JSON)" action, built on `run-audit-log`'s new step-enriched audit entry and trace
read endpoint — no new view, no new store pattern.

## MODIFIED Requirements

### Requirement: Run history view [MVP]
Each agent's detail view MUST show its run history (see `run-audit-log`) with status, timing, and
output/audit links, and MUST let the user expand any run to see its step timeline and download that
run's trace as a redacted JSON file.

#### Scenario: View an agent's run history
- GIVEN an agent detail view for an agent whose schedule has run
- WHEN the user views the Run history section
- THEN the system MUST list past runs with their status, timing, and output/audit links
- AND an agent with no runs MUST show an empty-state hint instead of an error

#### Scenario: View a run's step timeline
- GIVEN an agent detail view showing a completed run in the Run history section
- WHEN the user expands that run
- THEN the system MUST render its ordered step timeline (each step's type, name, duration, and
  outcome) fetched from the run-trace endpoint
- AND a run whose execution path did not record tool-call detail MUST show that plainly rather than
  appearing to have no tool activity

#### Scenario: Download a run's trace as JSON
- GIVEN an agent detail view showing a completed run in the Run history section
- WHEN the user chooses "Download trace (JSON)" for that run
- THEN the system MUST retrieve the run's full trace via the owner-scoped endpoint and save it as a
  local JSON file
- AND the downloaded content MUST be the same already-redacted data shown in the expanded timeline
