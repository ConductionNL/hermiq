# Agents

## What it is for

An agent is the thing that does the work. You give it a prompt, point it at a model,
decide what it may touch, and it runs on a schedule or when a flow asks it to.

## How to reach it

**Agents** in the navigation. Click one to open its detail page.

## Defining an agent

The form covers what the engine actually reads:

| Field | What it decides |
|-------|-----------------|
| Name, description, icon | How you recognise it in a list |
| Provider and model | Which LLM runs the turn |
| System prompt | The instructions it works from |
| Temperature, max tokens | How it generates |
| Enabled tools | Which tools it may call. Empty means every discovered tool |
| Delegation allowlist | Which agents it may hand a sub-task to. Empty means none |
| RAG settings | Whether it grounds answers in your files and objects |
| Speech | Whether it can be dictated to, and whether it speaks back |

Four providers ship: Ollama, OpenAI, Fireworks and Anthropic. The model field is a
dropdown you can also type into, because a provider ships new models faster than this
app is released.

## What the detail page shows

Four numbers for this agent (runs, success rate, latency, tokens), then its
configuration, its skills, its tool grants and their activity, its run history, its
memory, and its run operations.

Run history and the tool catalogue scroll inside their own regions. A hundred tools
behind a page scrollbar is not a way to find one.

## Who may change it

The owner. An agent's read path stays open to anyone who may see it, so a colleague
can look at what it does without being able to change what it does.

An agent that names an `actingUser` runs as that person. If that account no longer
resolves, the run is refused rather than quietly falling back to the schedule owner.
Disabling an account is how a departure is processed, so substituting somebody else is
least acceptable exactly then.

## API

- `GET|POST /apps/hermiq/api/agents`
- `GET /apps/hermiq/api/agents/stats`
- `GET /apps/hermiq/api/agents/tools`
- `GET /apps/hermiq/api/agents/{id}/versions` and `/versions/diff`
- `GET /apps/hermiq/api/agents/{agentId}/budget-estimate`

## Where to go next

Give it something to do: attach a schedule from the detail page, then watch what
happens in [Runs](runs.md).
