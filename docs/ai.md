# AI and the library

Rengaine lets an AI help you tidy a large library (genres, years, artist spellings, tag values) without
ever changing it behind your back: an AI can only **propose** batches of corrections, which you apply or
reject in **Admin > Proposals**, and which stay reversible.

There are two ways to bring an AI in. They use the same 24 tools.

## 1. Any MCP client (recommended)

The server exposes a [Model Context Protocol](https://modelcontextprotocol.io/) endpoint at
`/api/v2/mcp` (Streamable HTTP transport, stateless, JSON responses). Any MCP-capable client can connect:
Claude Code, and other compatible clients.

1. In **Admin > Accounts**, create an account with the **agent** role (no email needed).
2. On its page, create a token and tick what this AI may do (see below). For Claude Code, the screen also
   shows the ready-made command:

   ```bash
   claude mcp add --transport http rengaine https://music.example.org/api/v2/mcp \
     --header "Authorization: Bearer <token>"
   ```

3. Ask, for example: "Map the genre tag values that are not mapped yet", "Find artists spelled in two
   ways and propose merges", "Fill in missing years from the tags".

Tools: search, list and read albums (with raw file tags and active corrections), artists, tracks,
genres, unmapped tag values, collections, duplicates, same recordings, damaged files, inbox, indexing
errors, corrections, proposal batches, artists spelled several ways, albums present twice, artists read from
"Artist - Title" track titles, covers found online (if enabled, see [configuration](configuration.md)); and
`propose_changes`, the only one that writes (it creates a
pending batch). Only agent tokens can use the MCP endpoint: an admin token or a browser session is refused.

### What each AI may do

Each agent token has one switch per function, set on the account page and applied at the next request
(each change is in the audit log). The server enforces them on every request made with that token, through
MCP or directly on the API; a disabled function is missing from the tool list and answers
`403 capability_disabled` with its name.

| Function | By default | |
|---|---|---|
| Read the library | on | albums, tracks, artists, genres, search; corrections and batches |
| Analyse the library | on | duplicates, same recordings, damaged files, tag values, lookalike artists, albums present twice, artists read from titles |
| See the inbox and errors | off | shows file paths |
| Propose tag corrections | on | titles, artists, years, genres, numbers, BPM, key |
| Propose structural changes | off | merges, removed albums, album kind, moved tracks |
| Propose a known cover | on | an image already in the library |
| Covers found online | off | the server connects to the Internet (and must allow it, see [configuration](configuration.md)) |
| └ Known sites only | on | iTunes, Deezer, Cover Art Archive; unticked, a cover may come from any https site (warning shown), every other check stays |

Tokens created before 1.0.4 keep what they could do then (everything except covers found online). The
reorganisation started from **Admin > Proposals** works with the same set.

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
