# Déploiement de l'API sur un hébergement mutualisé cPanel (Namecheap)

Ce guide met en ligne l'**API** (`api.mondomaine.com`). Le front viendra ensuite sur un autre sous-domaine (`app.mondomaine.com`).
Les commandes sont à lancer **en SSH**, dans le dossier du projet, sauf mention contraire.

> **Ce qui est vérifié et ce qui ne l'est pas.** Le code et le script `scripts/deploy.sh` sont testés. Les réglages propres à Namecheap
> (chemin de PHP, extensions, limites) viennent de leur documentation et **doivent être confirmés sur votre forfait** : c'est le rôle de
> `php artisan depot:check`, que vous lancerez dès l'étape 6. Si une ligne de `depot:check` est en `[FAIL]`, corrigez avant d'aller plus loin.

## 0. Ce qu'il faut avoir

- Un hébergement avec **accès SSH** (cPanel → *Exclusive for Namecheap Customers* → **Manage Shell** → activer ; port **21098**), **PHP 8.3 minimum**, **MySQL**, **Cron**.
- Un domaine, et un **sous-domaine** pour l'API.
- Le dépôt GitHub `GuivenM/depot-api`.
- Un endroit **hors du serveur** pour copier les sauvegardes (S3, SFTP, autre hébergeur). Voir l'étape 9.

## 1. PHP et extensions (cPanel → *Select PHP Version*)

1. Choisir **PHP 8.3** (ou 8.4).
2. Cocher les extensions : `mbstring`, `pdo_mysql` (ou `nd_pdo_mysql`), `pdo_sqlite`, `sqlite3`, `zlib`, `fileinfo`, `xml`, `dom`, `openssl`, `ctype`, `tokenizer`, `json`, `curl`.
3. Dans *Options* : `allow_url_fopen` = On (Composer), `memory_limit` = 256M ou plus.
4. **`proc_open` ne doit pas être désactivée** : les sauvegardes MySQL et le planificateur en ont besoin. (La documentation Namecheap indique que la liste par défaut est `show_source, system, shell_exec, exec` ; `depot:check` le confirmera.)

En SSH, `php -v` n'affiche pas forcément la version choisie. Cherchez le bon binaire et **utilisez son chemin complet** partout (cron compris) :

```bash
which -a php
ls /opt/alt | grep php          # souvent /opt/alt/php83/usr/bin/php sur cPanel CloudLinux : à vérifier
/opt/alt/php83/usr/bin/php -v
```

Dans la suite, `php` désigne ce binaire (ou faites `export PHP_BIN=/opt/alt/php83/usr/bin/php`).

## 2. Le code

Le code va **à côté** de `public_html`, jamais dedans : seul le dossier `public/` doit être visible depuis Internet.

```bash
cd ~
ssh-keygen -t ed25519 -C "depot-api serveur"     # puis ajouter ~/.ssh/id_ed25519.pub comme « Deploy key » (lecture seule) dans GitHub > depot-api > Settings
git clone git@github.com:GuivenM/depot-api.git depot-api
cd depot-api
```

Composer (Namecheap : la ligne `-c php.ini` règle les soucis de configuration CLI) :

```bash
php -r "readfile('https://getcomposer.org/installer');" | php -c php.ini
php -c php.ini composer.phar install --no-dev --optimize-autoloader
```

(Si `php -c php.ini` échoue, retirez `-c php.ini`. Pour le script de mise à jour : `export COMPOSER_CMD="php composer.phar"`.)

**Version de PHP** : vous développez en PHP 8.5, l'hébergement est en 8.3 ou 8.4. Si `composer install` répond que le fichier `composer.lock` demande une version de PHP plus récente, faites **sur votre PC** :
`composer config platform.php 8.3.0 && composer update`, relancez `php artisan test`, puis commitez `composer.json` et `composer.lock`.

## 3. Le sous-domaine, le HTTPS

1. cPanel → **Domains** → créer le sous-domaine `api`, avec comme **racine du document** `depot-api/public`
   (donc `/home/VOTRE_COMPTE/depot-api/public`). Ainsi `.env`, `vendor/` et les sauvegardes restent hors d'atteinte.
2. DNS : un enregistrement `A` pour `api` vers l'IP de l'hébergement (automatique si le domaine utilise le DNS de Namecheap).
3. cPanel → **SSL/TLS Status** → *Run AutoSSL* pour `api.mondomaine.com`, puis **Domains → Force HTTPS Redirect**.

