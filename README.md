# TiBillet pour Dolibarr

Ce module relie **TiBillet** (billetterie, caisse, adhésions) à **Dolibarr** (logiciel
de gestion et de comptabilité).

**Chaque jour** (tâche planifiée, 6 h par défaut), selon le **mode** choisi sur la page du module :

- **Articles et ventes** (par défaut pour une nouvelle installation) : Dolibarr
  recopie le **catalogue** de TiBillet (produits, catégories, TVA, compte de vente),
  puis les **ventes** des journées clôturées, en factures payées. Dolibarr tient alors
  la comptabilité avec ses propres factures.
- **Écritures comptables** : Dolibarr va chercher chez TiBillet les journées de vente
  déjà clôturées. Il range leurs écritures comptables dans son **grand livre**, **non
  validées**. Votre comptable les relit, puis les valide. Un module déjà utilisé dans ce
  mode y reste.

**Un seul mode à la fois**, jamais les deux : sinon chaque vente serait comptée deux
fois. Personne ne recopie rien à la main.

📖 **Les guides pas à pas, avec des images :**
- installer et régler le module (les deux modes) :
  [Le module TiBillet](docs/00-installer-le-module.md) ;
- mode « articles et ventes » (par défaut) :
  [Remontée des ventes et articles](docs/03-articles-et-ventes.md) ;
- mode « écritures comptables » :
  [Comptabilité (partie double)](docs/02-relier-tibillet-a-dolibarr.md).

Avant eux, lisez [Un plan comptable](docs/01-un-plan-comptable.md) :
il explique les mots « compte », « journal », « débit » et « crédit ».

## Ce que fait le module

- Une journée de TiBillet (la clôture du soir, le « Z ») devient une écriture par
  journal : une pour le bar, une pour les ventes en ligne… Ce sont les mêmes écritures
  que le fichier FEC de TiBillet.
- S'il manque un compte ou un journal dans Dolibarr, le module le crée. Il ne modifie
  jamais un compte qui existe déjà.
- Une journée arrive **en entier, ou pas du tout**. Elle n'arrive **jamais deux fois**.
- Si TiBillet refuse une journée (un compte manque dans son plan comptable), l'import
  s'arrête et le dit. La nuit suivante, il reprend au même endroit.
- Le module ne modifie rien dans TiBillet.

### En mode « articles et ventes »

- Un **produit Dolibarr par tarif** vendu en euros (« Bière — Pinte »), avec son prix
  TTC, sa TVA et son compte de vente, tous calculés par TiBillet (les mêmes que dans
  le FEC). Prix libre : le prix minimum de TiBillet.
- Les catégories sont rangées sous deux catégories mères : **Caisse TiBillet** (les
  catégories de caisse) et **Types TiBillet** (billets, adhésions…). Une catégorie que
  vous posez à la main sur un produit reste.
- **Rien n'est jamais supprimé** : un tarif archivé passe « hors vente », comme un
  tarif qui ne peut plus être recopié (plus de compte, plus une vente, plus en euros) ;
  le résumé du passage le dit. Un produit à vous (sans lien avec TiBillet) n'est jamais
  touché.
- Les recharges, virements, retours de consigne et tarifs en jetons **ne sont pas des
  ventes** : ils ne viennent pas. Un article sans compte (pas de catégorie de caisse
  reliée à un compte) n'est pas créé : le résumé du passage le dit, avec la phrase de
  TiBillet.
- Si rien n'a changé dans TiBillet depuis la veille, rien n'est réécrit, et la page garde
  le résumé du dernier passage complet.
- Les noms des catégories de type (« Article de caisse », « Réservation de billets »…)
  sont en français, quelle que soit la langue de Dolibarr.
