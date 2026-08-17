# Code of conduct

Written for this project rather than copied from a template, because this
project has two specific and predictable failure modes.

## The two rules that matter here

**1. Do not relitigate the stack.**

This framework is PHP with no build step, no database and no dependencies.
That is a deliberate trade, explained in the README, and it is not up for
rediscussion in every issue thread. "Why not Next.js / Rust / a static site
generator" is a fine question to ask **once**, and it is answered in
[CONTRIBUTING.md](CONTRIBUTING.md).

What is not welcome is arriving on somebody else's bug report to explain that
the whole approach is wrong. It derails a thread that was about a real
problem, and it makes contributors who chose this deliberately feel they have
to defend the choice before they can report a typo.

If the trade does not suit you, fork it — genuinely, with no hard feelings.
The licence is MIT for exactly that reason.

**2. Review the contribution, not the contributor's tooling.**

Much of the code here will have been written with an AI assistant, including
most of the original. That is the point of the project, and it is not a
criticism anyone needs to make.

What *is* fair to ask of any pull request, however it was written: does the
author understand it, can they explain why it works, and did they verify it?
A change nobody can explain is a maintenance liability regardless of who or
what typed it. Say that about the change — never as an accusation about the
person.

## The ordinary expectations

- Assume good faith. Most disagreements here are about a trade-off, not a
  fact.
- Criticise the code, not the person who wrote it.
- No harassment, personal attacks, or comments about someone's identity,
  background or ability.
- If you are asked to stop, stop.

## Enforcement

Report anything to the repository owner through GitHub. Responses are
graduated: usually a comment asking for a change of tone, then a locked
thread, then a block for repeated or deliberate behaviour.

A declined pull request is **not** an enforcement action. It is usually a
scope decision, and it says nothing about you.

## A note on scope decisions

"Out of scope" will be said often here, because the framework is defined as
much by what it refuses as by what it does. It is not a polite way of saying
the work was bad. Where a decline is about scope, it will say so plainly, and
you are welcome to build the thing anyway in your own fork or as a
[community module](docs/COMMUNITY-MODULES.md).