## 4. Le `.env`

```bash
cp .env.production.example .env
php artisan key:generate
nano .env        # APP_URL, CORS_ALLOWED_ORIGINS, TENANT_DB_* ... (tout est commenté dans le fichier)
touch database/database.sqlite
chmod -R ug+rwX storage bootstrap/cache database
```

⚠️ **Copiez `APP_KEY` dans un gestionnaire de mots de passe, hors du serveur, maintenant.** Elle chiffre les mots de passe des bases des dépôts :
sans elle, ils sont perdus, même avec une sauvegarde.

## 5. Base centrale et premier démarrage

```bash
php artisan migrate --force
php artisan optimize
```

## 6. Contrôle

```bash
php artisan depot:check
```

Chaque ligne est `[OK]`, `[WARN]` (à lire) ou `[FAIL]` (bloquant). À ce stade, deux `[WARN]` sont normaux : planificateur pas encore branché (étape 8) et pas de copie hors serveur (étape 9).

Test depuis l'extérieur :

```bash
curl -i https://api.mondomaine.com/up                                   # 200
curl -i -X POST https://api.mondomaine.com/api/v1/login -H "X-Tenant: nimporte" -H "Accept: application/json"   # 404 « Dépôt introuvable »
```

## 7. Créer un dépôt (un client)

