# Un mode de paiement par type de monnaie / One payment mode per currency type

**Date :** 2026-10-21
**Migration :** Non

## Resume / Summary
**Quoi / What :** en mode « articles et ventes », chaque type de monnaie TiBillet a son
mode de paiement Dolibarr, créé par le module au premier besoin : monnaie locale `LE` →
`TIBL`, monnaie cadeau `LG` → `TIBG`, FED `SF` → `TIBF`. Le FED ne passe plus par `VAD`
(paiement en ligne). Tout autre moyen garde `TIB`.
/ Each TiBillet currency type gets its own Dolibarr payment mode (TIBL, TIBG, TIBF);
FED is no longer VAD; any other method keeps TIB.

**Pourquoi / Why :** distinguer, dans Dolibarr, les dépenses en monnaie locale et en FED
(la FED est rechargée chez TiBillet, pas chez le lieu), et ne plus confondre la FED avec
les paiements par carte en ligne.
/ Tell local currency and FED spending apart, and stop mixing FED with online card
payments.

### Fichiers modifies / Modified files
| Fichier / File | Changement / Change |
|---|---|
| `class/reglages.class.php` | `CODE_PAR_MOYEN` : `LE`, `LG`, `SF` ; libellé de chaque code créé (`LIBELLE_PAR_CODE_CREE`) |
| `class/importtibillet.class.php` | Commentaire : Dolibarr retire aussi les zéros du compte d'un produit (sauf `ACCOUNTING_MANAGE_ZERO`) |
| `langs/*/tibillet.lang` | 3 libellés de codes |
| `scripts/essai.php` | Essai 24 : un code par monnaie, `TIB` pour un autre moyen ; essais 23, 28, 34 suivent `ACCOUNTING_MANAGE_ZERO` |
| `README.md`, `docs/03-…` | Liste des codes |

---

## Comment tester (a la main) / Manual test

### Test 1 — ventes en monnaie locale et en FED
1. Dans TiBillet, une journée avec une dépense en monnaie locale et une en FED (la FED a
   besoin d'un compte de monnaie côté TiBillet, sinon la clôture est refusée en 409).
2. Dans Dolibarr, **Importer les ventes maintenant**.
3. Attendu : le résumé dit « Mode de paiement TIBL créé », « … TIBF créé ». Sur les
   factures, le paiement en monnaie locale est en « Monnaie locale TiBillet (€) », celui
   en FED en « FED TiBillet (€) », pas en « Paiement en ligne ».

### Verifs DB
- `SELECT code, libelle, active FROM llx_c_paiement WHERE code LIKE 'TIB%';`
