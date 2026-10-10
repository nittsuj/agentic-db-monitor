# Agentic DB Monitor High-Level Design (HLD)

## Current Architecture Topology

This diagram outlines the runtime topology of the Agentic DB Monitor desktop application: a single-user, local NativePHP Desktop v2 app whose Laravel core orchestrates an AI diagnostic loop against target MySQL/PostgreSQL databases. The interface is built with Livewire 3 and the free Flux UI component library.

```mermaid
flowchart TD
    %% Node Colors and Styles
    classDef client fill:#f9f9f9,stroke:#333,stroke-width:2px;
    classDef ui fill:#41B883,stroke:#333,stroke-width:2px,color:#fff;
    classDef backend fill:#092E20,stroke:#333,stroke-width:2px,color:#fff;
    classDef ai fill:#005571,stroke:#333,stroke-width:2px,color:#fff;
    classDef db fill:#336791,stroke:#333,stroke-width:2px,color:#fff;
    classDef queue fill:#DC382D,stroke:#333,stroke-width:2px,color:#fff;
    classDef os fill:#009639,stroke:#333,stroke-width:2px,color:#fff;

    %% Local User
    User["Local User"]:::client

    %% NativePHP Desktop Application (single local process)
    subgraph Desktop ["NativePHP Desktop v2 App"]
        UI["Livewire 3 + Flux UI<br/>Onboarding / Sidebar / Chat / Approval / Dashboard / Settings"]:::ui

        subgraph Core ["Laravel Application Core"]
            Agent["Agent Orchestrator<br/>Prism Tool-Loop"]:::backend
            Guard["Guardrail Engine<br/>Per-Write Approval"]:::backend
            Conn["Connection Manager<br/>+ Permission Check"]:::backend
            Driver["Diagnostic Drivers<br/>MySQL / PostgreSQL"]:::backend
            Monitor["Metrics + Thresholds<br/>+ Notifier"]:::backend
        end

        Worker["Scheduler + QueueWorker<br/>tries=1"]:::queue
        SQLite[("SQLite<br/>Profiles / Chat / Metrics<br/>Thresholds / PendingActions / ActionLog")]:::db
    end

    %% External Systems
    subgraph External ["External Systems"]
        Ollama["Ollama Cloud<br/>Free-Tier Agentic API"]:::ai
        MySQL[("MySQL Target")]:::db
        Postgres[("PostgreSQL Target")]:::db
        OSNotif["OS Notifications"]:::os
    end

    %% Connections
    User -->|Uses| UI
    UI -->|"Chat / Approve / Settings"| Agent
    UI -->|"Profiles / Workspaces"| Conn

    Agent -->|"Prism Requests + Tools"| Ollama
    Agent -->|"Read-only Tools"| Driver
    Agent -->|"Propose Mutation"| Guard
    Guard -->|"Approved Statement"| Driver
    Conn -->|"Runtime Connection"| Driver
    Driver -->|"Queries"| MySQL
    Driver -->|"Queries"| Postgres

    Worker -->|"Scheduled Collection"| Monitor
    Monitor -->|"Metrics Query"| Driver
    Monitor -->|"Threshold Breach"| OSNotif
    Worker -->|"Prune > 48h"| SQLite

    %% Local persistence (collapsed to one edge so it does not tangle the flow)
    Core -->|"Read / Write"| SQLite
```
