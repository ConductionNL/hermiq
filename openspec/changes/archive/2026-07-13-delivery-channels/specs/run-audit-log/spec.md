## ADDED Requirements

### Requirement: The delivery trace step reflects the channel actually used [MVP]

The system MUST record the run trace's `delivery` step with a `name` that
reflects the channel `DeliveryResult` reports as actually used
(`"Talk delivery"`, `"Notification delivery"`, `"Email delivery"`,
`"Webhook delivery"`, or `"No delivery"` for `deliver=none`/silent output),
rather than a single hard-coded label. The step's `type` remains `delivery`
in every case, so nothing that filters on step `type` is affected.

#### Scenario: A webhook delivery's trace step is labelled accordingly

- GIVEN a schedule with `deliver=webhook` whose run completes and is
  delivered successfully
- WHEN the owner retrieves that run's trace
- THEN the `delivery` step's `name` MUST read `"Webhook delivery"`
- AND its `type` MUST still read `delivery`

#### Scenario: An email delivery's trace step is labelled accordingly

- GIVEN a schedule with `deliver=email` whose run completes and is
  delivered successfully
- WHEN the owner retrieves that run's trace
- THEN the `delivery` step's `name` MUST read `"Email delivery"`

#### Scenario: Existing Talk delivery labelling is unchanged

- GIVEN a schedule with `deliver=talk` whose run completes and is
  delivered successfully
- WHEN the owner retrieves that run's trace
- THEN the `delivery` step's `name` MUST still read `"Talk delivery"`,
  unchanged from existing behavior
