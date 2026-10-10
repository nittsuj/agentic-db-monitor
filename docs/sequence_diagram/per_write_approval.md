# Guardrails: Per-Write Approval — UC-06

Participants: see [`README.md`](./README.md).

```mermaid
%%{init: {"theme":"base","themeVariables":{"actorBkg":"#eaf1ff","actorBorder":"#5b7fdb","actorTextColor":"#1f2937","actorLineColor":"#c7d2fe","activationBkgColor":"#d1fae5","activationBorderColor":"#10b981","signalColor":"#475569","signalTextColor":"#1f2937","noteBkgColor":"#fff8dc","noteBorderColor":"#d4a017","noteTextColor":"#5c4400","sequenceNumberColor":"#5b7fdb"}}}%%
sequenceDiagram
    autonumber
    participant Agent as Agent Orchestrator
    participant Guard as Guardrail Engine
    participant UI as Livewire + Flux UI
    actor User
    participant Driver as Diagnostic Drivers
    participant DB as Target DB
    participant Store as SQLite (local store)

    activate Agent
    Agent->>Guard: Propose mutating / DDL / maintenance statement
    activate Guard
    Guard->>Guard: Classify statement (not read-only)
    Guard->>Store: Persist PendingAction (exact command)
    activate Store
    Store-->>Guard: stored
    deactivate Store
    Guard->>UI: Request approval (halt agent loop)
    activate UI
    deactivate Guard
    UI-->>User: Show Flux approval modal (exact command)
    User->>UI: Approve or deny
    deactivate UI
    alt Approved
        UI->>Guard: Approve
        activate Guard
        Guard->>Driver: Execute statement
        activate Driver
        Driver->>DB: DDL / DML
        activate DB
        DB-->>Driver: Result
        deactivate DB
        Driver-->>Guard: Applied
        deactivate Driver
        Guard->>Store: ActionLog (executed)
        activate Store
        Store-->>Guard: logged
        deactivate Store
        Guard-->>Agent: Inject result, resume loop
        deactivate Guard
    else Denied
        UI->>Guard: Deny
        activate Guard
        Guard->>Store: ActionLog (denied)
        activate Store
        Store-->>Guard: logged
        deactivate Store
        Guard-->>Agent: Inject cancellation, resume loop
        deactivate Guard
    end
    deactivate Agent
```

See also: activity counterpart [`../activity_diagram/per_write_approval.md`](../activity_diagram/per_write_approval.md),
[`ai_diagnostics.md`](./ai_diagnostics.md).

## Notes

- Precondition: reached only from an agent tool call classified as non-read-only.
- Invariant: no session-level bypass — every mutation is approved individually.
- `PendingAction` (exact command) is persisted before the halt; the command is shown verbatim.
- Deny path injects a cancellation notice so the agent adapts without executing.
- Postcondition: every decision (executed/denied) is written to `ActionLog`.