1. cPanel → **MySQL Databases** : créer une base **vide** (ex. `compte_chezpaul`), un utilisateur MySQL, l'ajouter à la base avec **tous les privilèges**. cPanel préfixe les noms par le nom du compte : utilisez les noms complets.
2. Enregistrer le dépôt (le mot de passe MySQL est demandé au clavier, pas écrit dans l'historique) :
   ```bash
   php artisan depot:tenant:create chez-oka --name="Dépôt Chez Oka" --db=avdcyenp_compte_chezpaul --db-user=avdcyenp_depot
   ```
   Le code `chez-paul` est ce que le front enverra dans l'en-tête `X-Tenant` : minuscules, chiffres et tirets.
3. Préparer la base, la migrer, créer le gérant :
   ```bash
   php artisan depot:provision chez-oka --email=guivenpambou@gmail.com --name="Guiven"
   ```
   Le **mot de passe initial du gérant s'affiche une seule fois** : le lui transmettre (il devra le changer).
4. Contrôler : `php artisan depot:check --tenants`.

## 8. Les tâches planifiées (cron)

Namecheap **n'autorise pas** les tâches plus fréquentes que **toutes les 5 minutes**. cPanel → **Cron Jobs** → une seule ligne :

```
*/5 * * * * /opt/alt/php83/usr/bin/php /home/avdcyenp/depot-api/artisan schedule:run >> /dev/null 2>&1
```

Elle déclenche : la **sauvegarde de toutes les bases à 02:00 UTC**, le nettoyage quotidien des tokens expirés, et un témoin de vie que `depot:check` lit
pour vous dire si le cron tourne. Attendez 5 à 10 minutes puis relancez `php artisan depot:check` : la ligne « Planificateur » doit passer à `[OK]`.

## 9. Sauvegardes : à faire AVANT le premier vrai client

```bash
php artisan depot:backup            # une première sauvegarde à la main
php artisan depot:backup:status     # doit afficher « OK » pour chaque base
```

1. **Copie hors du serveur** : sauvegarder sur le disque qui tombe en panne ne protège de rien. Installez le pilote de disque voulu
   (`composer require league/flysystem-aws-s3-v3` ou `league/flysystem-sftp-v3`), déclarez le disque dans `config/filesystems.php`,
   puis `BACKUP_REMOTE_DISK=...` dans `.env`.
2. **Testez une restauration complète** sur un dépôt de test (créez-en un, mettez-y une vente, sauvegardez, supprimez la vente, restaurez avec
   `php artisan depot:restore <dépôt> <fichier> --force`, vérifiez que la vente est revenue). Une sauvegarde jamais restaurée n'est pas une sauvegarde.
3. Si `mysqldump` est introuvable : `which mysqldump`, puis `MYSQLDUMP_PATH=` dans `.env`.

## 10. Mettre à jour (à chaque nouvelle version)

```bash
cd ~/depot-api
./scripts/deploy.sh
```

Le script : met le site en maintenance, **sauvegarde**, récupère le code, installe les dépendances, migre la base centrale puis **chaque dépôt**,
vide et reconstruit les caches, lance `depot:check --tenants`, puis rouvre le site. **En cas d'échec, le site reste en maintenance**
(mieux vaut fermé qu'à moitié migré) et le script dit quoi faire. Options : `--no-pull`, `--no-composer`, `--skip-backup`.

Variables à définir si besoin : `PHP_BIN` (chemin complet de PHP), `COMPOSER_CMD` (`php composer.phar`).

## 11. Retour arrière

Le code seul (aucune migration dans la version qu'on quitte) :

```bash
cd ~/depot-api && php artisan down
git checkout <version-précédente>          # le script a affiché « <ancienne> -> <nouvelle> »
php composer.phar install --no-dev --optimize-autoloader
php artisan optimize:clear && php artisan optimize && php artisan up
```

Si une migration a modifié les données : **restaurer d'abord la sauvegarde prise par `deploy.sh` juste avant**
(`php artisan depot:restore <dépôt> <fichier> --force`, pour chaque dépôt concerné, et la base centrale se restaure depuis son fichier `central_*.sqlite.gz`),
puis revenir à l'ancien code comme ci-dessus.
Pour la base centrale : `gunzip -c storage/app/backups/central/central_AAAAMMJJ_HHMMSS.sqlite.gz > database/database.sqlite` (site en maintenance).

## 12. Liste de contrôle avant le premier vrai client

- [ ] `php artisan depot:check --tenants` : aucun `[FAIL]`, et chaque `[WARN]` compris.
- [ ] `APP_DEBUG=false`, `APP_ENV=production`, `APP_URL` en `https`.
- [ ] `APP_KEY` copiée hors du serveur.
- [ ] Le dossier `public/` est bien la racine du sous-domaine : `https://api.../.env` doit répondre **404 ou 403**, jamais afficher le fichier.
- [ ] Copie des sauvegardes hors du serveur en place, **restauration testée**.
- [ ] Cron `schedule:run` actif (la ligne « Planificateur » est `[OK]`).
- [ ] `depot:backup:status` branché sur une supervision (ex. un cron quotidien qui vous envoie un email en cas de code ≠ 0).
- [ ] `CORS_ALLOWED_ORIGINS` contient uniquement le domaine du front.
- [ ] Compte gérant créé, mot de passe initial transmis et changé.

## 13. Dépannage

| Symptôme | Cause probable | Remède |
|---|---|---|
| Erreur 500 sans détail | `APP_DEBUG=false` (normal) : lire le journal | `tail -50 storage/logs/laravel-*.log` |
| 500 juste après une mise à jour | Caches périmés ou droits | `php artisan optimize:clear && php artisan optimize` ; `chmod -R ug+rwX storage bootstrap/cache` |
| `depot:check` : `proc_open désactivé` | Fonction bloquée par l'hébergeur | Demander son activation au support ; sans elle, sauvegardes et cron planificateur ne marchent pas |
| `mysqldump` introuvable | Pas dans le PATH | `which mysqldump` puis `MYSQLDUMP_PATH=` |
| Le front reçoit une erreur CORS | Domaine du front absent | `CORS_ALLOWED_ORIGINS=https://app.mondomaine.com` puis `php artisan config:clear` |
| 404 « Dépôt introuvable » | `X-Tenant` absent ou faux | Le code du dépôt (étape 7) doit être envoyé dans l'en-tête `X-Tenant` |
| 429 à la connexion | Trop d'essais (5/min par compte) | Attendre la durée indiquée (`Retry-After`) |
| « Access denied » sur un dépôt | Utilisateur MySQL non rattaché à la base | cPanel → MySQL Databases → *Add User To Database* → tous privilèges |
| Une modification de `.env` sans effet | Configuration en cache | `php artisan config:clear` (ou `optimize` après) |
| Le cron ne tourne pas | Chemin de PHP, intervalle < 5 min | Chemin complet du binaire ; `*/5` ; cPanel → Cron Jobs → vérifier l'email d'erreur |
| `Class "Barryvdh\DomPDF..." not found` | `composer install` non lancé | `php composer.phar install --no-dev --optimize-autoloader` |
| `composer install` : « lock file ... PHP » | `composer.lock` résolu pour PHP 8.5 | Voir l'étape 2 (`composer config platform.php 8.3.0` puis `composer update`, en local) |
