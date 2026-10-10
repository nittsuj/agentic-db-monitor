# Onboarding & Credentials — UC-01, UC-02

Participants: see [`README.md`](./README.md).

```mermaid
%%{init: {"theme":"base","themeVariables":{"actorBkg":"#eaf1ff","actorBorder":"#5b7fdb","actorTextColor":"#1f2937","actorLineColor":"#c7d2fe","activationBkgColor":"#d1fae5","activationBorderColor":"#10b981","signalColor":"#475569","signalTextColor":"#1f2937","noteBkgColor":"#fff8dc","noteBorderColor":"#d4a017","noteTextColor":"#5c4400","sequenceNumberColor":"#5b7fdb"}}}%%
sequenceDiagram
    autonumber
    actor User
    participant UI as Livewire + Flux UI
    participant Conn as Connection Manager + Permission Check
    participant Store as SQLite (local store)
    participant DB as Target DB

    User->>UI: Open onboarding wizard
    activate UI
    UI-->>User: Render credentials form
    User->>UI: Submit engine, host, port, database, user, password
    UI->>Conn: Build runtime PDO connection
    activate Conn
    Conn->>DB: Connect
    activate DB
    DB-->>Conn: Connection result
    deactivate DB
    alt Connection failed
        Conn-->>UI: Actionable connection error
    else Connected
        Conn->>DB: Probe telemetry access (pg_stat_statements / performance_schema)
        activate DB
        DB-->>Conn: Telemetry privileges
        deactivate DB
        alt Privileges present
            Conn->>Store: Save Project (password encrypted via Crypt)
            activate Store
            Store-->>Conn: Project saved
            deactivate Store
            Conn-->>UI: Onboarding success
        else Privileges missing
            Conn-->>UI: Actionable error (missing grant / extension)
        end
    end
    deactivate Conn
    UI->>Conn: Scope to active project
    activate Conn
    Conn-->>UI: Scoped to project
    deactivate Conn
    UI-->>User: Show workspace
    deactivate UI
```

See also: activity counterpart [`../activity_diagram/onboarding_and_credentials.md`](../activity_diagram/onboarding_and_credentials.md).

## Notes

- Precondition: none — this is the first-run flow.
- Connection is validated and telemetry privileges probed *before* the project is saved; failures never persist.
- Password is encrypted via `Crypt` into SQLite, never written to `.env`.
- On any failure the flow returns to credential entry (retry loop) without saving.
- Postcondition: sessions/metrics/tools are scoped strictly to the active project.
