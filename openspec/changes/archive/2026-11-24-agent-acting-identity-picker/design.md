# Design: acting identity picker

## Context

Read at hermiq `development`: `Agent.actingUser` is a plain string property; the edit modal saves
the whole agent through the OpenRegister object store (`saveObject`, PUT semantics). OpenRegister
dispatches `ObjectCreatingEvent` and `ObjectUpdatingEvent` before a save; both implement
`StoppableEventInterface` and carry errors, so a listener can refuse a write whatever its source.

## Decisions

- **D1. Two choices, one field.** "Namens de persoon die hem gebruikt" stores `actingUser` empty;
  "Als eigen serviceaccount" stores the chosen Nextcloud user id. The edit page derives the selected
  option from the stored value.
- **D2. The guard sits on the write, not on the page.** A new listener
  `lib/Listener/AgentActingUserGuardListener.php` handles `ObjectCreatingEvent` and
  `ObjectUpdatingEvent` for the hermiq `agent` schema. When the new `actingUser` is non-empty and
  differs from the old one, the writer must pass `ActionAuthService::can($user, 'agent.set-acting-user')`
  and the named user must exist and be enabled; otherwise the listener stops the event with an error
  naming the reason. Clearing `actingUser` narrows rights and stays allowed for anyone who may
  modify the agent. Background writes with no session user (repair steps, `reassignAgent()` after
  its own `tenantops.reassign-agent` check) pass through `TenantOpsService`, which keeps its check.
- **D3. The page reflects the right.** The edit page learns from the server whether the viewer
  passes `agent.set-acting-user` (initial state filled from `ActionAuthService::can()`) and disables the service account option with a sentence saying
  an administrator sets it when the viewer lacks the action. The server guard decides either way.

## Risks

- An existing agent with an `actingUser` set before this change keeps it; the guard only fires on a
  change of the value.
