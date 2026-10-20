# Mode « articles et ventes » sans comptabilité / "Items and sales" mode without accounting

**Date :** 2026-10-20
**Migration :** Non

## Resume / Summary
**Quoi / What :** le module TiBillet ne dépend plus de « Comptabilité (partie double) ».
En mode « articles et ventes », il ne touche plus à la comptabilité de Dolibarr : pas de
création de comptes dans le plan, pas de comptes généraux (411, 401, TVA, 58), pas de
journal `BQ`, pas de contrôle des comptes sur les modes de paiement, pas de lien
comptable sur les lignes de facture. Le compte de vente voyage seulement sur le produit
(`accountancy_code_sell`). La page du module active la comptabilité quand on choisit le
mode « écritures », et l'import des clôtures refuse de tourner sans elle.
/ The module no longer depends on double-entry accounting. The "items and sales" mode
never touches Dolibarr's accounting; the sales account only lives on the product. The
"entries" mode turns accounting on, and refuses to run without it.

**Pourquoi / Why :** les lieux qui utilisent ce mode ne se servent pas de la
comptabilité de Dolibarr. L'import ne démarrait pas sur un Dolibarr sans plan comptable
complet (« le compte général ACCOUNTING_ACCOUNT_CUSTOMER est vide… »).
/ Venues using this mode do not use Dolibarr's accounting; the import was blocked on a
Dolibarr without a complete chart of accounts.

### Fichiers modifies / Modified files
| Fichier / File | Changement / Change |
|---|---|
| `core/modules/modTiBillet.class.php` | `modAccounting` retiré de `depends` ; version 0.3.0 |
| `Makefile` | `make release` passe à la version suivante (0.3.0 → 0.4.0) si la version du descripteur a déjà son tag |
| `admin/setup.php` | Mode « écritures » choisi → active `modAccounting` |
| `class/importtibillet.class.php` | `importerLesClotures` : refus si la comptabilité est éteinte |
| `class/catalogue.class.php` | Plus de `creerLesComptesManquants` |
| `class/ventes.class.php` | Plus de `creerLesComptesManquants` ; ligne de facture sans `fk_code_ventilation` ni refus « compte absent du plan » |
| `class/reglages.class.php` | `preparer()` : plus de comptes généraux, journal `BQ`, comptes sur modes de paiement. Banque créée sans journal, libellé « TiBillet <compte> ». Retrait de `poserUnCompteGeneral`, `idDuJournalDeBanque`, `idDuCompteComptable` |
| `langs/*/tibillet.lang` | 5 clés retirées, `TiBilletComptabiliteInactive` ajoutée |
| `scripts/essai.php` | Essais 22, 22 ter, 23, 27 adaptés ; essai 38 ajouté |
| `README.md`, `docs/00-…`, `docs/03-…` | La comptabilité n'est demandée que pour le mode écritures |

---

## Comment tester (a la main) / Manual test

### Test 1 — Dolibarr sans comptabilité, mode « articles et ventes »
1. Sur un Dolibarr 24 de test : désactiver « Comptabilité (partie double) ».
2. Mettre à jour le module TiBillet (zip), puis le désactiver / réactiver.
3. Vérifier que l'activation passe, sans demander la comptabilité.
4. Page du module, mode « articles et ventes » : **Tester la connexion**.
   Attendu : « Dolibarr est prêt à recevoir les ventes. » (aucun message sur 411 / 401).
5. **Synchroniser le catalogue maintenant** : les produits arrivent, chacun avec son
   compte de vente (fiche produit → onglet Comptabilité).
6. **Importer les ventes maintenant** : factures payées, banques « TiBillet 530000 »…
   créées, aucun compte ajouté au plan.

### Test 2 — Mode « écritures »
1. Toujours sans comptabilité : choisir le mode « écritures », **Enregistrer**.
   Attendu : le module « Comptabilité (partie double) » est activé par la page.
2. Désactiver à la main la comptabilité, puis **Importer maintenant**.
   Attendu : le message `TiBilletComptabiliteInactive`, rien n'est écrit.

### Test 3 — Dolibarr avec comptabilité, mode « articles et ventes »
1. Importer des ventes : les lignes de facture ne sont pas liées
   (Comptabilité → Liaison → Ventes clients : « à lier »).
2. **Lier automatiquement** : chaque ligne avec produit prend le compte du produit.
   Une ligne libre (tarif sans produit) reste à lier à la main.

### Verifs automatiques
- `php /var/www/html/custom/tibillet/scripts/essai.php` dans le conteneur Dolibarr de
  test : tout doit être OK, dont l'essai 38 (comptabilité éteinte en mémoire).
