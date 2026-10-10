# Activity Diagrams

Workflow (control-flow) views of the system, one diagram per use-case cluster. Each
activity is prefixed with the owning actor/component and color-coded; decision points
are diamonds. Related use cases are named in each file's heading.

**Cross-references:** requirements in [`../user_story.md`](../user_story.md), use cases in
[`../use_case_diagram.dio`](../use_case_diagram.dio), topology in
[`../high_level_design.md`](../high_level_design.md).

## Index

| Activity diagram | Use cases | File |
| --- | --- | --- |
| Onboarding & Credentials | UC-01, UC-02 | [`onboarding_and_credentials.md`](./onboarding_and_credentials.md) |
| AI Diagnostics (read-only) | UC-03, UC-04, UC-05 | [`ai_diagnostics.md`](./ai_diagnostics.md) |
| Guardrails: Per-Write Approval | UC-06 | [`per_write_approval.md`](./per_write_approval.md) |
| Monitoring & Retention | UC-07, UC-08 | [`monitoring_and_retention.md`](./monitoring_and_retention.md) |
| Configuration | UC-09, UC-10 | [`configuration.md`](./configuration.md) |

## How to read

- **Label prefix** = who performs the activity, e.g. `[Agent]`, `[Guard]`, `[Driver]`.
- **Color** = owning actor/component (see legend below). Subgraphs are used only to group
  two sub-flows inside a single diagram; they are not swimlanes.
- **Diamond** = a decision; branches are labeled.

## Legend

| Owner | Prefix | Color |
| --- | --- | --- |
| User | `[User]` | grey `#f9f9f9` |
| UI (Livewire + Flux) | `[UI]` | green `#41B883` |
| Agent Orchestrator | `[Agent]` | dark green `#092E20` |
| Guardrail Engine | `[Guard]` | amber `#B45309` |
| Connection Manager / Permission Check | `[Conn]` | dark green `#092E20` |
| Diagnostic Drivers | `[Driver]` | blue `#336791` |
| Scheduler / QueueWorker | `[Worker]` | red `#DC382D` |
| Thresholds / Notifier | `[Monitor]` | teal `#0E7490` |
| Local store (SQLite) | `[Store]` | blue `#336791` |
| LLM (Ollama Cloud) | `[LLM]` | `#005571` |
| Target database | `[Target]` | `#336791` |
| OS | `[OS]` | `#009639` |
