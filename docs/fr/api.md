# API

L'API est décrite par un contrat OpenAPI 3.1, [`api/openapi.yaml`](../../api/openapi.yaml) : opérations,
schémas, rôles (`x-roles`), codes d'erreur et limites. C'est la référence du serveur, du lecteur web, de
l'appli Android et des agents d'IA. Le contrat est rédigé en français.

`sh api/doc.sh` en fait une page lisible (Redoc, servi en local, sans CDN), dans `api/site/`.

L'essentiel :

- Chemin de base `/api/v2`. `GET /server` (sans authentification) donne la version de l'API
  (`apiVersion`), celle de Rengaine elle-même (`serverVersion`, depuis 2.29.0), les fonctions disponibles et la
  langue.
- Authentification : un cookie de session plus `X-CSRF-Token` pour le lecteur web, `Authorization: Bearer`
  pour les applis et les agents. Les appareils s'appairent avec un code court ou un QR code.
- Les erreurs ont toujours la même forme : `{"error": {"status", "code", "message", "requestId"}}` ; `code`
  est stable, `message` suit la langue du serveur.
- Les documents versionnés (file d'attente, réglages) utilisent `ETag` / `If-Match` ; un conflit renvoie le
  document actuel.
- Les flux acceptent les plages HTTP ; les images ont un cache permanent (adressées par leur contenu).
- `POST /mcp` est le point d'accès Model Context Protocol pour les agents d'IA (voir [IA](ai.md)) ; seuls les
  jetons d'agent y sont admis. Un jeton d'agent ne peut appeler que les fonctions cochées pour lui, par le MCP
  comme directement sur l'API (sinon `403 capability_disabled` ; il lit sa liste dans `GET /me`).
