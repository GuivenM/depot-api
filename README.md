# Dépôt de boissons — API de gestion (SaaS multi-dépôts)

API Laravel pour digitaliser la gestion des dépôts de boissons au Bénin : stock, ventes, caisse, crédits clients, consignes de casiers, achats et dettes fournisseurs, annulations, inventaire physique et rapports. Chaque dépôt (« tenant ») a **sa propre base de données**.

> Le front (React PWA, mobile-first, hors ligne) est un projet séparé. Ce dépôt ne contient que l'API.
> Documentation détaillée (architecture, règles, routes, limites, feuille de route) : [`docs/PROJET.md`](docs/PROJET.md).

## Fonctionnalités

| Domaine | Contenu |
|---|---|
| **Multi-tenant** | Une base MySQL par dépôt (`stancl/tenancy`), identification par l'en-tête `X-Tenant`, statuts de dépôt (actif, suspendu…) |
| **Stock** | Journal des mouvements (jamais de modification directe), réceptions, casse, ajustements, alertes de rupture |
| **Ventes et caisse** | Ventes idempotentes (`uuid`), prix fixés par le serveur, une caisse par vendeur, dépenses, clôture avec écart |
| **Crédit clients** | Limites, acomptes, remboursements (cash / MoMo), journal des dettes, créances |
| **Consignes** | Casiers vides du dépôt, casiers dus par les clients et à la brasserie, retours |
| **Achats** | Réceptions, dettes fournisseurs, règlements (le cash sort de la caisse), dernier coût d'achat |
| **Annulations** | Contre-écritures sur stock, dettes, casiers et caisse (rien n'est supprimé) |
| **Inventaire physique** | Comptage produits et casiers vides, écarts, validation tout ou rien, valorisation |
| **Rapports** | Chiffre d'affaires, marge estimée, courbe journalière, top produits, écarts de caisse, situation actuelle |
| **Rôles** | `gerant`, `caissier`, `vendeur`, `magasinier` |

## Prérequis

- PHP ≥ 8.2 avec `pdo_sqlite` et `pdo_mysql`
- Composer
- MySQL / MariaDB (bases des dépôts)
- SQLite (base centrale en local)

## Installation

```bash
git clone <url-du-depot> depot-api && cd depot-api
composer install
cp .env.example .env
php artisan key:generate
```

Configure le `.env` :

```dotenv
# Base centrale (table des dépôts) : SQLite en local
DB_CONNECTION=sqlite
# NE PAS définir DB_DATABASE / DB_USERNAME / DB_PASSWORD de dépôt ici

# Connexion MySQL « modèle » utilisée pour les bases des dépôts
TENANT_DB_HOST=127.0.0.1
TENANT_DB_PORT=3306
TENANT_DB_USERNAME=root
TENANT_DB_PASSWORD=

# manual : base créée à la main (hébergement mutualisé) | auto : créée par l'API (VPS)
TENANT_PROVISIONER=manual
```

```bash
touch database/database.sqlite
php artisan migrate          # base centrale
php artisan serve            # http://localhost:8000
```

> Les identifiants MySQL d'un dépôt ne vont **jamais** dans le `.env` : ils sont portés par le dépôt lui-même (table `tenants`). Voir `docs/PROJET.md`, §4.

## Créer un dépôt et son premier compte

1. **Créer la base** du dépôt dans MySQL (ou cPanel) : une base **vide** et un utilisateur ayant tous les droits dessus.
2. **Déclarer le dépôt** :

```bash
php artisan tinker
>>> App\Models\Tenant::create([
...   'id' => 'chez-paul',                    // code d'entreprise = valeur de X-Tenant
...   'name' => 'Dépôt Chez Paul',
...   'tenancy_db_name' => 'compte_chezpaul',
...   'tenancy_db_username' => 'compte_paul',
...   'tenancy_db_password' => 'mot-de-passe-mysql',
... ]);
```

3. **Préparer le dépôt** (vérifie la connexion, migre, crée le gérant) :

```bash
php artisan depot:provision chez-paul --email=paul@exemple.com --name="Paul"
# Le mot de passe initial du gérant est affiché une seule fois.
php artisan depot:demo chez-paul      # (optionnel) 3 produits de démonstration
```

4. **Se connecter** :

```bash
curl -X POST http://localhost:8000/api/v1/login \
  -H "X-Tenant: chez-paul" -H "Accept: application/json" \
  -d "email=paul@exemple.com" -d "password=LE_MOT_DE_PASSE"
# -> {"token":"...","user":{...}}
```

Ensuite, chaque appel envoie `X-Tenant: chez-paul` et `Authorization: Bearer <token>`.

## Exemple : une vente

```bash
# ouvrir sa caisse
curl -X POST http://localhost:8000/api/v1/cash/open -H "X-Tenant: chez-paul" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -d "opening_amount=50000"

# vendre 5 casiers (uuid généré par le client : rejouer la requête ne duplique rien)
curl -X POST http://localhost:8000/api/v1/sales -H "X-Tenant: chez-paul" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"uuid":"5f0c1c1e-3b1a-4d6e-9f55-0c1d2e3f4a5b","payment_method":"cash",
       "items":[{"product_id":1,"unit":"crate","quantity":5}]}'
```

La liste complète des routes est dans `docs/PROJET.md`, §6, et dans `routes/depot/operations.php`.

## Tests

```bash
php artisan test
php artisan test --filter=SalesTest
```

Les tests sont des tests d'API de bout en bout : chaque dépôt de test a sa propre base **SQLite** (fichiers `database/testing_*.sqlite`, supprimés ensuite), donc MySQL n'est pas nécessaire. `phpunit.xml` doit contenir `DB_CONNECTION=sqlite` et `DB_DATABASE=:memory:` (défaut de Laravel 11). Pensez à ajouter `database/testing_*.sqlite` au `.gitignore`.

Règle d'équipe : tout nouvel endpoint reçoit au moins un test d'isolation entre dépôts et un test de rôle.

## Structure

```
app/
  Models/                 Modèles des dépôts (Product, Sale, Customer, Supplier, Inventory…) et Tenant (base centrale)
  Services/               Logique métier : StockService, SaleService, CreditService, ContainerService,
                          PurchaseService, SupplierService, InventoryService, ReportService, CashService
  Http/Controllers/       Contrôleurs fins : validation + appel aux services
  Http/Middleware/        EnsureRole, EnsureTenantActive
  Tenancy/                Provisioners de bases (manuel / automatique)
  Console/Commands/       depot:provision, depot:demo
config/                   tenancy.php, depot.php, database.php (connexion tenant_template)
database/migrations/      Base centrale (tenants)
database/migrations/tenant/   Migrations de chaque dépôt
routes/depot.php          Auth + inclusion des routes métier
routes/depot/operations.php   Routes métier (stock, ventes, caisse, crédit, achats, rapports…)
tests/                    TenantTestCase + tests d'API
docs/PROJET.md            Documentation du projet
```

## Règles à connaître avant de contribuer

1. Le stock, les dettes (clients, fournisseurs) et les casiers **ne se modifient jamais directement** : passer par `StockService`, `CreditService`, `SupplierService`, `ContainerService` (journal + cache, dans une transaction).
2. Les prix viennent toujours du serveur.
3. Les opérations d'argent ou de stock sont **idempotentes** (`uuid` fourni par le client).
4. On ne supprime pas : on **annule par contre-écriture**.
5. Ordre des verrous de base : caisse → client ou fournisseur → produits (triés) → types de casiers (triés) → soldes.
6. Montants en FCFA **entiers**. Dates des rapports interprétées en heure du Bénin (`Africa/Porto-Novo`), stockage en UTC.

## Déploiement

- **Aujourd'hui** : hébergement mutualisé (Namecheap). Chaque nouveau dépôt exige la création manuelle d'une base dans cPanel (`TENANT_PROVISIONER=manual`). Le front React est déployé séparément (sous-domaine distinct de l'API).
- **Plus tard** : VPS avec `TENANT_PROVISIONER=auto`, worker de files supervisé, Redis (réactiver `CacheTenancyBootstrapper`), sauvegardes par dépôt. Checklist dans `docs/PROJET.md`, §11.
- Mutualisé : lancer `php artisan tenants:migrate` en ligne de commande (SSH), jamais depuis une route web.

## Dépannage

| Symptôme | Piste |
|---|---|
| `Unknown database 'laravel'` | La connexion modèle des dépôts doit être `tenant_template` (`config/tenancy.php`) |
| `Table 'users' already exists` pendant `depot:provision` | Le `.env` pointe sur la base d'un dépôt : il ne doit décrire que la base centrale |
| `Database connection [tenant] not configured` | Tenancy non initialisé : garder les listeners `BootstrapTenancy` et le bootstrapper de base |
| Un token fonctionne sur un autre dépôt | Vérifier `makeTenancyMiddlewareHighestPriority()` dans `TenancyServiceProvider` |
| `php artisan test` n'affiche rien | Une méthode d'aide de test porte le nom d'une méthode de `TestCase` (ex. `count`) : erreur fatale avalée par l'affichage ; lancer `vendor\bin\phpunit` pour la lire |
| Réponse 403 « dépôt inactif » | Statut du dépôt différent de `active` (`php artisan depot:provision <id>`) |

Plus de cas dans `docs/PROJET.md`, §12.

## Licence et état

Projet privé, en développement. Statut, limites connues et feuille de route : `docs/PROJET.md`, §8 à §10.
