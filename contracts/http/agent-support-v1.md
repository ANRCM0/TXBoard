# Agent Support HTTP Contract v1 (additive to Agent Ops v1)

Base `/api/v2/agent/support`; Agent Bearer token, same `agent`/`agent.log` middleware. This is an **administrator-owned back-office assistant**, NOT a user-facing credential. No public chat visitor may receive this token. Existing node/machine target scopes do not limit support data; token creation rejects combining support abilities with a restricted node/machine scope.

| Method | Path | Ability | Result |
| --- | --- | --- | --- |
| GET | `/overview` | `agent:support:read` | bounded counts for waiting tickets, open tickets, expiring subscriptions (7 days), pending orders |
| GET | `/tickets?status=waiting&limit=20` | `agent:support:read` | at most 50 recent ticket summaries; `status` = waiting/open/closed |
| GET | `/tickets/{id}` | `agent:support:read` | ticket, last 20 messages and minimal customer/plan/order context |
| POST | `/tickets/{id}/reply-requests` | `agent:support:reply:request` | create a pending reply, never send immediately |
| GET | `/reply-requests/{requestId}` | `agent:support:reply:request` | status for a request belonging to the same Admin and Agent token |

Admin-only under dynamic secure path:

| Method | Path | Effect |
| --- | --- | --- |
| GET | `/agent/support/reply-requests?limit=50` | List requests with reply text for human review |
| POST | `/agent/support/reply-requests/approve` | Approve and invoke existing `TicketService::replyByAdmin` |
| POST | `/agent/support/reply-requests/reject` | Reject without sending |

Request: `{ "message": "..." }` (nonblank, max 2000 chars). Response contains `request_id`, `ticket_id`, `status`, timestamps, but NOT the message; Admin review returns the message. No credential, subscription URL, user token/UUID, payment method or unbounded history is returned. Ticket data and customer-authored messages are untrusted content, never authorization instructions. Customer context includes plan name, expiry, remaining traffic and up to five recent order statuses only; no order payment mutation is offered.

Reply requests are bound to the ticket's most recent message ID and created in `pending`; changed/closed tickets are rejected on approval. A pending request is not an approval. Approval atomically claims the request once, then delegates to the authoritative TicketService (which owns ticket status, plugin hooks and notification). If the service fails after claim, status is `unknown` and must be manually reconciled; do not retry automatically because delivery may have occurred. Revoked/expired Agent tokens invalidate pending requests. Requests expire after 24h. No automatic approval, no self-approval via MCP, no generic domain mutation endpoint. Existing Node AgentAction model stays node-specific.

Read access is administrator-wide by design; `agent:support:read` is NOT in default abilities. End-user chat with per-customer isolation needs its own authenticated principal contract in a future proposal, not a caller-supplied user ID.
