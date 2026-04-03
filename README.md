# Semitexa Webhooks

Webhook support for Semitexa — inbound signature verification with deduplication, outbound durable delivery with retries, audit trail, and replay CLI.

## Phase 1

- Inbound: signature verification (HMAC-SHA256), durable inbox with deduplication
- Outbound: persistent outbox, claim-and-lease worker, exponential backoff retries
- Audit: append-only attempt history for all transitions
- CLI: `webhook:work`, `webhook:replay:inbound`, `webhook:replay:outbound`, `webhook:show`
