# Semitexa Webhooks

Webhook support for Semitexa — inbound signature verification with deduplication, outbound durable delivery with retries, audit trail, and replay CLI.

## Phase 1

- Inbound: signature verification (HMAC-SHA256), durable inbox with deduplication
- Outbound: persistent outbox, claim-and-lease worker, exponential backoff retries
- Audit: append-only attempt history for all transitions
- CLI: `webhook:work`, `webhook:replay:inbound`, `webhook:replay:outbound`, `webhook:show`

## Outbound targets

A delivery is only sent to an `http(s)` URL whose host resolves exclusively to public addresses. Loopback, private and link-local ranges (including the cloud metadata address `169.254.169.254`) are refused, and cURL connects to the exact address that was checked, so a DNS answer cannot change in between. A refused target fails the attempt with the reason in the attempt history.

To deliver to a receiver on a private network, typically in development, set `WEBHOOK_ALLOW_PRIVATE_TARGETS=true`.
