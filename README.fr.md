# Rengaine

[English](README.md) | **Français**

**Votre bibliothèque musicale, chez vous.** Rengaine est un serveur de musique et un lecteur web pour les albums que vous possédez : indiquez-lui un dossier (ou un stockage S3) de fichiers MP3 et FLAC, et écoutez depuis n'importe quel navigateur ou depuis l'appli Android, à la maison comme en déplacement.

🌐 **Site du projet et documentation : [rengaine.org](https://rengaine.org)**

**Statut :** version 1.0.2 (voir [CHANGELOG.md](CHANGELOG.md)). **Appli Android :** code et APK sur [rengaine-android](https://github.com/wootou/rengaine-android/releases), version de test 1.0.0-rc.1.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://rengaine.org/images/fr/albums-dark.webp">
  <img alt="Bibliothèque d'albums dans Rengaine" src="https://rengaine.org/images/fr/albums-light.webp">
</picture>

## Fonctionnalités

- **Vos fichiers, intacts.** Les tags sont lus, jamais réécrits ; les nouveaux albums se rangent tout seuls.
- **Un son qui s'enchaîne.** Volume égalisé, silences sautés, fondus calés sur le tempo.
- **Une écoute sans fin.** Par genre, ambiance ou décennie, avec des morceaux qui s'enchaînent bien.
- **Toute la famille.** Plusieurs comptes, synchronisés entre le lecteur web et l'appli Android.
- **Télécommande partagée.** Un lien suffit pour qu'un invité pilote la musique depuis son téléphone.
- **Une bibliothèque bien rangée.** Genres, corrections réversibles, doublons détectés.
- **Assisté par l'IA, jamais piloté par elle.** Une IA propose des corrections, vous décidez.
- **Respectueux par conception.** Aucun traceur, vos données restent chez vous.

## Aperçu


<table>
  <tr>
    <td>
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://rengaine.org/images/fr/album-dark.webp">
        <img alt="Page d'un album" src="https://rengaine.org/images/fr/album-light.webp">
      </picture>
    </td>
    <td>
      <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://rengaine.org/images/fr/explore-dark.webp">
        <img alt="Explorer : ambiances et genres" src="https://rengaine.org/images/fr/explore-light.webp">
      </picture>
    </td>
  </tr>
</table>


## Prérequis

Un serveur Linux avec PHP 8 (PHP-FPM), MariaDB, Apache (ou Nginx) et FFmpeg. En option : Essentia (tempo, tonalité), Chromaprint (empreintes audio), Mercure (télécommande en temps réel), un relais SMTP. Ni Composer ni Docker requis.

## Démarrage

Lisez le guide d'installation, puis configurez le serveur et ajoutez votre musique. Toute la documentation est dans `docs/` ; l'API est décrite par un contrat OpenAPI dans `api/openapi.yaml`.

## Contribuer

Les signalements de bugs et les pull requests sont les bienvenus : voir CONTRIBUTING.md. Pour signaler une faille de sécurité en privé, voir SECURITY.md.

## Licence

MIT. Les composants tiers et les programmes séparés que Rengaine peut utiliser sont listés dans THIRD\_PARTY\_NOTICES.md.
