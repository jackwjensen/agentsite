<!--
Scope reminder, so nothing is declined for a reason unrelated to its quality:
no build steps, no databases, no frontend frameworks, no style-only changes
to working code. See CONTRIBUTING.md — those are scope decisions, not
judgements about the work.

There is deliberately no house style here. No pull request will be declined
over formatting, naming or file length.
-->

## What this changes

## Why

<!-- If it fixes an issue: Fixes #123 -->

## How it was verified

<!--
"It should work" is not verification. Paste the actual output.
-->

```
$ ./dev.sh check

```

<!-- For a module, also paste the round-trip: -->
<!--
$ php scripts/module.php install <name>
$ ./dev.sh check
$ php scripts/module.php uninstall <name>
$ git status --porcelain      # must be empty
-->

## Checklist

- [ ] `./dev.sh check` passes
- [ ] Documentation updated **in this pull request**, not a follow-up
- [ ] One concern per pull request
- [ ] No shared file edited, if this is a module
- [ ] New UI strings added to **every** language file
- [ ] If it stores anything about a visitor, `MODULE.md` says what the user
      must put in their privacy policy

<!--
The docs item is not bureaucracy: code and prose drifting apart is how a
project starts lying to its users, and this one is mostly read by AI
assistants that believe what the prose says.
-->
