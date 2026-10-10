# AI Diagnostics (read-only) — UC-03, UC-04, UC-05

Legend: see [`README.md`](./README.md).

The mutating branch hands off to [`per_write_approval.md`](./per_write_approval.md).

```mermaid
flowchart TD
    classDef usr fill:#f9f9f9,stroke:#333,stroke-width:2px;
    classDef ui fill:#41B883,stroke:#333,stroke-width:2px,color:#fff;
    classDef agent fill:#092E20,stroke:#333,stroke-width:2px,color:#fff;
    classDef guard fill:#B45309,stroke:#333,stroke-width:2px,color:#fff;
    classDef driver fill:#336791,stroke:#333,stroke-width:2px,color:#fff;
    classDef store fill:#336791,stroke:#333,stroke-width:2px,color:#fff;
    classDef llm fill:#005571,stroke:#333,stroke-width:2px,color:#fff;
    classDef dec fill:#FFF3CD,stroke:#B45309,stroke-width:2px,color:#333;

    B0([Start]):::usr
    B1["[User] Send prompt in chat"]:::usr
    B2["[Agent] Build Prism request<br/>(system prompt: list-first,<br/>bound to active project)"]:::agent
    B3["[LLM] Ollama Cloud responds"]:::llm
    B4{"Tool call requested?"}:::dec
    B5["[Agent] Stream natural-language tokens"]:::agent
    B6["[UI] Render tokens via $this->stream()"]:::ui
    B7{"Read-only tool?"}:::dec
    B8["[Guard] Per-write approval flow,<br/>then return result"]:::guard
    B9{"Schema lookup within 2-3 cap?"}:::dec
    B10["[Agent] Cap / refuse schema fetch"]:::agent
    B11["[Driver] Run read-only tool vs [Target]<br/>(ListTables / GetSlowQueries /<br/>CheckConnectionPool / AnalyzeTableBloat)"]:::driver
    B12["[Agent] Append result to Prism context<br/>+ [Store] persist message"]:::agent
    B13([End]):::usr

    B0 --> B1 --> B2 --> B3 --> B4
    B4 -->|no| B5 --> B6 --> B13
    B4 -->|yes| B7
    B7 -->|no| B8 --> B12
    B7 -->|yes| B9
    B9 -->|no| B10 --> B12
    B9 -->|yes| B11 --> B12
    B12 --> B3
```

See also: [`per_write_approval.md`](./per_write_approval.md),
[`onboarding_and_credentials.md`](./onboarding_and_credentials.md).

## Notes

- Precondition: active project with a healthy connection and a configured provider key.
- Invariant: read-only tools auto-run; the Guardrail Engine is reached only for mutations.
- Rule: schema lookups capped at 2–3 calls per turn to bound context.
- Prompt-enforced: "list-first" and tool choice are prompted (Ollama has no required-tools) and there is no native JSON mode.
- Postcondition: results appended to Prism context and the message persisted.
