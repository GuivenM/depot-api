# Système de gestion de dépôt de boissons (SaaS) — Document de suivi

_Mis à jour le 20 novembre 2026. À garder à la racine du dépôt (`docs/PROJET.md`) et à tenir à jour._

## 1. Vision

Un **SaaS multi-dépôts** pour digitaliser la gestion des dépôts de boissons au Bénin, aujourd'hui tenus avec des cahiers (stock, ventes, caisse, crédits clients, casiers consignés, fournisseurs).

Principe directeur : ce n'est **pas** une simple appli de stock, mais un système de gestion complet où chaque mouvement (stock, argent, dette) est tracé dans un **journal** (ledger), jamais écrasé.

Cible technique : API Laravel + front React (PWA, mobile-first, **hors ligne**), hébergé d'abord sur mutualisé (Namecheap), puis sur VPS quand il y aura assez de clients.

## 2. Stack et décisions d'architecture

| Sujet | Décision |
|---|---|
| API | Laravel (11+) + Sanctum 4 (tokens) |
| Multi-tenant | **Une base MySQL par dépôt**, package `stancl/tenancy` v3 |
| Base centrale | SQLite en local (MySQL en prod possible) : table `tenants` + plus tard abonnements/super-admin |
| Identification du tenant | En-tête HTTP **`X-Tenant: <id du tenant>`** (middleware `InitializeTenancyByRequestData`) |
| Front (à faire) | React + Vite, PWA, IndexedDB pour le hors ligne |
| PDF (à faire) | dompdf |
| Paiement (phase 2) | Agrégateur MoMo (FedaPay / KkiaPay) |
| Hébergement | Mutualisé Namecheap aujourd'hui (bases illimitées), **VPS plus tard** |
| Monnaie | FCFA, **entiers** (pas de décimales) |
| Stock | Stocké en **bouteilles** (plus petite unité), converti en casiers à l'affichage |

### Règles d'architecture à ne jamais casser

