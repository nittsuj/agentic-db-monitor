# Entity Relationship Diagram — Agentic DB Monitor

Local SQLite store, Crow's foot notation. The default Laravel tables (`users`,
`password_reset_tokens`, `sessions`, `cache`, `jobs`) are untouched system tables and are
excluded. All PKs are UUID v7 (`char(36)`, `HasUuids`); foreign keys are `foreignUuid`.

```mermaid
erDiagram
    projects {
        uuid id PK
        string name
        string driver
        string host
        int port
        string database
        string username
        text password "encrypted at rest (Crypt)"
        json options "nullable"
        boolean is_active
        timestamp created_at
        timestamp updated_at
    }
    ai_settings {
        uuid id PK
        string provider
        string model "nullable"
        string base_url "nullable"
        text api_key "encrypted at rest (Crypt), nullable"
        boolean is_active
        timestamp created_at
        timestamp updated_at
    }
    chat_sessions {
        uuid id PK
        uuid project_id FK
        string title "nullable"
        timestamp created_at
        timestamp updated_at
    }
    chat_messages {
        uuid id PK
        uuid chat_session_id FK
        string role
        text content "nullable"
        json meta "nullable"
        timestamp created_at
        timestamp updated_at
    }
    pending_actions {
        uuid id PK
        uuid project_id FK
        uuid chat_session_id FK "nullable"
        string engine
        string category
        text command
        string status
        json meta "nullable"
        timestamp proposed_at
        timestamp decided_at "nullable"
        timestamp executed_at "nullable"
        timestamp created_at
        timestamp updated_at
    }
    action_logs {
        uuid id PK
        uuid project_id FK "nullable"
        string project_name "denormalized, preserved after project delete"
        string engine
        uuid pending_action_id FK "nullable"
        uuid chat_session_id FK "nullable"
        string decision
        text command
        string actor
        text result "nullable"
        text error "nullable"
        timestamp executed_at "nullable"
        timestamp created_at
        timestamp updated_at
    }
    metric_samples {
        uuid id PK
        uuid project_id FK
        string key
        float value
        timestamp captured_at
        json meta "nullable"
        timestamp created_at
        timestamp updated_at
    }
    thresholds {
        uuid id PK
        uuid project_id FK
        string key
        string operator
        float value
        boolean enabled
        timestamp created_at
        timestamp updated_at
    }

    projects ||--o{ chat_sessions : has
    chat_sessions ||--o{ chat_messages : contains
    projects ||--o{ pending_actions : proposes
    chat_sessions |o--o{ pending_actions : originates
    projects ||--o{ thresholds : defines
    projects ||--o{ metric_samples : collects
    projects |o--o{ action_logs : audited_as
    pending_actions |o--o{ action_logs : logged_as
    chat_sessions |o--o{ action_logs : audited_as
```

## How to read Crow's foot

| Symbol | Meaning |
| --- | --- |
| `||` | Exactly one |
| `|o` | Zero or one (optional) |
| `o{` | Zero or many |
| `||--o{` | One-to-many (parent owns many children) |
| `|o--o{` | Optional parent, many children (FK nullable) |

## Notes

- `ai_settings` is a standalone singleton (no FK) — UC-09 provider config.
- **Cascade with project delete:** `chat_sessions`, `chat_messages`, `pending_actions`,
  `metric_samples`, `thresholds`.
- **Preserved on project delete:** `action_logs` keeps its rows with `project_id` set to
  `NULL` and a denormalized `project_name`/`engine` so the audit trail stays readable
  (append-only).
- `pending_actions.chat_session_id`, `action_logs.project_id`/`pending_action_id`/
  `chat_session_id` are optional (`nullOnDelete`).
- `metric_samples` implements the `Prunable` contract (48h cutoff); configuration is never pruned.

See also: [`high_level_design.md`](./high_level_design.md), [`implementation_plan.md`](./implementation_plan.md) § 3,
[`naming_conventions.md`](./naming_conventions.md).