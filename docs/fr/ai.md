# L'IA et la bibliothèque

Rengaine permet à une IA de vous aider à ranger une grande bibliothèque (genres, années, orthographes
d'artistes, valeurs de tags) sans jamais la changer dans votre dos : une IA ne peut que **proposer** des lots
de corrections, que vous appliquez ou refusez dans **Admin > Propositions**, et qui restent annulables.

Il y a deux façons de faire entrer une IA. Elles utilisent les mêmes 20 outils.

## 1. N'importe quel client MCP (recommandé)

Le serveur expose un point d'accès [Model Context Protocol](https://modelcontextprotocol.io/) à
`/api/v2/mcp` (transport HTTP « Streamable », sans état, réponses JSON). Tout client compatible MCP peut
s'y connecter : Claude Code, et les autres clients compatibles.

1. Dans **Admin > Comptes**, créez un compte avec le rôle **agent**.
2. Sur sa fiche, créez un jeton. Pour Claude Code, l'écran affiche aussi la commande toute prête :

   ```bash
   claude mcp add --transport http rengaine https://music.example.org/api/v2/mcp \
     --header "Authorization: Bearer <jeton>"
   ```

3. Demandez par exemple : « Rattache les valeurs de genre des tags qui ne le sont pas encore », « Trouve
   les artistes écrits de deux façons et propose des fusions », « Complète les années manquantes d'après
   les tags ».

Outils : rechercher, lister et lire les albums (avec les tags bruts des fichiers et les corrections
actives), les artistes, les pistes, les genres, les valeurs de tags non rattachées, les collections, les
doublons, les mêmes enregistrements, les fichiers abîmés, le dépôt, les erreurs d'indexation, les
corrections, les lots ; et `propose_changes`, le seul qui écrit (il crée un lot en attente). Le jeton ne
fonctionne que sur l'API ; une session de navigateur ne peut pas utiliser le point d'accès MCP.

Les clients qui exigent OAuth pour un serveur distant (certains assistants en ligne) ne peuvent pas encore
se connecter.

## 2. Une clé d'API sur le serveur

Avec une section `[ia]` dans `rengaine.ini`, **Admin > Propositions > Demander à une IA** envoie votre
consigne à un modèle, qui travaille en tâche de fond avec les mêmes outils, sous le nom du premier compte
agent actif, et écrit un compte rendu à la fin. Deux familles de fournisseurs :

- `fournisseur = "anthropic"` : l'API Messages d'Anthropic (modèle par défaut `claude-opus-5-5`) ;
- `fournisseur = "openai"` : toute API compatible OpenAI (chat completions), y compris des modèles locaux
  (Ollama, LM Studio, vLLM) : rien ne sort alors de votre réseau.

La clé reste dans le fichier de configuration ; l'écran de l'admin n'affiche que le fournisseur et le modèle.

## Injection de consignes

Les titres et les tags viennent de fichiers que vous n'avez pas écrits. Un tag fabriqué pourrait tenter
d'orienter le modèle : c'est pour cela qu'il ne peut que proposer, et que l'écran des propositions rappelle
de vérifier chaque changement avant de l'appliquer.
