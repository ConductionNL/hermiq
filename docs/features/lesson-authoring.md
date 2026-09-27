# Lesson authoring

## What it is for

Teachers in learniq can ask for help while they write a lesson. Hermiq does the AI work, so your school governs it in one place, next to every other AI feature.

## What a teacher can ask for

- An outline for a lesson, built from one or more learning goals.
- Questions that check what pupils understood.
- The same text at a lower reading level: CEFR A1 to B2, or referentieniveau 1F to 3F.
- Which of the given learning goals the lesson text covers.

Every answer is a draft. The teacher accepts, edits or discards it. Nothing is saved or published without them.

## What the model sees

Only the lesson text and the goal titles. Learniq sends no pupil data, and Hermiq drops any other field before it builds a prompt. The model is told to leave out names and other personal data.

Goal suggestions come back as positions in the teacher's own list. The model cannot invent a goal, and the goal ids never leave learniq.

## Turning it on

The feature arrives switched off. It appears as **Lesson authoring assistance** in **Settings > Algorithm register**, at limited risk. Your DPO acknowledges it first, and an administrator then enables it. Until then every request answers "not available", and learniq keeps the buttons out of sight.

Hermiq uses the chat provider set in its settings. Pick the `nextcloud` provider to run the work as a Nextcloud Assistant task on your own instance.

## What is logged

One line per request in the Nextcloud log: the action, who asked, the outcome, the provider and the size of the input. The text itself is never logged.

## Endpoints

| Method | Path | What it returns |
|--------|------|-----------------|
| `POST` | `/api/lesson-authoring/outline` | An outline as draft text |
| `POST` | `/api/lesson-authoring/questions` | A list of questions |
| `POST` | `/api/lesson-authoring/simplify` | The rewritten text and the level used |
| `POST` | `/api/lesson-authoring/goal-suggestions` | The covered goals, by position |

Each call needs a logged-in user and is limited to 30 per user per minute. The full request and response contract is in `openspec/changes/lesson-authoring-ai-delegate/contract.md`.

To switch it on, ask your DPO to acknowledge **Lesson authoring assistance** in the Algorithm register.
