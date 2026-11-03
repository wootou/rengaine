# L'IA et la bibliothèque

Rengaine permet à une IA de vous aider à ranger une grande bibliothèque (genres, années, orthographes
d'artistes, valeurs de tags) sans jamais la changer dans votre dos : une IA ne peut que **proposer** des lots
de corrections, que vous appliquez ou refusez dans **Admin > Propositions**, et qui restent annulables.

Il y a deux façons de faire entrer une IA. Elles utilisent les mêmes 24 outils.

## 1. N'importe quel client MCP (recommandé)

Le serveur expose un point d'accès [Model Context Protocol](https://modelcontextprotocol.io/) à
`/api/v2/mcp` (transport HTTP « Streamable », sans état, réponses JSON). Tout client compatible MCP peut
s'y connecter : Claude Code, et les autres clients compatibles.

1. Dans **Admin > Comptes**, créez un compte avec le rôle **agent** (sans adresse e-mail si vous voulez).
2. Sur sa fiche, créez un jeton et cochez ce que cette IA a le droit de faire (voir plus bas). Pour Claude
   Code, l'écran affiche aussi la commande toute prête :

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
corrections, les lots, les artistes écrits de plusieurs façons, les albums présents deux fois, les artistes lus
dans les titres « Artiste - Titre », les jaquettes trouvées en ligne (si c'est activé, voir la
[configuration](configuration.md)) ; et `propose_changes`, le seul qui écrit (il crée un lot en attente). Le jeton ne
fonctionne que sur l'API. Seuls les jetons d'agent peuvent utiliser le point d'accès MCP : un jeton admin ou
une session de navigateur est refusé.

### Ce que chaque IA a le droit de faire

Chaque jeton d'agent a un interrupteur par fonction, réglé sur la fiche du compte et appliqué dès la requête
suivante (chaque changement est noté dans le journal d'audit). Le serveur les applique à toute requête faite
avec ce jeton, par le MCP comme directement sur l'API ; une fonction désactivée disparaît de la liste des
outils et répond `403 capability_disabled` en la nommant.

| Fonction | Par défaut | |
|---|---|---|
| Lire la bibliothèque | oui | albums, pistes, artistes, genres, recherche ; corrections et lots |
| Analyser la bibliothèque | oui | doublons, mêmes enregistrements, fichiers abîmés, valeurs de tags, artistes sosies, albums en double, artistes lus dans les titres |
| Voir le dépôt et les erreurs | non | montre des chemins de fichiers |
| Proposer des corrections de tags | oui | titres, artistes, années, genres, numéros, BPM, clé |
| Proposer des changements de structure | non | fusions, albums retirés, type d'album, pistes déplacées |
| Proposer une jaquette connue | oui | une image déjà présente dans la bibliothèque |
| Jaquettes en ligne | non | le serveur se connecte à Internet (et doit l'autoriser, voir la [configuration](configuration.md)) |
| └ Seulement les sites connus | oui | iTunes, Deezer, Cover Art Archive ; décochée, une jaquette peut venir de n'importe quel site https (avertissement affiché), tous les autres contrôles restent |

Les jetons créés avant la 1.0.4 gardent ce qu'ils pouvaient faire alors (tout, sauf les jaquettes en ligne).
La réorganisation lancée depuis **Admin > Propositions** travaille avec ces mêmes droits.

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
