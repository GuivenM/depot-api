# Système de gestion de dépôt de boissons (SaaS) — Document de suivi

_Mis à jour le 13 novembre 2026. À garder à la racine du dépôt (`docs/PROJET.md`) et à tenir à jour._

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
2. **La dette client ne se modifie jamais directement.** Tout passe par `CreditService` (journal `customer_ledger` + cache `customers.balance`).
3. **Les prix viennent toujours du serveur**, jamais du client.
4. **Idempotence par `uuid`** sur les ventes et les paiements (le client génère l'uuid ; un renvoi ne duplique rien).
5. **Ordre des verrous** : caisse → client → produits (produits triés par id). Évite les deadlocks.
6. **Les identifiants de base d'un tenant ne vont jamais dans le `.env`.** Ils sont portés par le tenant (voir §4).
7. Une opération d'argent/stock = **une transaction** (vente + sorties de stock + écriture de dette ensemble, ou rien).

## 3. Rôles

| Rôle | Peut |
|---|---|
| `gerant` | Tout, y compris limites de crédit, abandon de créance, clôture d'une caisse d'autrui, dépassement de limite |
| `caissier`, `vendeur` | Ouvrir sa caisse, vendre, encaisser des remboursements, gérer des clients (sans toucher aux limites) |
| `magasinier` | Produits, réceptions, casse, ajustements de stock |

Les rôles sont une simple colonne `users.role`, vérifiée par le middleware `EnsureRole` (`EnsureRole::class.':gerant,caissier'`).

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
| `users` | Comptes du dépôt (`role`, `active`) |
| `personal_access_tokens` | Tokens Sanctum (par tenant) |
| `products` | Nom, marque, format, `bottles_per_crate`, `price_crate`, `price_bottle`, `cost_crate`, `stock_bottles` (cache), `alert_threshold`, `active` |
| `stock_movements` | **Journal du stock** : `type` (entree/sortie/casse/ajustement/inventaire), `quantity` signée en bouteilles, référence polymorphe, `user_id` |
| `cash_sessions` | Une caisse par vendeur : fond, `expected_cash`, `counted_cash`, `difference`, `opened_at/closed_at`, `closed_by` |
| `expenses` | Dépenses rattachées à une session |
| `sales` / `sale_items` | Ventes (`uuid` unique, `payment_method` cash/momo/credit, `customer_id` nullable, `status`) et lignes (quantité, bouteilles équivalentes, prix serveur) |
| `customers` | Nom, téléphone, type (bar/restaurant/revendeur/particulier), `credit_limit`, `balance` (cache), `active` |
| `customer_ledger` | **Journal des dettes** : `amount` signé (+ dette, − remboursement), `balance_after`, référence polymorphe |
| `payments` | Remboursements de dette (`uuid` unique, `method` cash/momo, rattaché à une caisse, `sale_id` si acompte) |

Caisse théorique = **fond + ventes cash + remboursements de dette en cash − dépenses**. MoMo et ventes à crédit sont suivis à part et n'entrent pas dans le tiroir.

## 6. API (préfixe `/api/v1`, en-tête `X-Tenant` obligatoire)

Auth : `POST login` → token ; ensuite `Authorization: Bearer <token>`.

| Méthode | Route | Rôles | Rôle de la route |
|---|---|---|---|
| POST | `login` | — | Connexion (email, password) |
| GET | `me` / POST `logout` | connecté | Profil / déconnexion |
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

### Vente à crédit
`POST sales` avec `payment_method: "credit"`, `customer_id`, et optionnellement `paid_now` + `paid_method` (acompte). Refusée si la dette dépasse `credit_limit`, sauf `override_limit: true` par un gérant. Un paiement ne peut pas dépasser la dette.

## 7. Commandes utiles

```bash
php artisan migrate                              # base centrale
php artisan tenants:migrate [--tenants=<id>]     # bases des dépôts
php artisan depot:provision <tenant> --email=... # prépare + migre + crée le gérant
php artisan depot:demo <tenant>                  # 3 produits de démo avec stock
```

## 8. Fait

- [x] Architecture, stack, choix multi-tenant (une base par dépôt)
- [x] Socle Laravel + Tenancy, provisioning manuel/auto interchangeable, commande `depot:provision`
- [x] Auth Sanctum par tenant, rôles, blocage des tenants inactifs
- [x] Produits + stock en journal (`StockService`), réception, casse, ajustement
- [x] Caisse par sessions, dépenses, clôture avec écart, ventes idempotentes (cash / MoMo)
- [x] Clients, crédit, limites, remboursements, créances, ledger des dettes _(patch crédits)_

## 9. Reste à faire (ordre suggéré)

**Cœur métier**
1. **Consignes** : casiers et bouteilles vides par client et par fournisseur (`container_types`, `container_movements`) ; suivre « 10 casiers pleins, 8 vides rendus → 2 dus ».
2. **Achats et fournisseurs** : commandes, réceptions liées au stock, factures, **dettes fournisseurs** (ledger symétrique aux clients).
3. **Annulation de vente** : restitution du stock, correction de caisse et de dette, avec trace.
4. **Inventaire physique** : comptage, écarts, mouvement `inventaire`.
5. **Personnel** : CRUD utilisateurs, désactivation, changement/réinitialisation de mot de passe.
6. **Audit** : historique des modifications sensibles (prix, limites, ajustements) — `audit_logs`.
7. **Dashboard et rapports** : CA du jour, dépenses, bénéfice estimé, alertes de stock, bilans jour/semaine/mois, PDF (factures, tickets, clôture de caisse).

**Produit**
8. **Front React PWA** (mobile-first) : vente rapide au comptoir, caisse, stock, clients.
9. **Hors ligne** : cache IndexedDB (catalogue, clients), file de synchronisation avec uuid, résolution de conflits.
10. **SaaS** : super-admin, plans et abonnements, facturation des dépôts, suspension automatique, inscription/onboarding.
11. **Paiement MoMo** intégré, notifications WhatsApp/SMS pour relancer les dettes.

**Industrialisation**
12. **Tests automatisés**, dont l'**isolation entre tenants** (un tenant ne voit jamais les données d'un autre) sur chaque endpoint.
13. **Sauvegardes** : `mysqldump` par tenant (cron) avec stockage hors serveur, export à la demande.
14. **Suivi des migrations** par tenant (échecs isolés) et exécution par lots.
15. **Sécurité** : limitation de débit sur `login`, expiration des tokens, chiffrement des identifiants de base dans `tenants.data`, en-têtes CORS/HTTPS stricts.
16. **Migration vers VPS** (voir §11).

