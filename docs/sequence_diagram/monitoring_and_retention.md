# Monitoring & Retention — UC-07, UC-08

Participants: see [`README.md`](./README.md).

```mermaid
%%{init: {"theme":"base","themeVariables":{"actorBkg":"#eaf1ff","actorBorder":"#5b7fdb","actorTextColor":"#1f2937","actorLineColor":"#c7d2fe","activationBkgColor":"#d1fae5","activationBorderColor":"#10b981","signalColor":"#475569","signalTextColor":"#1f2937","noteBkgColor":"#fff8dc","noteBorderColor":"#d4a017","noteTextColor":"#5c4400","sequenceNumberColor":"#5b7fdb"}}}%%
sequenceDiagram
    autonumber
    participant Worker as Scheduler + QueueWorker
    participant Driver as Diagnostic Drivers
    participant DB as Target DB
    participant Store as SQLite (local store)
    participant Monitor as Thresholds + Notifier
    participant OS as OS Notifications

    Note over Worker: Scheduled every 5 min (tries=1)
    Worker->>Driver: Collect metrics
    activate Driver
    Driver->>DB: Read metrics
    activate DB
    DB-->>Driver: Metrics
    deactivate DB
    alt Query succeeded
        Driver->>Store: Persist MetricSample
        activate Store
        Store-->>Driver: stored
        deactivate Store
        Driver-->>Worker: Metrics collected
    else Query failed
        Driver-->>Worker: Error
    end
    deactivate Driver
    alt Query succeeded
        Worker->>Monitor: Evaluate per-project thresholds
        activate Monitor
        Monitor->>Store: Read thresholds + latest sample
        activate Store
        Store-->>Monitor: Thresholds, sample
        deactivate Store
        alt Threshold breached
            Monitor->>OS: Native notification
            activate OS
            OS-->>Monitor: shown
            deactivate OS
        end
        deactivate Monitor
    else Query failed
        Worker->>Worker: Discard job (no failed_jobs bloat)
    end
    Note over Worker, Store: Periodic prune (model:prune) removes samples older than 48h
    Worker->>Store: Delete MetricSample older than 48h
    activate Store
    Store-->>Worker: pruned
    deactivate Store
```

See also: activity counterpart [`../activity_diagram/monitoring_and_retention.md`](../activity_diagram/monitoring_and_retention.md),
[`configuration.md`](./configuration.md).

## Notes

- Trigger: scheduled every 5 min; job runs with `tries=1`.
- Failure semantics: a failed collect is discarded, not retried (avoids `failed_jobs` bloat).
- Thresholds are evaluated per-project.
- Retention: samples older than 48h are pruned; configuration/profiles are never pruned.
- Postcondition: a breach raises a native OS notification.