1. **Le stock ne se modifie jamais directement.** Tout passe par `StockService::move()` (journal `stock_movements` + cache `products.stock_bottles`, dans une transaction avec verrou).
2. **Les dettes ne se modifient jamais directement.** Dette client : `CreditService` (journal `customer_ledger` + cache `customers.balance`). Dette fournisseur : `SupplierService` (journal `supplier_ledger` + cache `suppliers.balance`).
3. **Les prix viennent toujours du serveur**, jamais du client.
4. **Idempotence par `uuid`** sur les ventes et les paiements (le client génère l'uuid ; un renvoi ne duplique rien).
5. **Ordre des verrous** : caisse → client ou fournisseur → produits (triés par id) → types de casiers (triés par id) → soldes de casiers. Évite les deadlocks.
6. **Les casiers ne se modifient jamais directement.** Tout passe par `ContainerService` (journal `container_movements` + caches `container_types.empty_stock` et `container_holdings.quantity`).
7. **Les identifiants de base d'un tenant ne vont jamais dans le `.env`.** Ils sont portés par le tenant (voir §4).
8. Une opération d'argent/stock/casiers = **une transaction** (vente + sorties de stock + dette + casiers ensemble, ou rien).
9. **On ne supprime jamais, on annule par contre-écriture** : mouvements inverses tracés (stock, dettes, casiers), `status = voided` sur la vente/l'achat, `voided_at` sur les règlements.

## 3. Rôles

| Rôle | Peut |
|---|---|
| `gerant` | Tout, y compris limites de crédit, abandon de créance, clôture d'une caisse d'autrui, dépassement de limite, **règlements fournisseurs** et dettes fournisseurs |
| `caissier`, `vendeur` | Ouvrir sa caisse, vendre, encaisser des remboursements, gérer des clients (sans toucher aux limites) |
| `magasinier` | Produits, réceptions, casse, ajustements de stock, fournisseurs et achats (**sans pouvoir payer**) |

Les rôles sont une simple colonne `users.role`, vérifiée par le middleware `EnsureRole` (`EnsureRole::class.':gerant,caissier'`). Un compte désactivé (`users.active = false`) est refusé à la connexion **et** sur toute requête (middleware `EnsureUserActive`, alias `user.active`), même avec un token encore valide.

## 4. Multi-tenant : comment ça marche ici

### Deux niveaux de base
- **Centrale** : `tenants` (id = code d'entreprise utilisé dans `X-Tenant`, `name`, `status`, `plan`, `data` JSON).
- **Tenant** (une par dépôt) : `users`, `personal_access_tokens`, produits, stock, ventes, caisse, clients…

### Identifiants de la base d'un tenant
Stockés dans `tenants.data` via les clés `tenancy_db_name`, `tenancy_db_username`, `tenancy_db_password` :

```php
Tenant::create([
    'id' => 'chez-paul', 'name' => 'Dépôt Chez Paul',
    'tenancy_db_name' => 'compte_chezpaul',
    'tenancy_db_username' => 'compte_paul',
    'tenancy_db_password' => '...',
]);
```

### Provisioning (mutualisé aujourd'hui, VPS demain)
Interface `TenantDatabaseProvisioner`, deux implémentations, choisies par `TENANT_PROVISIONER` (`config/depot.php`) :
- `manual` (défaut) : la base est **créée à la main dans cPanel** ; le provisioner vérifie seulement la connexion.
- `auto` : Tenancy crée lui-même base et utilisateur (nécessite un VPS / droits `CREATE DATABASE`).

Onboarding d'un client (mode manuel) :
1. Créer la base **vide** et l'utilisateur MySQL (tous droits sur cette base) dans cPanel.
2. Créer le tenant (ci-dessus, id = code d'entreprise).
3. `php artisan depot:provision <tenant> --email=gerant@... --name="Nom"` → vérifie la connexion, migre, crée le gérant (mot de passe initial affiché une seule fois), passe le statut à `active`.

Statuts : `pending_db` → `provisioning` → `active` (ou `failed`, `suspended`). Un tenant non `active` reçoit un 403.

### Réglages Tenancy indispensables (appris en route)
- `config/tenancy.php` : `tenant_model` = `App\Models\Tenant`, `template_tenant_connection` = **`tenant_template`**.
- Connexion `tenant_template` (dans `config/database.php`) : MySQL pointant sur `information_schema`, hôte/port/utilisateur via `TENANT_DB_HOST/PORT/USERNAME/PASSWORD`. Elle sert de modèle et aux vérifications d'existence. **Ne pas utiliser la connexion `mysql` par défaut** (base `laravel` inexistante).
- `.env` : `DB_CONNECTION=sqlite` (base centrale). **Aucun** `DB_DATABASE/USERNAME/PASSWORD` de tenant dedans, sinon un `php artisan migrate` crée les tables par défaut dans la base d'un client.
- `TenancyServiceProvider` : pipelines `TenantCreated` et `TenantDeleted` **vides** (pas de création/suppression automatique de base). Garder les listeners `BootstrapTenancy` / `RevertToCentralContext`.
- Bootstrappers : `Database`, `Filesystem`, `Queue`. **`CacheTenancyBootstrapper` retiré** (il exige un cache avec tags, absent du mutualisé).
- `makeTenancyMiddlewareHighestPriority()` doit rester dans `TenancyServiceProvider::boot()` : sans lui, `auth:sanctum` s'exécute avant l'identification du tenant.
- Sanctum 4 : pas de `ignoreMigrations()`. La migration `personal_access_tokens` vit dans `database/migrations/tenant`. Supprimer celle publiée par `install:api` dans `database/migrations`.
- `User` : trait `HasApiTokens`.

### Migrations
- Centrales : `database/migrations` → `php artisan migrate`.
- Tenant : `database/migrations/tenant` → `php artisan tenants:migrate` (tous) ou `--tenants=<id>`.
- ⚠️ `tenants:migrate-fresh` **supprime toutes les tables** du tenant : jamais sur un vrai client.

## 5. Modèle de données (tenant)

| Table | Rôle |
|---|---|
| `users` | Comptes du dépôt (`role`, `active`, `must_change_password` : vrai après création ou réinitialisation par le gérant) |
| `personal_access_tokens` | Tokens Sanctum (par tenant) |
| `products` | Nom, marque, format, `bottles_per_crate`, `price_crate`, `price_bottle`, `cost_crate`, `stock_bottles` (cache), `alert_threshold`, `active`, `container_type_id` (null = non consigné) |
| `stock_movements` | **Journal du stock** : `type` (entree/sortie/casse/ajustement/inventaire), `quantity` signée en bouteilles, référence polymorphe, `user_id` |
| `cash_sessions` | Une caisse par vendeur : fond, `expected_cash`, `counted_cash`, `difference`, `opened_at/closed_at`, `closed_by` |
| `expenses` | Dépenses rattachées à une session |
| `sales` / `sale_items` | Ventes (`uuid` unique, `payment_method` cash/momo/credit, `customer_id` nullable, `status`) et lignes (quantité, bouteilles équivalentes, prix serveur) |
| `customers` | Nom, téléphone, type (bar/restaurant/revendeur/particulier), `credit_limit`, `balance` (cache), `active` |
| `customer_ledger` | **Journal des dettes** : `amount` signé (+ dette, − remboursement), `balance_after`, référence polymorphe |
| `payments` | Remboursements de dette (`uuid` unique, `method` cash/momo, rattaché à une caisse, `sale_id` si acompte) |
| `container_types` | Type de casier (casier + ses bouteilles) : `deposit_amount` (valeur de consigne), `empty_stock` (casiers vides au dépôt, cache) |
| `container_holdings` | Casiers qu'un tiers doit rendre (`party_type` customer/supplier, `party_id`, `quantity`, cache) |
| `container_movements` | **Journal des casiers** : `type` (sale_out, return_in, purchase_in, from_supplier, to_supplier, loss, adjustment), `party_delta`, `depot_delta`, référence polymorphe |
| `suppliers` | Fournisseur : `balance` = ce que le dépôt lui doit (cache, via `SupplierService`) |
| `supplier_ledger` | **Journal des dettes fournisseurs** : `amount` signé (+ on doit plus, − on a payé), `balance_after` |
| `purchases` / `purchase_items` | Réceptions de marchandise (`uuid` unique, `invoice_ref`, total) et lignes (quantité, bouteilles équivalentes, `unit_cost`) |
| `supplier_payments` | Règlements fournisseurs (`uuid` unique, `method` cash/momo/bank ; le cash est rattaché à la caisse et sort du tiroir) |
| `audit_logs` | **Journal d'audit** (immuable) : `user_id` (auteur), `action` (ex. `product.updated`), `subject_type`/`subject_id`, `details` JSON (avant/après ou contexte), `note`, `ip`, `created_at` (pas de `updated_at`) |
| `inventories` / `inventory_lines` | Inventaire physique : statut (`open`/`validated`/`cancelled`), lignes produit (en bouteilles) ou casiers vides, `expected` (stock système au moment du comptage), `counted`, `difference` ; à la validation : `lines_with_gap`, `shortage_value`, `surplus_value` |
| _(colonnes d'annulation)_ | `sales` et `purchases` : `status = voided`, `voided_at`, `voided_by`, `void_reason` ; `payments` et `supplier_payments` : `voided_at` (un règlement annulé est marqué, jamais supprimé, et exclu de la caisse) |

Caisse théorique = **fond + ventes cash + remboursements de dette en cash − dépenses − règlements fournisseurs en cash**. MoMo et ventes à crédit sont suivis à part et n'entrent pas dans le tiroir.

## 6. API (préfixe `/api/v1`, en-tête `X-Tenant` obligatoire)

Auth : `POST login` → token ; ensuite `Authorization: Bearer <token>`.

| Méthode | Route | Rôles | Rôle de la route |
|---|---|---|---|
| POST | `login` | — | Connexion (email, password) |
| GET | `me` / POST `logout` | connecté | Profil / déconnexion |
| POST | `me/password` | connecté | Changer son mot de passe (`current_password`, `password`, `password_confirmation`) ; déconnecte les autres appareils |
| GET | `users` | gérant | Personnel (filtres `role`, `active`, `q`) |
| POST | `users` | gérant | Créer un compte (`name`, `email`, `role`, `password` optionnel ; sinon mot de passe provisoire généré, renvoyé **une seule fois**) |
| GET/PUT | `users/{id}` | gérant | Consulter / modifier nom, email, rôle |
| POST | `users/{id}/deactivate` / `activate` | gérant | Désactiver (coupe tous ses tokens) / réactiver |
| POST | `users/{id}/reset-password` | gérant | Nouveau mot de passe provisoire (renvoyé une seule fois), tokens révoqués |
| GET | `products` | connecté | Liste avec `stock_crates` et `stock_loose_bottles` |
| POST/PUT | `products`, `products/{id}` | gérant, magasinier | Créer / modifier |
| GET | `products/{id}/movements` | gérant, magasinier | Historique du stock |
| POST | `stock/receipts` | gérant, magasinier | Réception (casiers + bouteilles) |
| POST | `stock/adjustments` | gérant, magasinier | Casse ou ajustement (note obligatoire pour ajustement) |
| GET | `cash/current` | gérant, caissier, vendeur | Caisse ouverte + résumé |
| POST | `cash/open` / `cash/close` | idem | Ouvrir (fond) / clôturer (montant compté) |
| POST | `cash/expenses` | idem | Dépense |
| POST/GET | `sales` | idem | Vente idempotente / liste |
| GET/POST/PUT | `customers`, `customers/{id}` | idem | Clients (limite et activation : gérant seul) |
| GET | `customers/{id}/ledger` | idem | Historique des dettes |
| POST | `customers/{id}/payments` | idem | Remboursement de dette (idempotent) |
| GET | `receivables` | gérant, caissier | Créances : total, dernier paiement, dépassement de limite |
| POST | `customers/{id}/adjustments` | gérant | Abandon / correction de créance |
| GET | `containers` | connecté | Types de casiers : vides au dépôt, dus par les clients, valeur de consigne |
| POST/PUT | `containers`, `containers/{id}` | gérant | Créer / modifier un type de casier |
| GET | `containers/{id}/holders` | gérant, caissier | Quels clients doivent des casiers de ce type |
| GET | `containers/{id}/movements` | gérant, magasinier | Journal des casiers |
| POST | `containers/depot-movements` | gérant, magasinier | Vides du dépôt : `from_supplier`, `to_supplier`, `loss`, `adjustment` (inventaire initial inclus) |
| GET | `customers/{id}/containers` | gérant, caissier, vendeur, magasinier | Casiers que ce client doit rendre |
| POST | `customers/{id}/containers/returns` | idem | Le client rapporte des vides |
| POST | `customers/{id}/containers/adjustments` | gérant | Correction / abandon de casiers dus |
| GET/POST/PUT | `suppliers`, `suppliers/{id}` | gérant, magasinier | Fournisseurs (activation : gérant seul) |
| GET | `suppliers/{id}/containers` | gérant, magasinier | Casiers que le dépôt doit à ce fournisseur (négatif = il nous en doit) |
| GET/POST | `purchases` | gérant, magasinier | Réceptions de marchandise (idempotent sur `uuid`) |
| GET | `suppliers/{id}/ledger` | gérant | Historique des dettes fournisseur |
| POST | `suppliers/{id}/payments` | gérant | Règlement fournisseur (cash/momo/bank, idempotent, plafonné à la dette) |
| POST | `suppliers/{id}/adjustments` | gérant | Correction de dette fournisseur |
| GET | `payables` | gérant | Dettes fournisseurs : total, par fournisseur, dernier paiement |
| POST | `sales/{id}/void` | gérant (toute vente), caissier/vendeur (les leurs) | Annuler une vente (`reason` obligatoire), caisse encore ouverte |
| POST | `purchases/{id}/void` | gérant | Annuler une réception (`reason` obligatoire) |
| GET | `reports/overview` | gérant | Ventes par mode de paiement, marge estimée, dépenses, encaissements, achats + situation actuelle |
| GET | `reports/daily` | gérant | Une ligne par jour (zéros inclus), max 92 jours |
| GET | `reports/top-products` | gérant | Produits les plus vendus (`limit`) |
| GET | `reports/cash-sessions` | gérant | Caisses clôturées, écarts, bilan par vendeur |
| GET | `reports/stock-alerts` | gérant, magasinier | Produits en rupture ou sous leur seuil |
| GET | `audit-logs` | gérant | Journal d'audit, du plus récent au plus ancien (50 par page). Filtres : `action` (`user.*` accepté), `user_id`, `subject_type`, `subject_id`, `from` + `to` (`YYYY-MM-DD`, heure du dépôt) |
| GET/POST | `inventories`, `inventories/{id}` | gérant, magasinier | Lister / ouvrir un inventaire (une ligne par produit actif et par type de casier) / le consulter avec sa progression |
| POST | `inventories/{id}/counts` | gérant, magasinier | Enregistrer des comptages (`crates` et/ou `bottles`, ou `quantity` pour les casiers) ; recompter remplace |
| POST | `inventories/{id}/cancel` | gérant, magasinier | Annuler un inventaire ouvert (aucun effet sur le stock) |
| POST | `inventories/{id}/validate` | gérant | Appliquer les écarts au stock et aux casiers vides (tout ou rien) |

### Personnel
Le gérant gère les comptes ; **aucun compte n'est jamais supprimé** (il est référencé par les ventes, caisses et journaux) : on le désactive. Toute la logique passe par `UserService`.

- **Mot de passe provisoire** : créé ou réinitialisé par le gérant, affiché une seule fois ; le compte porte `must_change_password = true` (renvoyé par `login` et `me`) jusqu'à ce que l'utilisateur change son mot de passe via `me/password`.
- **Désactivation** : révoque tous les tokens du compte (déconnexion de tous ses appareils). Refusée (409) si le compte a une **caisse ouverte** : la clôturer d'abord. Même règle pour un changement de rôle.
- **Garde-fous** : on ne peut ni désactiver son propre compte, ni changer son propre rôle, ni réinitialiser son propre mot de passe (il utilise `me/password`). Il doit toujours rester au moins un gérant actif (409).
- Les emails sont enregistrés en minuscules, uniques **dans le dépôt** (le même email peut exister dans deux dépôts).

### Sécurité
- **Limitation de débit** (réponse 429 en JSON avec `Retry-After`) : `login` est limité à **5 tentatives/minute par dépôt + email + IP** et **30/minute par IP** (tous emails confondus) ; `me/password` à **5/minute par utilisateur**. Réglable par `DEPOT_LOGIN_ATTEMPTS`, `DEPOT_LOGIN_ATTEMPTS_IP`, `DEPOT_PASSWORD_ATTEMPTS`. Les compteurs vivent dans le cache `file` (`RATE_LIMITER_STORE`).
- **Expiration des tokens** : 7 jours par défaut (`SANCTUM_EXPIRATION`, en minutes ; `0` = jamais). `login` renvoie `expires_at` ; passé ce délai l'API répond 401 et le front redemande le mot de passe. Les ventes faites hors ligne gardent leur `uuid` : elles se synchronisent après la reconnexion. Nettoyage quotidien des tokens expirés (`sanctum:prune-expired`) planifié pour chaque dépôt : il demande le cron `php artisan schedule:run` ; sans lui, les tokens expirés restent refusés mais s'accumulent.
- **Dépôt inconnu ou `X-Tenant` absent** : 404 JSON, **même message** dans les deux cas (on ne révèle pas quels dépôts existent).
- **Mot de passe de la base d'un dépôt chiffré** dans `tenants.data` (cast `encrypted` de Laravel, clé = `APP_KEY`). Le nom de base et l'utilisateur restent en clair. Un mot de passe encore en clair reste lisible (il n'y a pas de coupure de service) ; `php artisan depot:encrypt-credentials` le chiffre définitivement (idempotent, à lancer une fois après la mise à jour). ⚠️ **Sauvegarder `APP_KEY` hors du serveur** : sans elle, les mots de passe des bases sont perdus.

### Audit
Le journal répond à « qui a changé quoi, quand ». Il s'ajoute aux journaux métier (stock, dettes, casiers), qui tracent les **mouvements** ; l'audit trace les **décisions et modifications sensibles**. Toute écriture passe par `AuditService`, **dans la transaction de l'action** : si l'action échoue, aucune trace ; si elle réussit, la trace existe. Une entrée n'est jamais modifiée ni supprimée (le modèle `AuditLog` refuse `update` et `delete`). Jamais de mot de passe ni de secret dans `details`.

| Action | Déclencheur | `details` |
|---|---|---|
| `product.created` / `product.updated` | création ; modification de nom, prix, coût, conditionnement, seuil, activation, consigne | `values` (création) ou `{champ: {from, to}}` (uniquement les champs réellement changés) |
| `customer.created` / `customer.updated` | limite de crédit ou activation (gérant) | idem |
| `supplier.updated` | activation | `{active: {from, to}}` |
| `container_type.created` / `.updated` | nom, valeur de consigne, activation | idem |
| `customer.debt_adjusted` | abandon / correction de créance | `amount`, `balance_before`, `balance_after` + `note` |
| `supplier.debt_adjusted` | correction de dette fournisseur | idem |
| `stock.breakage` / `stock.adjusted` | casse / ajustement manuel (les réceptions ne sont pas tracées : elles sont dans le journal du stock) | `bottles` (signé), `stock_after` + `note` |
| `container.holding_adjusted` | correction de casiers dus par un client | `delta`, `quantity_before`, `quantity_after` |
| `container.depot_loss` / `container.depot_adjusted` | perte / correction des vides du dépôt | `delta`, `empty_stock_before`, `empty_stock_after` |
| `sale.limit_overridden` | le gérant vend au-delà de la limite de crédit (seulement si elle est réellement dépassée) | `balance_before`, `new_debt`, `credit_limit` |
| `sale.voided` / `purchase.voided` | annulation | `total`, tiers concerné + `note` = raison |
| `inventory.validated` | validation d'un inventaire | `lines_with_gap`, `shortage_value`, `surplus_value` |
| `cash.closed` | clôture **avec écart**, ou d'une caisse par quelqu'un d'autre que son titulaire | `owner_id`, `expected_cash`, `counted_cash`, `difference` |
| `user.created` / `user.updated` | création ; changement de nom, email ou rôle | `role`, `email` ou `{champ: {from, to}}` |
| `user.activated` / `user.deactivated` / `user.password_reset` / `user.password_changed` | gestion des comptes (`password_changed` : l'auteur est l'intéressé) | — |

Une modification qui ne change aucun champ surveillé ne laisse aucune trace.

### Consignes (casiers)
Un type de casier = un casier avec ses bouteilles. On lie un produit à un type via `products.container_type_id`.

À la vente (`POST sales`), chaque casier plein vendu **d'un produit consigné, vendu en casier** est compté. Le champ optionnel `empties_returned: [{container_type_id, quantity}]` indique les vides rendus pendant la vente. Exemple : 10 casiers vendus, 8 vides rendus → le client doit désormais 2 casiers, et les 8 vides entrent dans le stock de vides du dépôt.

- **Client identifié** : le solde de ce qu'il doit évolue (jamais négatif).
- **Client anonyme** : l'échange doit être équilibré (autant rendus que vendus), sinon la vente est refusée (« sélectionnez un client »).
- Le stock de vides du dépôt se renseigne par `containers/depot-movements` (par exemple `adjustment` pour l'inventaire initial).

### Achats et fournisseurs
`POST purchases` enregistre une **réception** : fournisseur, lignes (`unit_cost` par casier ou bouteille), `invoice_ref` optionnel. Dans une seule transaction : entrée en stock (journal), dette fournisseur, mise à jour du **dernier coût** du produit (`cost_crate`), et casiers.

- **Casiers** : les casiers pleins reçus de produits consignés sont dus au fournisseur ; `empties_returned` indique les vides rendus à la brasserie (ils sortent du stock de vides du dépôt, refusé s'il n'y en a pas assez). Le solde envers un fournisseur peut devenir négatif (c'est lui qui nous doit des casiers).
- **Paiement** : `paid_now` + `paid_method` (cash, momo, bank), **gérant seulement**. Un paiement cash exige la caisse ouverte du gérant et sort du tiroir. Le magasinier reçoit la marchandise à crédit, le gérant règle ensuite (`suppliers/{id}/payments`).

### Inventaire physique
Le magasinier compte, le gérant valide. À l'ouverture, une ligne est créée par produit actif et par type de casier. Quand une ligne est comptée, le système **mémorise le stock qu'il croyait avoir à cet instant** (`expected`) et calcule l'écart (`compté − attendu`). La validation applique **l'écart** (mouvement `inventaire` dans le journal du stock, ou des casiers vides), pas la valeur absolue : une vente faite entre le comptage et la validation reste valable (attendu 120, compté 114, vendu 24 depuis → stock final 96 − 6 = 90).

- **Partiel autorisé** : les lignes non comptées sont ignorées ; au moins une ligne comptée est exigée.
- **Tout ou rien** : si un écart rendrait un stock ou des vides négatifs (ex. beaucoup de ventes depuis le comptage), la validation est refusée (422) et l'inventaire reste ouvert ; recompter la ligne concernée.
- **Valorisation** : manquants et excédents des produits au dernier coût (`shortage_value`, `surplus_value`). Les casiers ne sont pas valorisés.
- Un seul inventaire ouvert à la fois. Le dernier inventaire validé apparaît dans `reports/overview` (`snapshot.last_inventory`).

### Rapports et tableau de bord
Endpoints **en lecture seule** : l'API calcule les totaux (une seule règle de calcul, cohérente avec la clôture de caisse, avec les droits), le front React n'a qu'à les afficher. Paramètres `from` et `to` au format `YYYY-MM-DD`, **heure du dépôt** (`Africa/Porto-Novo` par défaut, surchargeable par `config('depot.timezone')`) ; par défaut : aujourd'hui ; maximum 366 jours (92 pour `daily`).

`GET reports/overview` renvoie `period`, `sales` (nombre, CA, par mode `cash`/`momo`/`credit`, annulées), `margin` (`estimated_cost`, `estimated_margin`, `uncosted_revenue`), `expenses`, `collections` (remboursements clients, règlements fournisseurs), `purchases` et `snapshot` (créances, dettes fournisseurs, casiers, valeur du stock au coût, produits sous le seuil).

**Marge estimée** = CA des lignes dont le produit a un coût connu − quantité × **dernier coût d'achat** (`cost_crate`). Les produits sans coût sont comptés à part (`uncosted_revenue`) pour ne pas fausser la marge. Ce n'est pas une marge comptable (pas de coût moyen pondéré, pas d'historique des coûts).

### Annulations
`POST sales/{id}/void` et `POST purchases/{id}/void` (raison obligatoire) rejouent **l'inverse** des mouvements d'origine, dans une transaction :

- **Vente** : le stock revient ; une vente à crédit annule sa dette et son acompte (l'acompte est rendu au client, donc il sort de la caisse) ; les casiers (dus par le client, vides rentrés au dépôt) sont restitués. La vente sort de la caisse théorique. Possible **tant que sa caisse est ouverte**.
- **Achat** (gérant) : la marchandise sort du stock, la dette et les règlements sont annulés (un règlement cash revient en caisse), les casiers dus à la brasserie sont restitués.
- **Refusé (422) avec retour arrière complet** si l'annulation rendrait un stock, une dette ou un solde de casiers **négatif** : marchandise d'un achat déjà vendue, client qui a déjà remboursé davantage que le reste dû, casiers déjà rendus depuis, vides déjà renvoyés. Dans ce cas, corriger d'abord par un ajustement. Une opération déjà annulée renvoie 409.

### Vente à crédit
`POST sales` avec `payment_method: "credit"`, `customer_id`, et optionnellement `paid_now` + `paid_method` (acompte). Refusée si la dette dépasse `credit_limit`, sauf `override_limit: true` par un gérant. Un paiement ne peut pas dépasser la dette.

## 7. Commandes utiles

```bash
php artisan migrate                              # base centrale
php artisan tenants:migrate [--tenants=<id>]     # bases des dépôts (à relancer après chaque nouvelle migration tenant, ex. personnel)
php artisan depot:provision <tenant> --email=... # prépare + migre + crée le gérant
php artisan depot:demo <tenant>                  # 3 produits de démo avec stock
php artisan depot:encrypt-credentials            # chiffre les mots de passe de base encore en clair (une fois, après mise à jour)
```

## 8. Fait

- [x] Architecture, stack, choix multi-tenant (une base par dépôt)
- [x] Socle Laravel + Tenancy, provisioning manuel/auto interchangeable, commande `depot:provision`
- [x] Auth Sanctum par tenant, rôles, blocage des tenants inactifs
- [x] Produits + stock en journal (`StockService`), réception, casse, ajustement
- [x] Caisse par sessions, dépenses, clôture avec écart, ventes idempotentes (cash / MoMo)
- [x] Clients, crédit, limites, remboursements, créances, ledger des dettes _(patch crédits)_
- [x] Consignes : types de casiers, vides du dépôt, casiers dus par client, retours à la vente ou après, journal des casiers _(patch consignes)_
- [x] Inventaire physique : comptage produits et casiers vides, écarts relatifs, validation tout ou rien, valorisation, dernier inventaire dans le tableau de bord _(patch inventaire)_
- [x] Rapports et tableau de bord côté API : vue d'ensemble, courbe journalière, top produits, alertes de stock, écarts de caisse _(patch rapports)_
- [x] Annulation de vente et d'achat (contre-écritures sur stock, dettes, casiers et caisse) _(patch annulations)_
- [x] Achats et fournisseurs : réceptions liées au stock, dettes fournisseurs (journal), règlements, casiers dus à la brasserie, dernier coût _(patch achats)_
- [x] Personnel : création, modification, désactivation/réactivation, réinitialisation et changement de mot de passe, garde-fous (dernier gérant, caisse ouverte), refus des comptes désactivés même avec token _(patch personnel)_
- [x] Audit : journal immuable `audit_logs` écrit dans la transaction de l'action (prix, limites, ajustements, casse, annulations, dépassements de limite, inventaires, écarts de caisse, comptes) et endpoint de consultation filtrable _(patch audit)_
- [x] Sécurité : limitation de débit (login, mot de passe), expiration des tokens, 404 JSON uniforme pour un dépôt inconnu, mot de passe de base chiffré + commande de migration _(patch sécurité)_
- [x] Suite de tests d'API (47 tests : isolation entre tenants, stock, ventes/caisse, crédit, consignes) _(patch tests : 47/47 verts à la première exécution, voir §15)_

## 9. Reste à faire (ordre suggéré)

**Cœur métier**
1. ~~**Personnel**~~ : fait (voir §8).
2. ~~**Audit**~~ : fait (voir §6 « Audit »). : historique des modifications sensibles (prix, limites, ajustements) — `audit_logs`.
3. **Commandes fournisseurs** : bon de commande avant réception, réceptions partielles, rapprochement avec la facture.
4. **Compléments consignes** : facturation d'un casier non rendu (conversion en dette d'argent via `deposit_amount`), consigne payée par un client anonyme, bouteilles vendues à l'unité.
5. **Corrections après clôture** : annulation tardive d'une vente, remboursement client, avoir (écriture dans la caisse courante, sans rouvrir la caisse clôturée).
6. **Rapports PDF et exports** : bilan jour/semaine/mois, ticket de caisse, facture, export Excel ; comparaison de périodes ; rapport par vendeur ; historique des inventaires.

**Produit**
7. **Front React PWA** (mobile-first) : vente rapide au comptoir, caisse, stock, clients, casiers, achats, annulations, inventaire, tableau de bord (consomme `reports/*`).
8. **Hors ligne** : cache IndexedDB (catalogue, clients), file de synchronisation avec uuid, résolution de conflits.
9. **SaaS** : super-admin, plans et abonnements, facturation des dépôts, suspension automatique, inscription/onboarding.
10. **Paiement MoMo** intégré, notifications WhatsApp/SMS pour relancer les dettes.

**Industrialisation**
11. **Compléter les tests** : concurrence (deux ventes simultanées), `ManualProvisioner` / `depot:provision` (nécessitent MySQL), hors ligne, et un test d'isolation à chaque nouvel endpoint. Brancher la suite en CI.
12. **Sauvegardes** : `mysqldump` par tenant (cron) avec stockage hors serveur, export à la demande.
13. **Suivi des migrations** par tenant (échecs isolés) et exécution par lots.
14. **Sécurité** : ~~limitation de débit sur `login`, expiration des tokens, chiffrement des identifiants de base, 404 JSON pour un tenant inconnu~~ fait (§6 « Sécurité »). Reste : en-têtes CORS/HTTPS stricts, limitation de débit avant l'identification du dépôt (requêtes sur des dépôts inconnus), verrouillage d'un compte après trop d'échecs répartis sur plusieurs IP.
15. **Migration vers VPS** (voir §11) ; vérifier la performance des rapports sur de gros volumes (index, éventuellement tables d'agrégats).

## 10. Limites connues

- **Hors ligne non fini** : une vente synchronisée après la clôture de la caisse est refusée. Il faudra empêcher la clôture tant que des ventes locales ne sont pas envoyées, ou rattacher la vente à la session active à `sold_at`.
- **Une caisse ouverte par utilisateur** : le même compte sur deux appareils partage la même caisse.
- **Pas d'annulation de vente** ni de remboursement client pour l'instant.
- **Consignes** : seuls les casiers vendus **en casier** (`unit: crate`) de produits consignés sont suivis ; les bouteilles vendues à l'unité et leurs vides ne le sont pas. Un casier est suivi avec ses bouteilles (pas de suivi séparé).
- **Consignes** : aucune facturation automatique d'un casier non rendu (`deposit_amount` ne sert qu'à valoriser). Un client anonyme doit rendre autant de casiers qu'il en achète.
- **Consignes** : pas de retour anticipé côté client (solde jamais négatif) ; le stock de vides du dépôt doit être initialisé à la main (`containers/depot-movements`).
- **Inventaire** : un seul inventaire ouvert à la fois ; pas de verrouillage des ventes pendant le comptage (c'est pourquoi la validation applique l'écart et non la valeur absolue) ; pas d'annulation d'un inventaire déjà validé (corriger par un ajustement) ; les casiers vides sont comptés mais pas valorisés ; pas de comptage par emplacement ni par lot.
- **Rapports** : marge = estimation avec le dernier coût d'achat ; la courbe journalière est agrégée en PHP (limitée à 92 jours) ; pas de comparaison de périodes ni d'export. Les dates sont interprétées en heure du Bénin, le stockage reste en UTC.
- **Annulations** : uniquement tant que la caisse de la vente (ou des règlements cash de l'achat) est ouverte ; après clôture, pas de correction automatique (voir §9). Annulation totale seulement (pas de retour partiel d'une ligne). Le dernier coût d'achat du produit n'est pas rétabli après l'annulation d'un achat. Pas d'annulation de dépense ni de remboursement de dette.
- **Achats** : une réception = un achat (pas de bon de commande ni de réception partielle). Le coût du produit est le **dernier coût d'achat** (pas de coût moyen pondéré). Dette fournisseur jamais négative (pas d'avance) ; un règlement ne peut pas dépasser la dette. Seul le gérant règle un fournisseur ; les paiements MoMo/bank ne sont suivis que dans le journal (pas de rapprochement bancaire). Pas d'annulation d'achat.
- **Dette ≥ 0** : pas d'avance ni d'avoir client ; un paiement ne peut pas dépasser la dette.
- **Personnel** : `must_change_password` est un **indicateur** pour le front, l'API n'empêche pas d'utiliser le reste de l'API avec un mot de passe provisoire. Pas de mot de passe oublié en libre-service (le gérant réinitialise). Le garde-fou « dernier gérant » n'est atteignable que par des requêtes simultanées de deux gérants (un gérant ne peut pas se toucher lui-même). Pas de suppression de compte, par choix. Les actions sur les comptes ne sont pas encore journalisées (voir audit, §9).
- **Audit** : immuable côté application (modèle `AuditLog`), mais pas côté base : quelqu'un qui a accès à MySQL peut modifier la table (pas de signature ni de chaînage des entrées). Pas de rotation ni d'archivage (la table grossit avec l'activité sensible, pas avec chaque vente). Les ventes, paiements et mouvements courants ne sont pas dans l'audit : ils ont leurs propres journaux. Pas d'audit des connexions (ni échecs de login). Les champs `from`/`to` reprennent la valeur brute de la base. Un compte supprimé ne peut pas exister (on désactive), donc `user` n'est jamais nul en pratique.
- **Vendeurs et caissiers voient tous les clients** et leurs dettes (pas de cloisonnement par vendeur).
- **Pas de TVA ni de facturation normalisée.** Seule la logique de caisse est couverte.
- **Provisioning manuel** : chaque nouveau dépôt demande la création d'une base dans cPanel.
- **Pas de rapports ni de PDF** pour l'instant ; le dashboard n'existe pas.
- **Tests** : la suite passe (§15) mais ne couvre ni la concurrence, ni le provisioning MySQL, ni le hors ligne.
- **Cache Tenancy désactivé** (mutualisé) : à rétablir avec Redis sur VPS.
- **Sécurité** : la limite de connexion par dépôt + email + IP n'arrête pas une attaque répartie sur beaucoup d'adresses IP contre un seul compte (un verrouillage de compte serait possible mais permettrait de bloquer un gérant à volonté). Les mots de passe de base sont chiffrés avec `APP_KEY` : quiconque a l'accès au serveur voit aussi `.env`, donc ce chiffrement protège surtout les sauvegardes et les exports de la base centrale. Le nom de base et l'utilisateur MySQL restent en clair.
- Limites de requêtes d'un mutualisé : migrations de nombreux tenants à lancer en CLI ou par lots (timeouts PHP).

## 11. Migration vers VPS (checklist)

1. Installer PHP/MySQL/Supervisor (Forge, Ploi ou Coolify), mettre le front statique à part si besoin.
2. Passer `TENANT_PROVISIONER=auto` ; donner à l'utilisateur MySQL de l'app le droit `CREATE` + `GRANT` (ou utiliser un utilisateur dédié au provisioning).
3. Restaurer les bases des dépôts existants (dump par tenant) et vérifier `tenancy_db_*`.
4. Remplacer le cron de queue par un worker supervisé ; réactiver `CacheTenancyBootstrapper` avec Redis.
5. Configurer sauvegardes par tenant et surveillance.

## 12. Pièges rencontrés (pour ne pas les refaire)

| Symptôme | Cause | Solution |
|---|---|---|
| `Target [TenantDatabaseProvisioner] is not instantiable` | `DepotServiceProvider` non enregistré | Ajouter dans `bootstrap/providers.php` |
| `Sanctum::ignoreMigrations()` introuvable | Méthode supprimée en Sanctum 4 | Ne pas l'appeler |
| `Table 'users' already exists` pendant `depot:provision` | `.env` pointait sur la base d'un tenant et un `migrate` y a créé les tables | `.env` = base centrale uniquement |
| `Database connection [tenant] not configured` | Tenancy non initialisé (listeners/bootstrappers retirés) | Garder `BootstrapTenancy`, `DatabaseTenancyBootstrapper` |
| `Unknown database 'laravel'` | Connexion modèle `mysql` sans base | Connexion `tenant_template` sur `information_schema` |
| `php artisan test` n'affiche **rien** (même pour un seul fichier) | Une classe de test déclare une méthode d'aide qui porte le nom d'une méthode de `TestCase` (ex. `count()`, déjà `public` dans PHPUnit) : erreur fatale au chargement, avalée par l'affichage de `artisan test` | Renommer la méthode (`submitCounts`, `countLines`…). Pour voir l'erreur, lancer `vendor\bin\phpunit <fichier>` |
| Erreur « table cache introuvable » sur `login` | Le limiteur utilisait le cache `database`, dont la connexion par défaut est devenue celle du dépôt | `RATE_LIMITER_STORE=file` (`config/cache.php`, clé `limiter`) |
| `auth:sanctum` cherche les tokens dans la mauvaise base | Middleware Tenancy pas prioritaire | `makeTenancyMiddlewareHighestPriority()` |

## 13. Environnement local (référence)

- PHP 8.5 en local ; **vérifier la version PHP du forfait Namecheap** avant déploiement.
- Base centrale SQLite ; MySQL local pour les bases des dépôts (Laragon/XAMPP).
- Variables `.env` propres à ce projet : `DB_CONNECTION=sqlite`, `TENANT_DB_HOST/PORT/USERNAME/PASSWORD`, `TENANT_PROVISIONER=manual`, `RATE_LIMITER_STORE=file`, `SANCTUM_EXPIRATION`, `DEPOT_LOGIN_ATTEMPTS`, `DEPOT_LOGIN_ATTEMPTS_IP`, `DEPOT_PASSWORD_ATTEMPTS`. `phpunit.xml` règle les limites très haut pour que la suite ne se bloque pas elle-même.

## 14. Livrables déjà produits

- `depot-socle.patch` : Tenancy, provisioning, auth, produits de base, stock (`StockService`).
- `depot-ventes-caisse.patch` : caisse, ventes, produits/stock (API), démo.
- `depot-credits.patch` : clients, crédit, remboursements, créances.
- `depot-inventaire-fix.patch` : renomme l'aide `count()` de `InventoryTest` (conflit avec `TestCase::count()`).
- `depot-inventaire.patch` : inventaire physique (`InventoryService`, `InventoryController`), `last_inventory` dans le tableau de bord, `InventoryTest` (16 tests).
- `README.md` : présentation et mise en route du projet (à la racine du dépôt).
- `depot-rapports.patch` : endpoints de rapports (`ReportService`, `ReportController`), `ReportTest` (14 tests).
- `depot-annulations.patch` : annulation de vente et d'achat (colonnes `voided_*`, contre-écritures), `VoidTest` (17 tests).
- `depot-achats.patch` : fournisseurs, achats, dettes fournisseurs, casiers dus à la brasserie, `PurchaseTest` (modifie `CashSession`, `ContainerService`, `ContainerController`, `routes/depot/operations.php`).
- `depot-personnel.patch` : gestion du personnel (`UserService`, `UserController`, `EnsureUserActive`, migration `must_change_password`, `me/password`), `PersonnelTest` (modifie `User`, `AuthController`, `DepotServiceProvider`, `routes/depot.php`, `routes/depot/operations.php`).
- `depot-audit.patch` : journal d'audit (`AuditService`, `AuditLog`, `AuditLogController`, migration `audit_logs`), branché dans les contrôleurs produits/clients/fournisseurs/casiers/stock et dans `CreditService`, `SupplierService`, `ContainerService`, `SaleService`, `PurchaseService`, `InventoryService`, `CashService`, `UserService` ; `AuditTest`. **À appliquer après `depot-personnel.patch`.**
- `depot-securite.patch` : limitation de débit, expiration des tokens, 404 uniforme, chiffrement du mot de passe de base (`Tenant`, commande `depot:encrypt-credentials`, `bootstrap/app.php`, `DepotServiceProvider`, `config/cache.php`, `config/sanctum.php`, `config/depot.php`, `phpunit.xml`, `routes/console.php`), `SecurityTest`. **À appliquer après `depot-audit.patch`, puis lancer `php artisan depot:encrypt-credentials`.**
- `depot-tests-fix.patch` : correctif des tests (requête « fraîche », base de test copiée).
- `depot-tests.patch` : suite de tests (`tests/TenantTestCase.php` + 5 fichiers dans `tests/Feature`).
- `depot-consignes.patch` : types de casiers, vides du dépôt, casiers dus par client, intégration à la vente (modifie `SaleService`, `SaleController`, `Product`, `ProductController`, `routes/depot/operations.php`).

## 15. Tests

```bash
php artisan test                 # toute la suite
php artisan test --filter=SalesTest
```

**Principe** : tests d'API de bout en bout (en-tête `X-Tenant`, vrai login Sanctum, vraies routes, vrais rôles). La base centrale est celle de phpunit (SQLite en mémoire) ; chaque dépôt de test a **sa propre base SQLite** (`database/testing_*.sqlite`), créée via `AutoProvisioner` puis migrée avec les vraies migrations tenant. Pas besoin de MySQL. Les fichiers sont supprimés en fin de test.

**Prérequis** : `phpunit.xml` avec `DB_CONNECTION=sqlite` et `DB_DATABASE=:memory:` (défaut Laravel 11), extension `pdo_sqlite`. Conseillé : ajouter `database/testing_*.sqlite` au `.gitignore`.

| Fichier | Couvre |
|---|---|
| `TenantIsolationTest` | requête sans/avec mauvais tenant, token d'un dépôt refusé dans un autre (et inversement), données qui ne fuient pas, même email dans deux dépôts, dépôt suspendu, utilisateur désactivé, rôles |
| `StockTest` | réception, casse, ajustements, stock jamais négatif, journal = cache |
| `SalesTest` | prix serveur, idempotence, rollback complet si stock insuffisant, caisse obligatoire, une caisse par vendeur, clôture et écart |
| `CreditTest` | limite, acompte, remboursements (cash/MoMo, idempotence, plafond), impact sur la caisse, abandon de créance, créances |
| `InventoryTest` | ouverture (produits actifs + casiers), un seul inventaire ouvert, comptages et recomptage, écarts relatifs (ventes après comptage), lignes non comptées ignorées, tout ou rien (stock ou vides négatifs), valorisation, cycle de vie, droits, tableau de bord, isolation |
| `ReportTest` | CA par mode de paiement, marge estimée, produit sans coût, ventes annulées exclues, dépenses/encaissements/achats, situation actuelle, filtre et validation de période, courbe jour par jour, top produits, alertes de stock, écarts de caisse, droits, isolation |
| `VoidTest` | annulation de vente (cash, crédit + acompte, casiers), d'achat (stock, dette, règlement cash, casiers) ; refus et retour arrière complet (déjà vendu, déjà remboursé, déjà rendu, caisse clôturée), double annulation, rôles |
| `PurchaseTest` | réception (stock, dette, dernier coût), idempotence, paiement à l'achat réservé au gérant, cash qui sort du tiroir, règlements (idempotents, plafonnés), casiers dus à la brasserie, rollback complet, rôles, isolation |
| `PersonnelTest` | droits (gérant seul), création avec mot de passe provisoire ou fourni, email unique (casse ignorée) et rôle valide, filtres, modification, pas de changement de son propre rôle, refus avec caisse ouverte, désactivation (tokens coupés, connexion refusée, réactivation), compte désactivé refusé même avec token, réinitialisation, changement de mot de passe (autres appareils déconnectés), isolation |
| `SecurityTest` | 404 identique pour un `X-Tenant` absent ou inconnu, limite de login (par dépôt + email + IP, puis par IP seule), 429 avec `Retry-After`, comptes et dépôts voisins épargnés, limite du changement de mot de passe, token expiré refusé, mot de passe de base chiffré au repos et lisible par l'application, mot de passe en clair existant lisible puis chiffré par la commande (idempotente) |
| `AuditTest` | traces des prix/limites/consignes, absence de trace sans changement réel ou si l'action est refusée, abandon de créance et dette fournisseur (avant/après), casse et ajustements, vides et casiers dus, dépassement de limite seulement quand il a lieu, annulations avec raison, inventaire, clôture de caisse (écart ou tiers), comptes sans aucun mot de passe, immuabilité, droits, filtres (action, utilisateur, sujet, période), isolation |
| `ContainerTest` | 10 vendus / 8 rendus → 2 dus, client anonyme équilibré, retours, rollback, produits non consignés, rôles |

**Règle d'équipe** : tout nouvel endpoint reçoit au moins un test d'isolation (tenant B ne voit pas les données de A) et un test de rôle.

**Piège** : ne jamais nommer une méthode d'aide de test comme une méthode de `TestCase` (`count`, `name`, `status`, `run`, `get`, `post`, `json`, `call`…). PHP lève une erreur fatale au chargement et `php artisan test` n'affiche alors aucune sortie ; lancer `vendor\bin\phpunit` pour la voir.

**Non couvert** : concurrence, `ManualProvisioner` et `depot:provision` (demandent une vraie base MySQL), hors ligne, performances.

**Résultat de la première exécution (15 nov.)** : 47 tests de la suite passent. Trois points à noter :
- `tests/Feature/ExampleTest.php` (test par défaut de Laravel, qui appelle `/`) échoue sur une API : le supprimer.
- `test_depot_suspendu_est_refuse` échouait parce que, dans un même test, le tenant restait initialisé entre deux requêtes (ancien statut en mémoire). En production chaque requête résout le tenant à nouveau. Les tests simulent maintenant une requête fraîche (`tenancy()->end()` avant chaque appel).
- Durée de 8 min : chaque dépôt de test rejouait toutes les migrations. Une base tenant migrée est maintenant construite une seule fois par exécution puis copiée (`depot-tests-fix.patch`).
