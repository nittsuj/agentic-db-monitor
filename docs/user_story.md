# User Story (Business Logic)

### Module 1: Target Database Management

**US-01: Configure Database Credentials**

* **As a** User,
* **I want** to securely input and save my target database connection details (host, port, and password),
* **So that** the application can safely store my credentials using reversible encryption and connect to my databases automatically.

**US-02: View Project Workspace**

* **As a** User,
* **I want** to switch between different saved project databases from a sidebar,
* **So that** all health metrics, active connection contexts, and troubleshooting sessions remain strictly isolated to the selected database workspace.

---

### Module 2: AI Diagnostics & Troubleshooting

**US-03: Troubleshoot Database via Chat**

* **As a** User,
* **I want** to ask questions about database performance in plain English through a chat interface,
* **So that** I can diagnose system slowdowns and bottlenecks without needing to manually write complex queries.

**US-04: Inspect Table Schemas**

* **As a** User,
* **I want** the system to dynamically fetch the structure of specific tables during a troubleshooting session,
* **So that** the AI assistant has accurate context about the database design without wasting API tokens on irrelevant data.

**US-05: Execute Authorized AI Diagnostics**

* **As a** User,
* **I want** the AI assistant to automatically run read-only diagnostic checks (such as slow query analysis or connection counts),
* **So that** I get immediate insights into database health without extra friction.

---

### Module 3: Guardrails & Maintenance

**US-06: Authorize Modifying Actions**

* **As a** User,
* **I want** the application to pause and require my explicit approval before executing any database modifications or write actions proposed by the AI,
* **So that** I maintain full control and prevent accidental destructive changes to my database.

**US-07: Receive Performance Alerts**

* **As a** User,
* **I want** to receive a native desktop notification if background health metrics cross critical user-defined thresholds,
* **So that** I am immediately alerted to anomalies before applications crash.

**US-08: Clear Old Telemetry Logs**

* **As a** User,
* **I want** to manually clear or configure automatic pruning for old telemetry logs,
* **So that** I can manage my local storage and keep the application lightweight over time.