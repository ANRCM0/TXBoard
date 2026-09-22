# TXBoard Admin Bridge Contract v2

Admin Bridge v2 is the optional host-service protocol for plugin-owned Admin Apps running inside TXBoard's same-origin iframe host.

Admin Bridge v1 remains supported. Plugin Package v1 does not require a Bridge v2 migration.

Bridge v2 is a frontend host protocol. It is not a PHP sandbox and it does not grant backend permissions.

## Negotiation

### Bridge v1 compatibility

Existing Plugin Package v1 apps may continue to send:

```js
{ type: "txboard:plugin:ready", version: 1 }
```

and use the existing `txboard:plugin:init` / `txboard:plugin:navigate` protocol unchanged.

### Bridge v2 opt-in

A Bridge v2-capable app sends:

```js
{ type: "txboard:module:ready", version: 2 }
```

The host replies only to the same validated iframe window and same origin:

```json
{
  "type": "txboard:module:init",
  "version": 2,
  "module": {
    "id": "access_audit",
    "type": "plugin",
    "name": "Access Audit",
    "version": "1.2.0"
  },
  "route": {
    "path": "reports"
  },
  "api": {
    "root": "",
    "admin": "/api/v2/<secure_path>"
  },
  "auth": {
    "authorization": "Bearer ..."
  },
  "theme": {
    "mode": "light"
  },
  "services": [
    "navigate",
    "toast",
    "confirm",
    "refresh",
    "open-user",
    "open-node",
    "open-machine",
    "get-theme"
  ]
}
```

The bearer authorization is only sent to the validated same-origin Plugin App iframe already accepted by Plugin Package v1 Admin App validation.

## Common validation

Before processing any Bridge request, the host verifies:

1. `event.origin === window.location.origin`;
2. `event.source` is the current Plugin App iframe;
3. the message is an object;
4. the message type/version is recognized;
5. message-specific fields pass bounded validation.

Unknown message types are ignored safely.

## Services

### navigate

Request:

```json
{
  "type": "txboard:module:navigate",
  "version": 2,
  "path": "reports/daily"
}
```

The path is restricted to a safe relative path within the current Plugin module. External URLs, absolute paths, backslashes and traversal are rejected.

### toast

Request:

```json
{
  "type": "txboard:module:toast",
  "version": 2,
  "level": "success",
  "message": "Saved"
}
```

Supported levels are `success`, `info`, `warning` and `error`. Messages are bounded host text, not HTML.

### confirm

Request:

```json
{
  "type": "txboard:module:confirm",
  "version": 2,
  "request_id": "delete-rule-7",
  "title": "Delete rule?",
  "message": "This action cannot be undone.",
  "danger": true,
  "confirm_label": "Delete",
  "cancel_label": "Cancel"
}
```

Response:

```json
{
  "type": "txboard:module:confirm-result",
  "version": 2,
  "request_id": "delete-rule-7",
  "confirmed": true
}
```

The host owns the confirmation UI.

### refresh

Request:

```json
{
  "type": "txboard:module:refresh",
  "version": 2
}
```

The host invalidates relevant Module/Plugin Admin query state. This is a presentation refresh, not a lifecycle command.

### open-user / open-node / open-machine

Requests:

```json
{ "type": "txboard:module:open-user", "version": 2, "id": 42 }
{ "type": "txboard:module:open-node", "version": 2, "id": 11 }
{ "type": "txboard:module:open-machine", "version": 2, "id": 3 }
```

IDs must resolve to a positive integer. The host converts them to existing Core Admin routes.

These services provide navigation only. They do not bypass Core authorization.

### get-theme

Request:

```json
{
  "type": "txboard:module:get-theme",
  "version": 2,
  "request_id": "theme-1"
}
```

Response:

```json
{
  "type": "txboard:module:theme",
  "version": 2,
  "request_id": "theme-1",
  "mode": "dark"
}
```

Theme mode is `light` or `dark`.

## Non-goals

Bridge v2 does not provide:

- arbitrary HTTP proxying;
- arbitrary filesystem access;
- shell or command execution;
- direct MySQL/Redis access;
- direct TX-Node operations;
- Plugin lifecycle execution;
- Agent approval bypass;
- cross-origin iframe trust;
- a PHP security sandbox.

Specialized Plugin backend operations continue through existing authenticated HTTP APIs.

## Compatibility

Bridge v2 is additive:

- Bridge v1 remains accepted;
- Plugin Package v1 remains the publishing boundary;
- existing `admin/dist` layouts remain valid;
- existing Plugin APIs and runtime lifecycle are unchanged;
- unknown v2 messages are ignored;
- a v1-only plugin requires no rebuild.
