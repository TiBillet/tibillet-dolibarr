# Banques reliées par leur numéro, jamais créées / Banks linked by number, never created

**Date :** 2026-10-23
**Migration :** Non

## Resume / Summary
**Quoi / What :** en mode « articles et ventes », le module ne crée plus de banque. Un
compte de trésorerie TiBillet va sur la banque choisie sur la page du module, sinon sur
la banque ouverte qui porte le même numéro de compte comptable (les zéros de droite ne
comptent pas : 530000 = 5300 = 53). Un compte sans banque est une erreur qui le nomme.
La vérification tourne sur « Tester la connexion », « Synchroniser le catalogue »,
« Importer les ventes » et la tâche de nuit. Le tableau « Banques et client de passage »
liste tous les comptes de trésorerie de TiBillet, avec leur libellé.
/ The module no longer creates banks: a treasury account goes to the chosen bank, or to
the open bank with the same account number. A missing bank blocks the three buttons.
The table lists every TiBillet treasury account with its label.

**Pourquoi / Why :** des lieux avaient déjà leurs banques sous un autre nom ; le module
en créait une de plus (« TiBillet 419100 »), ou l'import s'arrêtait sur un libellé déjà
pris.
/ Venues already had their banks under another name; the module created duplicates.

**Dépend de / Depends on :** Lespass, champ `treasury` de `/api/v2/accounts/`
(CHANGELOG Lespass du 2026-10-23). Sans lui, aucun compte n'est vu comme de trésorerie.

### Fichiers modifies / Modified files
| Fichier / File | Changement / Change |
|---|---|
| `class/reglages.class.php` | `comptesDeTresorerie()`, `verifierLesBanques()` (appelée par `preparer()`) ; `banquePourLeCompte` ne crée plus, compare sans les zéros de droite, écarte une banque de caisse pour un compte autre que 53x |
| `class/catalogue.class.php` | Vérification des banques avant le catalogue |
| `class/ventes.class.php` | Appel de `banquePourLeCompte` sans `$user` |
| `admin/setup.php` | Tableau : tous les comptes de trésorerie, leur libellé, « — à relier — » ; ce choix efface le lien |
| `langs/*/tibillet.lang` | `TiBilletCompteSansBanque`, `TiBilletBanquesManquantesCatalogue`, `TiBilletLibelleTiBillet`, `TiBilletBanqueARelier` ; `TiBilletBanqueCreee` retirée |
| `scripts/essai.php` | Essais 23 adaptés ; 23 ter (compte sans banque) ; 23 quater (vérification) |
| `README.md`, `docs/03-…` | Banques retrouvées, jamais créées |

---

## Comment tester (a la main) / Manual test

### Test 1 — un compte sans banque bloque les trois boutons
1. Dans TiBillet, un moyen de paiement relié à un compte qui n'a pas de banque dans
   Dolibarr (en dev : 471000, 511200, 512000).
2. **Tester la connexion** : avertissement « le compte TiBillet 511200 « Chèques à
   encaisser » n'a pas de banque… ».
3. **Synchroniser le catalogue** et **Importer les ventes** : même message, rien n'est
   écrit, aucune banque créée.

### Test 2 — relier
1. Section **Banques et client de passage** : chaque compte avec son libellé ; les comptes
   sans banque sont sur « — à relier — » avec un avertissement.
2. Choisir une banque pour chacun, **Enregistrer**, puis **Tester la connexion** :
   « Dolibarr est prêt à recevoir les ventes. »
3. Variante : créer la banque dans Dolibarr avec le numéro dans « Compte comptable »
   (ex. 5112 ou 511200) : elle est retenue sans rien choisir.

### Verifs DB
- `SELECT rowid, label, account_number FROM llx_bank_account;` : pas de nouvelle banque
  après un import.
- `SELECT name, value FROM llx_const WHERE name LIKE 'TIBILLET_BANQUE_%';`
