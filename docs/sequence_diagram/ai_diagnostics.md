# AI Diagnostics (read-only) — UC-03, UC-04, UC-05

Participants: see [`README.md`](./README.md).

The mutating branch is defined in [`per_write_approval.md`](./per_write_approval.md); it is
marked below with a handoff note (Mermaid's `ref` fragment is unsupported in current versions).

```mermaid
%%{init: {"theme":"base","themeVariables":{"actorBkg":"#eaf1ff","actorBorder":"#5b7fdb","actorTextColor":"#1f2937","actorLineColor":"#c7d2fe","activationBkgColor":"#d1fae5","activationBorderColor":"#10b981","signalColor":"#475569","signalTextColor":"#1f2937","noteBkgColor":"#fff8dc","noteBorderColor":"#d4a017","noteTextColor":"#5c4400","sequenceNumberColor":"#5b7fdb"}}}%%
sequenceDiagram
    autonumber
    actor User
    participant UI as Livewire + Flux UI
    participant Agent as Agent Orchestrator
    participant LLM as Ollama Cloud
    participant Guard as Guardrail Engine
    participant Driver as Diagnostic Drivers
    participant DB as Target DB
    participant Store as SQLite (local store)

    User->>UI: Send prompt in chat
    activate UI
    UI->>Agent: Start agent turn (active project)
    activate Agent
    deactivate UI
    loop Until the model stops requesting tools
        Agent->>LLM: Prism request (system prompt: list-first)
        activate LLM
        LLM-->>Agent: Response (content and/or tool calls)
        deactivate LLM
        alt Tool call requested
            alt Read-only tool
                alt Schema lookup within 2-3 cap
                    Agent->>Driver: Run read-only tool
                    activate Driver
                    Driver->>DB: Read query
                    activate DB
                    DB-->>Driver: Rows
                    deactivate DB
                    Driver-->>Agent: Result
                    deactivate Driver
                    Agent->>Store: Persist message
                    activate Store
                    Store-->>Agent: stored
                    deactivate Store
                else Cap exceeded
                    Agent->>Agent: Cap / refuse schema fetch
                end
            else Mutating tool
                Note over Agent, Guard: Mutating tool -> per_write_approval.md
            end
        else No tool call
            Agent->>UI: Stream natural-language tokens
            activate UI
            UI-->>User: Render tokens ($this->stream())
            deactivate UI
        end
    end
    Agent->>Store: Persist final message
    activate Store
    Store-->>Agent: stored
    deactivate Store
    deactivate Agent
```

See also: activity counterpart [`../activity_diagram/ai_diagnostics.md`](../activity_diagram/ai_diagnostics.md).

## Notes

- Precondition: active project with a healthy connection and a configured provider key.
- Invariant: read-only tools auto-run; the Guardrail Engine is reached only for mutations.
- Rule: schema lookups capped at 2–3 calls per turn to bound context.
- Prompt-enforced: "list-first" and tool choice are prompted (Ollama has no required-tools) and there is no native JSON mode.
- Postcondition: results appended to Prism context and the message persisted.
