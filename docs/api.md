# API

The API is described by an OpenAPI 3.1 contract, [`api/openapi.yaml`](../api/openapi.yaml): operations,
schemas, roles (`x-roles`), error codes and limits. It is the reference for the server, the web player,
the Android app and AI agents.

Build a readable page with `sh api/doc.sh` (Redoc, served locally, no CDN): it writes `api/site/`.

Highlights:

- Base path `/api/v2`. `GET /server` (no authentication) tells the version, the available features and
  the language.
- Authentication: a session cookie plus `X-CSRF-Token` for the web player, `Authorization: Bearer` for
  apps and agents. Devices pair with a short code or a QR code.
- Errors always have the same shape: `{"error": {"status", "code", "message", "requestId"}}`; `code` is
  stable, `message` follows the server language.
- Versioned documents (queue, settings) use `ETag` / `If-Match`; a conflict returns the current document.
- Streams support HTTP ranges; images are served with permanent caching (content-addressed).
- `POST /mcp` is the Model Context Protocol endpoint for AI agents (see [AI](ai.md)).
