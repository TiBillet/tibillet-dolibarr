# Module Dolibarr « TiBillet »

Chaque nuit, Dolibarr va chercher chez **TiBillet** les clôtures de caisse du lieu
qu'il n'a pas encore, et range leurs écritures dans son **grand livre**, **non
validées**. Le comptable relit, puis valide.

- Une clôture journalière TiBillet = une écriture par journal (un journal par point de
  vente : BAR, WEB…), déjà calculée et équilibrée par TiBillet (le même contenu que le
  FEC de TiBillet).
- Le module crée dans Dolibarr les **comptes** et les **journaux** qui manquent. Il ne
  modifie jamais un compte existant.
- Une clôture entre **en entier ou pas du tout**. Elle n'est jamais importée deux fois.
- Si TiBillet refuse une clôture (un compte manque dans le plan du lieu), l'import
  s'arrête et le dit ; la nuit suivante, il reprend à la même clôture.

Licence : GPL v3 ou plus (voir `COPYING`). Spec et décisions : dépôt Lespass,
`TECH_DOC/SESSIONS/COMPTABILITE/CHANTIER-06-connecteurs-comptables/`.

## Installer

1. Copier ce dossier dans `htdocs/custom/tibillet/` de Dolibarr (ou le monter dans le
   conteneur), ou installer le zip par « Configuration → Modules → Déployer un module
   externe ».
2. Activer le module **Comptabilité (partie double)**, charger un plan comptable, et
   ouvrir un **exercice comptable** (sans exercice ouvert, Dolibarr refuse toute
   écriture).
3. Activer le module **TiBillet**, puis ouvrir ses réglages :
   - **Adresse du lieu** : `https://monlieu.tibillet.coop` (l'adresse principale, en
     https) ;
   - **Clé d'API** : créée dans l'admin TiBillet (Clés d'API), avec **seulement** le
     droit « Comptabilité (lecture) ». Elle est chiffrée dans la base de Dolibarr et
     n'est jamais réaffichée ;
   - **Mode développement** : décoché en production.
4. « Tester la connexion », puis « Importer maintenant » (ou attendre la nuit).

La tâche planifiée « TiBilletImportDesClotures » est active dès l'installation, chaque
jour à 02:30. **Le cron système de Dolibarr doit tourner** : `cron_run_jobs.php` avec la
clé `CRON_KEY` **et** un utilisateur (`firstadmin`). Avec l'image Docker officielle :
variable `DOLI_CRON_USER=firstadmin` sur le conteneur cron, sinon aucune tâche ne tourne.
La clé `DOLI_CRON_KEY` doit aussi être celle de Configuration → Travaux planifiés
(constante `CRON_KEY`) : sinon « securitykey … does not match » et rien ne tourne.

## Réimporter une clôture

Page du module → « Réimporter » : retire les écritures de la clôture et les réécrit
avec le plan comptable actuel de TiBillet. Impossible si une ligne est déjà validée.

## Essai automatique (instance de TEST seulement : il écrit et efface)

```bash
php /var/www/html/custom/tibillet/scripts/essai.php
```

Il vérifie : l'import, l'égalité grand livre = TiBillet compte par compte, aucune ligne
validée, pas de doublon au second passage, le réimport, et le « tout ou rien ».

## En développement (pile `dolibarr_stack` + Lespass de dev)

- `docker-compose.override.yml` de la pile : monter ce dossier en
  `/var/www/html/custom/tibillet` sur les deux services, `extra_hosts:
  lespass.tibillet.localhost:host-gateway`, et `DOLI_CRON_USER: firstadmin`.
- Réglages : adresse `https://lespass.tibillet.localhost`, **mode développement coché**
  (nom lu dans `/etc/hosts`, certificat de dev non vérifié).
