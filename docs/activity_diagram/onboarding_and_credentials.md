# Onboarding & Credentials — UC-01, UC-02

Legend: see [`README.md`](./README.md).

```mermaid
flowchart TD
    classDef usr fill:#f9f9f9,stroke:#333,stroke-width:2px;
    classDef ui fill:#41B883,stroke:#333,stroke-width:2px,color:#fff;
    classDef conn fill:#092E20,stroke:#333,stroke-width:2px,color:#fff;
    classDef store fill:#336791,stroke:#333,stroke-width:2px,color:#fff;
    classDef dec fill:#FFF3CD,stroke:#B45309,stroke-width:2px,color:#333;

    A0([Start]):::usr
    A1["[User] Open onboarding wizard"]:::usr
    A2["[User] Enter engine, host, port, database, user, password"]:::usr
    A3["[Conn] Build runtime PDO connection"]:::conn
    A4{"Connected?"}:::dec
    A5["[UI] Show actionable connection error"]:::ui
    A6["[Conn] Probe telemetry access<br/>(pg_stat_statements / performance_schema)"]:::conn
    A7{"Required privileges present?"}:::dec
    A8["[UI] Show actionable error<br/>(missing grant / extension) - do not save"]:::ui
    A9["[Conn] Encrypt password (Crypt)<br/>+ [Store] Save Project"]:::conn
    A10["[UI] Sidebar: [User] select workspace"]:::ui
    A11["[Conn] Scope sessions / metrics / tools<br/>to the active project"]:::conn
    A12([End]):::usr

    A0 --> A1 --> A2 --> A3 --> A4
    A4 -->|no| A5 --> A2
    A4 -->|yes| A6 --> A7
    A7 -->|no| A8 --> A2
    A7 -->|yes| A9 --> A10 --> A11 --> A12
```

See also: [`ai_diagnostics.md`](./ai_diagnostics.md).

## Notes

- Precondition: none — this is the first-run flow.
- Connection is validated and telemetry privileges probed *before* the project is saved; failures never persist.
- Password is encrypted via `Crypt` into SQLite, never written to `.env`.
- Postcondition: sessions/metrics/tools are scoped strictly to the active project.
