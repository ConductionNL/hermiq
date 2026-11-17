# Algorithm register

## What it is for

Dutch public bodies must be able to say which algorithms they use, what for, and on what
legal basis. This surface holds that record and publishes it.

## How to reach it

**Settings > Algorithm register**.

## What it holds

Every AI feature this instance offers, as a governed object carrying:

- Its name, slug and plain-language description
- Its EU AI Act risk category: minimal, limited, high or unacceptable
- Its lifecycle state, which starts disabled
- The Algoritmekader fields: purpose, statutory basis, impact assessments (IAMA, DPIA,
  FRAIA), data sources, human intervention, who is responsible, publication category
- Its publication state and reference

## The DPO gate

A high-risk feature cannot be enabled until your Data Protection Officer has
acknowledged it. The gate is on the lifecycle transition itself, not in a controller, so
every route to enabled meets it. It fails closed: no acknowledgement recorded means no
transition, and a feature with no slug is refused rather than assumed safe.

Disabling is unrestricted. Turning something off should never wait for a signature.

Acknowledging, enabling and disabling are restricted to administrators or the DPO role.

## Publishing

A feature that is high risk, enabled, acknowledged and fully described can be published
to the national Algoritmeregister. If any of those is missing, the action tells you which
one rather than refusing without saying why.

Hermiq does not talk to the national portal itself. Publication is delegated to the
fleet's publication path through OpenCatalogi, resolved at runtime. Where OpenCatalogi is
absent the action is unavailable and the feature is still fully governable here.

## API

- `GET /apps/hermiq/api/ai-features`
- `POST /apps/hermiq/api/ai-features/{slug}/acknowledge`
- `POST /apps/hermiq/api/ai-features/{id}/enable` and `/disable`
- `POST /apps/hermiq/api/ai-features/{id}/publish` and `/withdraw`

## Where to go next

Track the controls behind these duties in [Compliance](compliance.md).
