# Factures marquées « tibillet » / Invoices tagged "tibillet"

**Date :** 2026-10-23
**Migration :** Non

## Resume / Summary
**Quoi / What :** chaque facture et chaque avoir créés par l'import des ventes portent
`module_source = 'tibillet'`, comme TakePOS y met `takepos`. Dans la liste des factures,
la colonne « Module POS (PDV) » affiche `tibillet` et permet de filtrer les ventes
TiBillet.
/ Every invoice and credit note created by the sales import has
`module_source = 'tibillet'`, like TakePOS sets `takepos`.

**Pourquoi / Why :** distinguer les factures TiBillet des autres dans Dolibarr. La
colonne « Source » du module de facture électronique (`einvoicing`) affiche le point
d'accès configuré (ex. SUPERPDP), pas la provenance de la vente : on ne doit pas y
toucher.
/ Tell TiBillet invoices apart. The e-invoicing "Source" column shows the access point
(e.g. SUPERPDP), not where the sale comes from.

### Fichiers modifies / Modified files
| Fichier / File | Changement / Change |
|---|---|
| `class/ventes.class.php` | `creerLaFacture` : `$facture->module_source = 'tibillet'` |
| `scripts/essai.php` | Essais 27 et 30 : la facture et l'avoir portent `tibillet` |

---

## Comment tester (a la main) / Manual test

1. Importer les ventes.
2. Liste des factures : la colonne « Module POS (PDV) » n'apparaît que si TakePOS ou
   Caisse est actif, ou avec la constante `INVOICE_SHOW_POS` = 1. L'ajouter par le
   sélecteur de colonnes : `tibillet` sur les nouvelles factures et les avoirs.

### Verifs DB
- `SELECT ref, module_source FROM llx_facture ORDER BY rowid DESC LIMIT 5;`
- Les factures importées avant ce changement restent à `NULL`.
