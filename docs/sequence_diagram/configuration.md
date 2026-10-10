# Configuration — UC-09, UC-10

Participants: see [`README.md`](./README.md).

```mermaid
%%{init: {"theme":"base","themeVariables":{"actorBkg":"#eaf1ff","actorBorder":"#5b7fdb","actorTextColor":"#1f2937","actorLineColor":"#c7d2fe","activationBkgColor":"#d1fae5","activationBorderColor":"#10b981","signalColor":"#475569","signalTextColor":"#1f2937","noteBkgColor":"#fff8dc","noteBorderColor":"#d4a017","noteTextColor":"#5c4400","sequenceNumberColor":"#5b7fdb"}}}%%
sequenceDiagram
    autonumber
    actor User
    participant UI as Livewire + Flux UI
    participant Store as SQLite (local store)
    participant LLM as Ollama Cloud

    Note over User, LLM: UC-09 Configure AI Provider
    User->>UI: Open Settings
    activate UI
    UI->>Store: Load provider settings
    activate Store
    Store-->>UI: Settings
    deactivate Store
    User->>UI: Choose provider (default Ollama Cloud) + model + API key
    UI->>Store: Store API key (encrypted via Crypt)
    activate Store
    Store-->>UI: stored
    deactivate Store
    UI->>LLM: Test connection (validate key + tool calling)
    activate LLM
    LLM-->>UI: Valid or invalid
    deactivate LLM
    alt Valid
        UI->>Store: Save provider settings
        activate Store
        Store-->>UI: saved
        deactivate Store
        UI-->>User: Saved
    else Invalid
        UI-->>User: Show error
    end
    deactivate UI

    Note over User, Store: UC-10 Configure Alert Thresholds
    User->>UI: Manage per-project thresholds
    activate UI
    UI->>Store: CRUD thresholds (metric / operator / value / enabled)
    activate Store
    Store-->>UI: updated
    deactivate Store
    alt Delete project
        UI->>Store: Cascade-delete thresholds
        activate Store
        Store-->>UI: cascaded
        deactivate Store
    end
    deactivate UI
```

See also: activity counterpart [`../activity_diagram/configuration.md`](../activity_diagram/configuration.md),
[`monitoring_and_retention.md`](./monitoring_and_retention.md).

## Notes

- UC-09: provider defaults to Ollama Cloud; the model must be tool-capable; the key is encrypted at rest (never `.env`).
- Test connection validates both key validity and tool-calling support before saving.
- UC-10: thresholds are strictly project-scoped; deleting a project cascades to its thresholds.
- Postcondition: the poller evaluates only the active project's thresholds.