- **Les ventes** des journées clôturées, jamais en temps réel :
  - une **facture payée par vente** TiBillet (un **avoir** pour un remboursement, relié
    à la facture d'origine si elle est dans Dolibarr), au compte de vente de chaque
    produit ;
  - le **tiers** : l'acheteur, retrouvé par son **e-mail** (créé s'il manque, l'e-mail
    comme nom), sinon le **client de passage** (comme la caisse TakePOS) ;
  - le paiement va dans la **banque Dolibarr de son compte de trésorerie** TiBillet
    (caisse, CB, Stripe, monnaie locale…) : la banque qui porte le même numéro de
    compte comptable, ou celle choisie sur la page. Le module n'en crée aucune ;
  - les **recharges** et **vidages** de carte en **virements internes** ; la recharge
    offerte, les jetons repris et les écarts d'encaissement en **paiements divers** ;
  - date des factures et des paiements = la date de la journée, comme le FEC.
  Compte par compte, le résultat est celui du FEC de la journée. Une vente arrive une
  fois, en entier ; une vente refusée arrête l'import (la page dit pourquoi) et la
  nuit suivante reprend à cette vente. Un passage importe toutes les ventes, page
  après page. Si PHP coupe trop tôt sur votre serveur, la constante
  `TIBILLET_VENTES_PAR_PASSAGE` limite le nombre de ventes par passage ; la suite
  arrive au passage suivant.
- **Ce mode ne touche pas à la comptabilité de Dolibarr** (ni plan comptable, ni
  comptes généraux, ni journaux) : le module « Comptabilité (partie double) » peut
  rester éteint. Le compte de vente voyage seulement sur le produit.
