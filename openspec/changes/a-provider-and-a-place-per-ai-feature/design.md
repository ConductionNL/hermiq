# Design: a provider and a place per AI feature

## D1. Two registers that do not meet

Measured on HEAD:

| register | grain | what it decides |
|---|---|---|
| `ModelPolicy` (`tenant-model-policy`) | per organisation | which `{provider, models[]}` pairs are permitted at all |
| `AiFeature` (`ai-feature-governance`) | per feature | risk category, and whether the feature is on |

The question the sweep asks falls exactly between them: which provider does **this
feature** use. Today the answer is "whichever the policy defaults to", for every
feature at once.

The join is one optional pair of fields on `AiFeature`. It is small because both
halves already exist, and it is worth writing down because the missing join is
invisible: an administrator looking at either screen sees a complete-looking picture.

## D2. The feature narrows, and can never widen

Two orders were possible.

1. The feature binding overrides the policy. Then a feature can reach a provider the
   organisation has forbidden, and the policy stops meaning anything.
2. The feature binding must be a subset of the policy.

Option 2, and it is enforced at two moments: when the binding is written, and again on
every turn. The write-time check gives an administrator an immediate answer. The
run-time check is the one that matters, because a policy narrowed **after** a binding
was written must take effect without anybody revisiting the feature.

`tenant-model-policy` already enforces the pair on every turn regardless of trigger.
This change adds the feature's own binding into that resolution rather than beside it,
so there is still one enforcement point.

## D3. Residency is administered, never inferred

The tempting implementation is to read the provider's endpoint hostname and guess:
`.eu` means Europe, a local address means on premise.

It is wrong in both directions. A `.eu` domain resolving to a US region is ordinary,
and an on-premise reverse proxy in front of a hosted model looks local from inside.
A residency that is guessed is a residency an FG cannot rely on, and the whole value
of this field is that somebody can be held to it.

So the label is typed by whoever configures the provider, alongside a free-text
location for the detail an enum cannot carry ("Frankfurt, AWS eu-central-1"). It is a
statement of fact by an administrator, and it is auditable as one.

## D4. The refusal happens before the call

A run that discovers a residency mismatch after sending the text has already sent the
text. Logging it afterwards records a breach rather than preventing one.

So the check sits with the existing model-policy check, before the provider call.
Ordering matters: resolve feature binding, then narrow by policy, then check
residency, then call. Each step can refuse, and each refusal names which step refused.

## D5. What the run record has to carry

`run-audit-log` writes every run to openregister's tamper-evident chain. The question
"which model saw this case, and where" needs three things that are not on it today:
the feature, the provider and model actually used, and the residency in force **at
the time of the run**.

The last one is the subtle one. A provider relabelled next year must not rewrite what
last year's runs say, so the label is copied onto the run rather than referenced. This
is the same reasoning humaniq's dated working pattern uses: the record of what was
true then survives what is true now.

## D6. The split deployment is already possible, and that is the finding

Decos Join sells "assistant in our cloud, case system on yours" as a deployment
option. In hermiq it is a configuration: an on-premise Nextcloud with a feature bound
to a hosted provider.

So this change does not build it. It makes it **expressible and visible**, which is
what the matrix could not do. The residency label is the difference between an
architecture diagram and an answerable question, and a tender asks the question.