## 10. Limites connues

- **Hors ligne non fini** : une vente synchronisée après la clôture de la caisse est refusée. Il faudra empêcher la clôture tant que des ventes locales ne sont pas envoyées, ou rattacher la vente à la session active à `sold_at`.
- **Une caisse ouverte par utilisateur** : le même compte sur deux appareils partage la même caisse.
- **Pas d'annulation de vente** ni de remboursement client pour l'instant.
- **Dette ≥ 0** : pas d'avance ni d'avoir client ; un paiement ne peut pas dépasser la dette.
- **Vendeurs et caissiers voient tous les clients** et leurs dettes (pas de cloisonnement par vendeur).
- **Pas de TVA ni de facturation normalisée.** Seule la logique de caisse est couverte.
- **Provisioning manuel** : chaque nouveau dépôt demande la création d'une base dans cPanel.
- **Pas de rapports ni de PDF** pour l'instant ; le dashboard n'existe pas.
- **Identifiants de base en clair** dans `tenants.data` (à chiffrer avant la production).
- **Code non testé automatiquement** : seuls des contrôles de syntaxe et des essais manuels ont été faits. Ne pas mettre en production avant les tests d'isolation.
- **Cache Tenancy désactivé** (mutualisé) : à rétablir avec Redis sur VPS.
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
| `auth:sanctum` cherche les tokens dans la mauvaise base | Middleware Tenancy pas prioritaire | `makeTenancyMiddlewareHighestPriority()` |

## 13. Environnement local (référence)

- PHP 8.5 en local ; **vérifier la version PHP du forfait Namecheap** avant déploiement.
- Base centrale SQLite ; MySQL local pour les bases des dépôts (Laragon/XAMPP).
- Variables `.env` propres à ce projet : `DB_CONNECTION=sqlite`, `TENANT_DB_HOST/PORT/USERNAME/PASSWORD`, `TENANT_PROVISIONER=manual`.

## 14. Livrables déjà produits

- `depot-socle.patch` : Tenancy, provisioning, auth, produits de base, stock (`StockService`).
- `depot-ventes-caisse.patch` : caisse, ventes, produits/stock (API), démo.
- `depot-credits.patch` : clients, crédit, remboursements, créances.
