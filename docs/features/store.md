# Store

## What it is for

Install an agent or a skill somebody else already built, instead of writing it again.

## How to reach it

**Store** in the navigation.

## What you can install

- **Agent templates**, a whole agent with its prompt, tools and settings
- **Skills**, individual abilities, from your organisation or a GitHub-backed catalogue

## Quarantine

Anything arriving from another organisation or an external hub is quarantined on
install. It is present, and it is not yet trusted. You review it and release it.

This is deliberate. An installable skill is a written instruction an agent will follow,
so treating an import as automatically trustworthy would make the store the easiest way
into your instance.

## Publishing

You can publish your own skills back out, individually or as a bundle. A bundle
publishes many skills to one repository and installs as many separately quarantined
skills, so a single bad entry does not carry the rest in with it.

Bundle entries are validated as paths before use.

## API

- `GET /apps/hermiq/api/skills/github/search`
- `POST /apps/hermiq/api/skills/github/install`
- `POST /apps/hermiq/api/skills/bundle/publish`

## Where to go next

Review what you installed in [Skills](skills.md) before you release it from quarantine.
