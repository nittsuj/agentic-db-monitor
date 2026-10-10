# Sequence Diagrams

Time-ordered interaction views of the system, one diagram per use-case cluster — mirroring
[`../activity_diagram/`](../activity_diagram/README.md). Each diagram shows the messages
exchanged between participants to realize the matching activity flow.

**Cross-references:** topology in [`../high_level_design.md`](../high_level_design.md),
use cases in [`../use_case_diagram.dio`](../use_case_diagram.dio), requirements in
[`../user_story.md`](../user_story.md).

## Index

| Sequence diagram | Use cases | Activity counterpart |
| --- | --- | --- |
| [`onboarding_and_credentials.md`](./onboarding_and_credentials.md) | UC-01, UC-02 | [onboarding & credentials](../activity_diagram/onboarding_and_credentials.md) |
| [`ai_diagnostics.md`](./ai_diagnostics.md) | UC-03, UC-04, UC-05 | [AI diagnostics](../activity_diagram/ai_diagnostics.md) |
| [`per_write_approval.md`](./per_write_approval.md) | UC-06 | [per-write approval](../activity_diagram/per_write_approval.md) |
| [`monitoring_and_retention.md`](./monitoring_and_retention.md) | UC-07, UC-08 | [monitoring & retention](../activity_diagram/monitoring_and_retention.md) |
| [`configuration.md`](./configuration.md) | UC-09, UC-10 | [configuration](../activity_diagram/configuration.md) |

## Participants

| Alias | Lifeline |
| --- | --- |
| `User` | `actor User` |
| `UI` | Livewire 3 + Flux UI |
| `Conn` | Connection Manager + Permission Check |
| `Agent` | Agent Orchestrator |
| `Guard` | Guardrail Engine |
| `Driver` | Diagnostic Drivers |
| `Worker` | Scheduler + QueueWorker |
| `Monitor` | Thresholds + Notifier |
| `Store` | SQLite (local store) |
| `DB` | Target DB (MySQL / PostgreSQL) |
| `LLM` | Ollama Cloud |
| `OS` | OS Notifications |

`Driver` is the single gateway to `DB`: `Conn`, `Agent`, `Guard`, and `Worker` all reach the
target database through it.

## Conventions

- Mermaid ` ```mermaid sequenceDiagram ` with `autonumber`.
- Combined lifelines (see participants); `actor User` marks the human.
- `alt` / `else` = decisions; `opt` = optional step; `loop` = the agent turn loop.
- Mermaid's `ref` fragment is **unsupported** in current Mermaid versions, so the
  diagnostics → approval handoff is shown with a `Note over` instead of a `ref`.
- Constraints are captured in `Note` lines and each file's `## Notes` section, mirroring
  the activity diagrams.
- **Color** is limited to each participant's lifeline box and the activation bars, applied
  globally with a `%%{init: {"theme":"base","themeVariables":{...}}}%%` directive at the top
  of every diagram (no `box`/`rect` fills — those proved unreadable). The palette:

  | Theme variable | Value | Applies to |
  | --- | --- | --- |
  | `actorBkg` / `actorBorder` / `actorTextColor` | `#eaf1ff` / `#5b7fdb` / `#1f2937` | Lifeline (participant) boxes |
  | `actorLineColor` | `#c7d2fe` | Vertical lifelines |
  | `activationBkgColor` / `activationBorderColor` | `#d1fae5` / `#10b981` | Activation bars |
  | `signalColor` / `signalTextColor` | `#475569` / `#1f2937` | Arrows and their labels |
  | `noteBkgColor` / `noteBorderColor` / `noteTextColor` | `#fff8dc` / `#d4a017` / `#5c4400` | `Note` fragments |
  | `sequenceNumberColor` | `#5b7fdb` | `autonumber` badges |

  Activation must be **linearly balanced** (`activate`/`deactivate`), since Mermaid counts
  activations across both `alt` branches — deactivating in only one branch fails to render.
