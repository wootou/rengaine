# AI and the library

Rengaine lets an AI help you tidy a large library (genres, years, artist spellings, tag values) without
ever changing it behind your back: an AI can only **propose** batches of corrections, which you apply or
reject in **Admin > Proposals**, and which stay reversible.

There are two ways to bring an AI in. They use the same 20 tools.

## 1. Any MCP client (recommended)

The server exposes a [Model Context Protocol](https://modelcontextprotocol.io/) endpoint at
`/api/v2/mcp` (Streamable HTTP transport, stateless, JSON responses). Any MCP-capable client can connect:
Claude Code, and other compatible clients.

1. In **Admin > Accounts**, create an account with the **agent** role.
2. On its page, create a token. For Claude Code, the screen also shows the ready-made command:

   ```bash
   claude mcp add --transport http rengaine https://music.example.org/api/v2/mcp \
     --header "Authorization: Bearer <token>"
   ```

3. Ask, for example: "Map the genre tag values that are not mapped yet", "Find artists spelled in two
   ways and propose merges", "Fill in missing years from the tags".

Tools: search, list and read albums (with raw file tags and active corrections), artists, tracks,
genres, unmapped tag values, collections, duplicates, same recordings, damaged files, inbox, indexing
errors, corrections, proposal batches; and `propose_changes`, the only one that writes (it creates a
pending batch). The token only works on the API; a browser session cannot use the MCP endpoint.

Clients that require OAuth for remote servers (some hosted assistants) cannot connect yet.

## 2. An API key on the server

With an `[ia]` section in `rengaine.ini`, **Admin > Proposals > Ask an AI** sends your instruction to a
model, which works as a background job with the same tools, under the first active agent account, and
writes a report when done. Two families of providers:

- `fournisseur = "anthropic"`: the Anthropic Messages API (default model `claude-opus-5-5`);
- `fournisseur = "openai"`: any OpenAI-compatible chat-completions API, including local models (Ollama,
  LM Studio, vLLM), in which case nothing leaves your network.

The key stays in the configuration file; the admin screen only shows the provider and model.

## Prompt injection

Titles and tags come from files you did not write. A crafted tag could try to steer the model; that is
why it can only propose, and why the proposal screen reminds you to check each change before applying.
