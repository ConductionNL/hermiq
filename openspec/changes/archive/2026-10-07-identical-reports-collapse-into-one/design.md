# Design: identical reports collapse into one

## D1. "Identical" is the wrong word, and the spec says the right one

The candidate's row reads "identical reports collapsed into one". Taken literally it
is worthless: two people describing one power cut write different sentences, and a
byte comparison finds nothing.

What a gemeente means is "about the same event". That is a judgement, it is
occasionally wrong, and the design has to survive being wrong rather than aim at never
being.

Everything below follows from that: bands instead of a boolean, reversal without loss,
and reasons a human can read.

## D2. Three bands, because the middle one is where the value is

A boolean classifier forces every borderline case into one of two mistakes.

- Called the same: a genuinely separate report is buried inside a group of two
  hundred, and the person who filed it never hears back about their actual problem.
- Called different: two hundred and one items, which is the situation today.

So three bands:

| band | what happens | who sees it |
|---|---|---|
| above the upper threshold | joins the group | counted |
| between the thresholds | joins the group, flagged uncertain | listed beside the group for a human |
| below the lower threshold | stands alone | normal intake |

The middle band is the candidate's own phrase, "with the near-duplicates beside it".
It is not hedging; it is where a human's attention should go, and it is the only band
that needs a screen.

Both thresholds are administered, because the cost of each mistake is a
municipality's to weigh.

## D3. Grouping is additive, so undoing it costs nothing

The destructive implementation merges the reports, keeps one and discards the rest.
Undoing that means recovering discarded data, which is the kind of recovery that works
in a test and not in March.

So a group is a set of references with a score. Every report still exists, still has
its own reporter, and still has its own acknowledgement. Pulling one out is deleting a
membership, and the count moves.

This also makes the failure cheap. A wrong grouping costs a click, so the thresholds
can be set where they are useful rather than where they are safe.

## D4. The acknowledgement is per person, always

The sweep's own front page names row 17 of the loudest twenty-five as the first thing
to fix: an automatic acknowledgement on creation is Awb 4:3a, a statutory duty, and
dossiq returns zero hits for one.

Grouping is exactly the feature that could quietly break that duty before it is built.
A handler seeing one item where two hundred people wrote will find it natural that one
confirmation went out.

So it is written here, in the spec of the thing that would cause it: collapsing is a
view for the handler and never reduces the number of confirmations owed. Two hundred
people who wrote to the gemeente are owed two hundred confirmations.

## D5. The deterministic key runs first, again

For a melding a gemeente often already knows the answer: same address, same category,
within an hour. That is a key, not a judgement. It is explainable, stable and free.

The rule is the one `a-conversational-intake-that-files-for-the-citizen` states: where
the owning app supplies a deterministic key, use it and record that you did. The model
is for what the key does not catch, which for a street-wide storing is most of it,
because people report from the address they are standing at rather than the one that
broke.

Recording which decided a grouping is what makes an argument about it possible.

## D6. The window is bounded, and the bound is the point

Comparing a new report against every report ever filed is expensive and wrong. A
melding about a streetlight in March and one in October are not one event, however
similar the text.

So the comparison runs over an open window whose length is administered per report
type. A storing lasts hours; a recurring nuisance complaint might reasonably group
over weeks. The window is part of the reason a group carries, so a reader can see what
was and was not considered.

## D7. Why these reasons have to be readable

A citizen told "your melding was merged into an existing one" will sometimes disagree,
and they will be right often enough that the answer cannot be "the model said so".

A group therefore carries the terms that matched, the window in force, the score and
whether a deterministic key or the model decided. That is enough for a handler to
defend the grouping or to undo it, which are the only two useful outcomes of the
conversation.
