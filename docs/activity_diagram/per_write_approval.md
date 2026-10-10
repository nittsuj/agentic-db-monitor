# Guardrails: Per-Write Approval — UC-06

Legend: see [`README.md`](./README.md).

```mermaid
flowchart TD
    classDef usr fill:#f9f9f9,stroke:#333,stroke-width:2px;
    classDef ui fill:#41B883,stroke:#333,stroke-width:2px,color:#fff;
    classDef agent fill:#092E20,stroke:#333,stroke-width:2px,color:#fff;
    classDef guard fill:#B45309,stroke:#333,stroke-width:2px,color:#fff;
    classDef store fill:#336791,stroke:#333,stroke-width:2px,color:#fff;
    classDef dec fill:#FFF3CD,stroke:#B45309,stroke-width:2px,color:#333;

    C0([Start: agent proposes an action]):::agent
    C1["[Agent] Propose mutating / DDL / maintenance statement<br/>(OPTIMIZE TABLE / CREATE INDEX / VACUUM)"]:::agent
    C2["[Guard] Classify statement (not read-only)"]:::guard
    C3["[Guard] Create PendingAction (exact command)<br/>+ [Store] persist"]:::guard
    C4["[Guard] Halt agent loop"]:::guard
    C5["[UI] Show Flux approval modal<br/>with the exact command"]:::ui
    C6{"User approves?"}:::dec
    C7["[Guard] Execute via [Driver] vs [Target]"]:::guard
    C8["[Store] ActionLog: executed<br/>+ inject result into Prism context"]:::store
    C9["[Store] ActionLog: denied<br/>+ inject cancellation notice"]:::store
    C10["[Agent] Resume Prism loop"]:::agent
    C11([End]):::usr

    C0 --> C1 --> C2 --> C3 --> C4 --> C5 --> C6
    C6 -->|approve| C7 --> C8 --> C10
    C6 -->|deny| C9 --> C10
    C10 --> C11
```

> No session-level bypass: every proposed mutation requires its own approval and is audited.

See also: [`ai_diagnostics.md`](./ai_diagnostics.md).

## Notes

- Precondition: reached only from an agent tool call classified as non-read-only.
- Invariant: no session-level bypass — every mutation is approved individually.
- `PendingAction` (exact command) is persisted before the halt; the command is shown verbatim.
- Deny path injects a cancellation notice so the agent adapts without executing.
- Postcondition: every decision (executed/denied) is written to `ActionLog`.
