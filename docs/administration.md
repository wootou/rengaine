# Administration

The **Admin** menu appears for admin accounts. Most tasks can also be done with the command line:
`sudo -u www-data php server/bin/rengaine aide` lists the commands.

## Accounts

People request an account from the sign-in page (the direct link `https://<site>/?demande` opens the
form; **Admin > Server** shows it). Admins receive a mail, then approve or refuse in **Admin > Accounts**.
Requests can be closed, and the number of pending requests per network is limited.

Roles: **user**, **admin**, and **agent** (an AI working on the library: it can read the catalogue and
propose corrections, nothing else; it has no password, only tokens created by an admin). Suspend,
reactivate, change roles, see devices and revoke them from the account page.

## Library

- **Genres**, **Tags**: create genres and sub-genres, map tag values to genres, merge genres.
- **Collections**: hand-made groups of albums, shown in Explore.
- **Corrections**: everything changed by hand or by an AI, reversible.
- **Proposals**: batches proposed by an AI agent; apply, reject, or revert after applying.
- **Duplicates**: MP3 copies of lossless tracks (moved to a recycle folder on request, restorable with
  `rengaine corbeille:restaurer`), same recordings found by fingerprint, damaged files.
- **Jobs**: scans, analysis, inbox state, errors per file; start a scan or an analysis.
- **Backgrounds**: images shown behind the player, one at random per visit.

## Server

**Admin > Server**: server name, site language (French or English), account requests, the shared remote
control switch, and the AI model configured in `[ia]` (if any).

## Logs and audit

- **Admin > Log**: application logs (API, command line, security), filterable.
- The audit trail records sign-ins, account changes, corrections, jobs and settings, with who and from
  which IP. IPs are erased after `conservation_ip_jours`.
- `securite.log` (failed sign-ins, invalid tokens) is meant for fail2ban.
