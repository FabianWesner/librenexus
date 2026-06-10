# LibreNexus — Review Agent Checklists

After each epic (and on the final state), the agent runs **six structured
reviews**, each playing a senior role. Reviewers **verify explicit rules**, not
taste. Every review produces a structured report using
[00-report-template.md](00-report-template.md) and ends in a decision:
**pass**, **pass with warnings**, or **fail**.

A **blocking** finding (Critical or High per
[../definition-of-done.md](../definition-of-done.md) §Severity) means the epic is
**not done** until fixed.

## The six reviewers

| Reviewer | Verifies against | File |
|----------|------------------|------|
| Product | [../requirements.md](../requirements.md), [../pages.md](../pages.md) | [product-reviewer.md](product-reviewer.md) |
| Architecture | [../architecture.md](../architecture.md) | [architecture-reviewer.md](architecture-reviewer.md) |
| Security | [../security.md](../security.md) | [security-reviewer.md](security-reviewer.md) |
| QA | [../test-plan.md](../test-plan.md) | [qa-reviewer.md](qa-reviewer.md) |
| Performance | [../non-functional.md](../non-functional.md) | [performance-reviewer.md](performance-reviewer.md) |
| Code Quality | [../quality-gates.md](../quality-gates.md) | [code-quality-reviewer.md](code-quality-reviewer.md) |

## Rules for reviewers

- **Evidence over opinion.** Cite the rule ID, the file/flow, and the tool output
  or test that proves the finding. "Looks fine" is not a review.
- **Run the tools.** A review that claims a gate passes must show the command and
  result.
- **Be specific.** Each finding names the exact location and the required fix.
- **Be honest.** If something could not be checked, say so in the report; do not
  imply coverage that does not exist.
- **Decision discipline.** Any Critical/High finding ⇒ **fail**. A review may be
  **pass with warnings** only when every open finding is genuinely Medium/Low and
  tracked. If a finding's severity is in doubt, re-grade it explicitly before
  deciding — do not downgrade a real Critical/High just to avoid a fail.

## Where reports go

Store per-epic reports under `docs/reviews/epic-NN/<role>.md`. The final-state
reports feed the quality report (Epic 10).
