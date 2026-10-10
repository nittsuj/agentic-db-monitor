# Monitoring & Retention — UC-07, UC-08

Legend: see [`README.md`](./README.md).

```mermaid
flowchart TD
    classDef usr fill:#f9f9f9,stroke:#333,stroke-width:2px;
    classDef worker fill:#DC382D,stroke:#333,stroke-width:2px,color:#fff;
    classDef driver fill:#336791,stroke:#333,stroke-width:2px,color:#fff;
    classDef store fill:#336791,stroke:#333,stroke-width:2px,color:#fff;
    classDef monitor fill:#0E7490,stroke:#333,stroke-width:2px,color:#fff;
    classDef os fill:#009639,stroke:#333,stroke-width:2px,color:#fff;
    classDef dec fill:#FFF3CD,stroke:#B45309,stroke-width:2px,color:#333;

    D0([Scheduler tick: every 5 min]):::worker
    D1["[Worker] Dispatch CollectMetrics (tries=1)"]:::worker
    D2["[Driver] Read metrics from [Target]"]:::driver
    D3{"Query succeeded?"}:::dec
    D4["[Worker] Discard job<br/>(no failed_jobs bloat)"]:::worker
    D5["[Store] Persist MetricSample"]:::store
    D6["[Monitor] Evaluate per-project thresholds"]:::monitor
    D7{"Threshold breached?"}:::dec
    D8["[Monitor] Fire native alert"]:::monitor
    D9["[OS] Show notification"]:::os
    D10([End]):::usr
    D11["[Worker] Scheduled prune: model:prune"]:::worker
    D12["[Store] Delete MetricSample older than 48h<br/>(config untouched)"]:::store

    D0 --> D1 --> D2 --> D3
    D3 -->|no| D4 --> D10
    D3 -->|yes| D5 --> D6 --> D7
    D7 -->|yes| D8 --> D9 --> D10
    D7 -->|no| D10
    D11 --> D12 --> D10
```

See also: [`configuration.md`](./configuration.md).

## Notes

- Trigger: scheduled every 5 min; job runs with `tries=1`.
- Failure semantics: a failed collect is discarded, not retried (avoids `failed_jobs` bloat).
- Thresholds are evaluated per-project.
- Retention: samples older than 48h are pruned; configuration/profiles are never pruned.
- Postcondition: a breach raises a native OS notification.
