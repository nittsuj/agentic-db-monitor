# Agentic DB Monitor High-Level Design (HLD)

## Current Architecture Topology

This diagram outlines the runtime topology of the Agentic DB Monitor desktop application: a single-user, local NativePHP (Electron + PHP) app whose Laravel core orchestrates an AI diagnostic loop against target MySQL/PostgreSQL databases. The interface is built with Livewire 3 and the free Flux UI component library.

```mermaid
flowchart TD
    %% Define Node Colors and Styles
    classDef client fill:#f9f9f9,stroke:#333,stroke-width:2px;
    classDef ui fill:#41B883,stroke:#333,stroke-width:2px,color:#fff;
    classDef backend fill:#092E20,stroke:#333,stroke-width:2px,color:#fff;
    classDef ai fill:#005571,stroke:#333,stroke-width:2px,color:#fff;
    classDef db fill:#336791,stroke:#333,stroke-width:2px,color:#fff;
    classDef queue fill:#DC382D,stroke:#333,stroke-width:2px,color:#fff;
    classDef os fill:#009639,stroke:#333,stroke-width:2px,color:#fff;

    %% Local User
    User[Local User]:::client

    %% NativePHP Desktop Application
    subgraph Desktop[NativePHP Desktop App - Electron + PHP]
        Shell[Electron Window<br/>NativePHP Runtime]:::client

        subgraph UI[Livewire 3 + Flux UI Components]
            Onboard[Onboarding Wizard]:::ui
            Sidebar[Workspace Sidebar]:::ui
            Chat[Diagnostics Chat<br/>Token Streaming]:::ui
            Approve[Approval Modal]:::ui
            Dash[Health Dashboard]:::ui
            Settings[Settings<br/>Provider / Thresholds / Retention]:::ui
        end

        subgraph Core[Application Core - Laravel Services]
            Prism[Prism PHP Client<br/>Provider + Tool Definitions]:::ai
            Agent[Agent Orchestrator<br/>Tool-Loop]:::backend
            Guard[Guardrail Engine<br/>Per-Write Approval]:::backend
            Conn[TargetConnectionManager<br/>Dynamic PDO]:::backend
            Perm[PermissionChecker]:::backend
            Driver[DiagnosticDriver<br/>MySQL / PostgreSQL]:::backend
            Metrics[Metrics Collector]:::backend
            Threshold[Threshold Evaluator]:::backend
            Notifier[Alert Notifier]:::backend
            Pruner[Retention / Pruner<br/>48h]:::backend
        end

        Queue[Scheduler + QueueWorker<br/>tries=1]:::queue
        SQLite[(SQLite<br/>Profiles / Chat / Thresholds<br/>Metrics / PendingActions / ActionLog)]:::db
    end

    %% External Systems
    subgraph External[External Systems]
        Ollama[Ollama Cloud<br/>Free-Tier Agentic API]:::ai
        MySQL[(MySQL Target)]:::db
        Postgres[(PostgreSQL Target)]:::db
        OSNotif[OS Notifications]:::os
    end

    %% User interaction
    User --> Shell
    Shell --> UI

    %% UI to Core
    Onboard --> Conn
    Onboard --> Perm
    Sidebar --> Conn
    Chat --> Agent
    Approve --> Guard
    Settings -->|Provider / Model| Prism
    Settings --> Threshold
    Settings -->|Manual Clear| Pruner
    Dash -->|Read Samples| SQLite

    %% Agent loop
    Agent -->|Prompts + Tools| Prism
    Prism -->|Request / Stream| Ollama
    Agent -->|Read-only Tools| Driver
    Agent -->|Propose Mutation| Guard
    Guard -->|Approved Statement| Driver

    %% Connection and target access (single gateway: Driver)
    Conn -->|Runtime Connection| Driver
    Conn -->|Profile Read / Write| SQLite
    Perm -->|Privilege Probe| Driver
    Driver -->|Read-only / Approved| MySQL
    Driver -->|Read-only / Approved| Postgres

    %% Background work
    Queue --> Metrics
    Queue --> Pruner
    Metrics --> Driver
    Metrics --> Threshold
    Metrics -->|Write Samples| SQLite
    Threshold -->|Breach| Notifier
    Notifier --> OSNotif

    %% Local persistence
    Chat -->|Messages| SQLite
    Guard -->|PendingAction / Audit| SQLite
    Pruner -->|Prune > 48h| SQLite
```