- **Ce que le module règle lui-même** dans Dolibarr, et dit : codes de paiement
  (`VAD`, `TIBL`, `TIBG`, `TIBF`, `TIB`), compte de TVA de chaque taux s'il est vide, client de
  passage. **Ce qu'il signale
  seulement** (l'import ne démarre pas) : un compte de trésorerie de TiBillet sans
  banque dans Dolibarr (aussi vérifié par la synchro du catalogue), un module inactif, les options
  `FAC_FORCE_DATE_VALIDATION`, `INVOICE_CHECK_POSTERIOR_DATE` ou
  `STOCK_CALCULATE_ON_BILL` allumées, un utilisateur sans droit de créer des factures.
  « Tester la connexion » montre les deux listes.
- Une journée importée en factures ne se **réimporte** jamais en écritures.
- La clé TiBillet doit avoir les droits **« Produits »**, **« Comptabilité
  (lecture) »** et **« Ventes »** (les ventes publient l'e-mail des acheteurs). En
  passant dans ce mode, la page active les modules Produits, Services, Catégories,
  Tiers, Factures et Banques de Dolibarr.

## Installer, en 6 étapes

1. **Dans TiBillet** : Paramètres → Clés API → ajouter une clé, avec **seulement**
   « Comptabilité (lecture) ». Copiez la clé : elle ne s'affiche qu'une fois.
2. **Dans Dolibarr (version 24 ou plus)**, pour le mode « écritures comptables »
   seulement : activez le module « Comptabilité (partie double) », chargez un plan
   comptable (par exemple FR - PCG18-ASSOC pour une association) et ouvrez un
   **exercice comptable**. Le mode « articles et ventes » n'en a pas besoin.
3. Téléchargez **`module_tibillet-<version>.zip`** sur la
   [page des versions](https://github.com/TiBillet/tibillet-dolibarr/releases).
   ⚠️ Pas « Source code (zip) » : Dolibarr ne saurait pas l'installer.
4. Dans Dolibarr : Configuration → Modules / Applications → **Déployer/Installer un
   module externe** → envoyez le zip. Puis activez le module **TiBillet**. Dolibarr
   active aussi « Travaux planifiés ».
5. Roue dentée du module : l'**adresse** de votre lieu (`https://monlieu.tibillet.coop`)
   et la **clé**. Laissez « Mode développement » décoché. Enregistrez, puis
   **Tester la connexion**.
6. **Importer maintenant**, puis comparez la balance de Dolibarr avec celle de TiBillet
   (Plan comptable → onglet Gérer) : les montants doivent être les mêmes.

⚠️ **La tâche de nuit** (« Tâche de nuit TiBillet », chaque jour à l'heure réglée sur la
page du module, dans le fuseau horaire de la personne qui l'enregistre, 6 h par
défaut, à 5 minutes près) ne lit que les journées **déjà clôturées** : TiBillet clôture
la journée tout seul deux heures après l'heure de fermeture du lieu. Réglez-la après
cette clôture, sinon la soirée n'arrive que le lendemain. Elle ne tourne
que si le serveur lance le cron de Dolibarr (`scripts/cron/cron_run_jobs.php`), avec la
clé de **Configuration → Modules → Travaux planifiés**. Chez un hébergeur, demandez-le
lui. Avec l'image Docker officielle : un conteneur avec `DOLI_CRON=1`,
`DOLI_CRON_KEY` égal à cette clé, et `DOLI_CRON_USER=firstadmin`.

## Réimporter une journée

Page du module → **Réimporter** : retire les écritures de la journée et les réécrit avec
le plan comptable actuel de TiBillet. Impossible si une ligne est déjà validée.

## Licence

GPL v3 ou plus (voir `COPYING`). Développé par la coopérative TiBillet.

---

## Pour les développeuses et développeurs

### Publier une version

1. Changer `$this->version` dans `core/modules/modTiBillet.class.php`.
2. Commiter et pousser.
3. `make release` : vérifie que tout est commité et poussé, fabrique
   `dist/module_tibillet-<version>.zip` (`git archive`, avec un dossier `tibillet/`
   dedans, comme Dolibarr l'exige), pose le tag `v<version>` et publie la release
   GitHub (outil `gh`).

`make zip` fabrique seulement le zip. Ce que `.gitattributes` marque `export-ignore`
(le script d'essai, le Makefile) n'entre pas dans le zip.

### Essai automatique (instance de TEST seulement : il écrit et efface)

```bash
php /var/www/html/custom/tibillet/scripts/essai.php             # mode « articles et ventes » seul
php /var/www/html/custom/tibillet/scripts/essai.php ecritures   # + mode « écritures »
```

Sans argument, seuls les essais du mode « articles et ventes » tournent. Les essais du
mode « écritures » (1 à 7, 12, 35, 35 bis, 37, le refus de l'essai 38) demandent
l'argument `ecritures`, la comptabilité en partie double et des clôtures chez TiBillet.

Il vérifie : l'import, l'égalité grand livre = TiBillet compte par compte, aucune ligne
validée, pas de doublon au second passage, le réimport, le « tout ou rien », le mode
« articles et ventes » (aucune écriture), la synchro du catalogue (produits =
TiBillet, `304`, prix remis, catégories manuelles gardées, collision de référence) et
l'heure de la tâche ; puis, avec une réponse de TiBillet imitée : produit passé hors
vente, réponse illisible, nom piégé (aucune balise dans le résumé), `304` qui garde le
résumé, espaces en trop, fuseau horaire ; et les ventes (essais 22 à 35) : réglages,
banques, codes de paiement, tiers par e-mail, factures payées sans lien comptable,
virements, avoirs, paiement divers, centime faux refusé, second passage, limite par
passage, réimport refusé ; enfin la comptabilité éteinte (essai 38) : les ventes
arrivent, le mode écritures refuse. **Il retire puis recrée les produits TiBillet, et laisse
des factures d'essai.**

### Avec la pile de développement (`dolibarr_stack` + Lespass de dev)

- `docker-compose.override.yml` de la pile : monter ce dossier en
  `/var/www/html/custom/tibillet` sur les deux services, `extra_hosts:
  lespass.tibillet.localhost:host-gateway`, et `DOLI_CRON_USER: firstadmin`.
- Sur une base neuve, la constante `CRON_KEY` de Dolibarr est vide : la mettre égale à
  `DOLI_CRON_KEY`, sinon le cron répond « securitykey … does not match ».
- Réglages du module : adresse `https://lespass.tibillet.localhost`, **mode
  développement coché** (nom lu dans `/etc/hosts`, certificat de dev non vérifié).

Spécification et décisions : dépôt Lespass,
`TECH_DOC/SESSIONS/COMPTABILITE/CHANTIER-06-connecteurs-comptables/`.
