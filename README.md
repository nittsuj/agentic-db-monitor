# Agentic DB Monitor

AI-assisted database monitoring and troubleshooting as a local desktop app. Chat in plain English to diagnose MySQL/PostgreSQL performance issues, let the assistant run read-only diagnostic checks automatically, and gate every write action behind explicit per-write approval.

## Requirements

- PHP ^8.3
- Composer
- Node.js + npm

## Setup

```bash
composer setup
```

This installs dependencies, creates `.env`, generates an app key, runs migrations, and builds frontend assets.

Then load demo data (sample projects, metrics, chat history, pending approvals):

```bash
php artisan db:seed
```

For local development, start the dev server with `composer dev`.