# Configuration — UC-09, UC-10

Legend: see [`README.md`](./README.md).

```mermaid
flowchart TD
    classDef usr fill:#f9f9f9,stroke:#333,stroke-width:2px;
    classDef ui fill:#41B883,stroke:#333,stroke-width:2px,color:#fff;
    classDef store fill:#336791,stroke:#333,stroke-width:2px,color:#fff;
    classDef monitor fill:#0E7490,stroke:#333,stroke-width:2px,color:#fff;
    classDef llm fill:#005571,stroke:#333,stroke-width:2px,color:#fff;
    classDef dec fill:#FFF3CD,stroke:#B45309,stroke-width:2px,color:#333;

    E0([Start]):::usr
    subgraph EAI ["UC-09 Configure AI Provider"]
        E1["[User] Open Settings"]:::usr
        E2["[User] Choose provider (default Ollama Cloud)<br/>+ tool-capable model"]:::usr
        E3["[UI] Enter API key -> encrypt (Crypt) -> [Store]"]:::ui
        E4["[LLM] Test connection<br/>(validate key + tool calling)"]:::llm
        E5{"Valid?"}:::dec
        E6["[UI] Show error"]:::ui
        E7["[Store] Save provider settings"]:::store
    end
    subgraph ETH ["UC-10 Configure Alert Thresholds"]
        E8["[User] Manage per-project thresholds<br/>(metric / operator / value / enabled)"]:::usr
        E9{"Delete project?"}:::dec
        E10["[Store] Cascade-delete thresholds"]:::store
        E11["[Monitor] Poll uses only active project thresholds"]:::monitor
    end
    E12([End]):::usr

    E0 --> E1 --> E2 --> E3 --> E4 --> E5
    E5 -->|no| E6 --> E2
    E5 -->|yes| E7 --> E8
    E8 --> E9
    E9 -->|yes| E10 --> E11
    E9 -->|no| E11
    E11 --> E12
```

See also: [`monitoring_and_retention.md`](./monitoring_and_retention.md).

## Notes

- UC-09: provider defaults to Ollama Cloud; the model must be tool-capable; the key is encrypted at rest (never `.env`).
- Test connection validates both key validity and tool-calling support before saving.
- UC-10: thresholds are strictly project-scoped; deleting a project cascades to its thresholds.
- Postcondition: the poller evaluates only the active project's thresholds.
