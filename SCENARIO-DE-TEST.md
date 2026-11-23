# Scénario de test manuel de l'API

À dérouler sur un dépôt **de test** (jamais sur un vrai dépôt), pour vérifier de bout en bout le déploiement.

## 0. Préparer les données

Sur le serveur, en SSH, depuis le dossier du projet (dépôt vide, après `depot:provision`) :

```bash
php artisan depot:demo chez-paul --full --users
```

Cela crée : 6 produits avec coût et prix, 2 types de casiers consignés (40 vides chacun), 5 clients avec limites de crédit, 2 fournisseurs,
et 3 comptes de test (`caissier@demo.test`, `vendeur@demo.test`, `magasinier@demo.test`) dont les **mots de passe provisoires s'affichent une seule fois**.
Les prix sont des exemples. Le stock est voulu varié : *Fanta 33cl* est sous son seuil et *Eau Possotomè* est en rupture, pour voir les alertes.

La commande refuse de s'exécuter si le dépôt contient déjà des produits : elle ne se mélange jamais à de vraies données.

## 1. Outils

Avec **Postman / Insomnia / Bruno**, ou avec `curl` (sous Windows PowerShell, écrire `curl.exe`, car `curl` y est un autre outil).
Chaque appel envoie `X-Tenant: chez-paul`, `Accept: application/json`, et (sauf la connexion) `Authorization: Bearer <token>`.

```bash
API=https://api.mondomaine.com/api/v1
T="X-Tenant: chez-paul"
```

## 2. Déroulé

| # | Action | Appel | Résultat attendu |
|---|---|---|---|
| 1 | Connexion du gérant | `POST $API/login` `{"email":"…","password":"…"}` | 200, un `token`, `expires_at` |
| 2 | Mauvais mot de passe 6 fois de suite | idem avec un faux mot de passe | 422 puis **429** (limite de 5/minute) |
| 3 | Produits et prix | `GET $API/products` | 6 produits, stock visible |
| 4 | Alertes de stock | `GET $API/reports/stock-alerts` | 2 produits : Fanta (bas), Eau Possotomè (rupture) |
| 5 | Ouvrir la caisse | `POST $API/cash/open` `{"opening_amount":20000}` | 201 |
| 6 | Vente au comptant | `POST $API/sales` `{"uuid":"<un UUID>","payment_method":"cash","items":[{"product_id":1,"unit":"crate","quantity":2}]}` | 201, total 13 000 |
| 7 | Rejouer la même vente (même `uuid`) | idem | **même vente**, pas de doublon ni de second débit de stock |
| 8 | Vente à crédit | idem avec `"payment_method":"credit","customer_id":2` | 201 ; solde du client et casiers dus augmentent |
| 9 | Dépasser la limite de crédit | vente à crédit de plus de 20 000 pour *Madame Adjovi* | 422 ; avec `"override_limit":true` (gérant) : accepté et **tracé dans l'audit** |
| 10 | Encaisser un paiement client | `POST $API/customers/2/payments` `{"uuid":"<UUID>","amount":5000,"method":"cash"}` | 201, solde réduit |
| 11 | Reçu PDF | `curl -H "$T" -H "Authorization: Bearer $TOKEN" $API/sales/1/receipt.pdf -o recu.pdf` | un PDF lisible : lignes, total, mode de paiement, **casiers à rendre** |
| 12 | Annuler une vente | `POST $API/sales/1/void` `{"reason":"test"}` | 200 ; stock et caisse remis ; visible dans le reçu (« VENTE ANNULÉE ») |
| 13 | Relevé client | `GET $API/customers/2/statement.pdf` | solde de début / fin et détail |
| 14 | Dépense de caisse | `POST $API/cash/expenses` `{"category":"transport","amount":1000}` | 201 |
| 15 | Clôturer la caisse (avec un écart volontaire) | `POST $API/cash/close` `{"counted_cash":<théorique − 500>}` | 200, `difference` = -500 |
| 16 | Clôture imprimée | `GET $API/cash/sessions/<id>/report.pdf` | calcul de la caisse, écart, lignes de signature |
| 17 | Rapport d'activité | `GET $API/reports/activity.pdf` | ventes, marge, meilleurs produits, caisses |
| 18 | Créances | `GET $API/reports/receivables.pdf` | clients qui doivent, du plus gros au plus petit |
| 19 | Export Excel | `GET $API/reports/sales.csv` | s'ouvre dans Excel, accents corrects |
| 20 | Droits | refaire 6 avec le `vendeur`, puis `GET $API/reports/activity.pdf` avec lui | 6 : 201 ; rapport : **403** |
| 21 | Compte désactivé | `POST $API/users/<id>/deactivate` puis utiliser son ancien token | **401** |
| 22 | Journal d'audit | `GET $API/audit-logs` | dépassement de limite, annulation, écart de caisse, désactivation |

Pour changer un mot de passe provisoire : `POST $API/me/password` `{"current_password":"…","password":"…","password_confirmation":"…"}`.

## 3. Côté serveur

```bash
php artisan depot:check --tenants       # aucun [FAIL]
php artisan depot:backup                # puis : php artisan depot:backup:status
```

Pour tester la restauration : faire une sauvegarde, annuler une vente ou en créer une, puis
`php artisan depot:restore chez-paul <fichier> --force` : l'état d'avant revient.

## 4. Nettoyage

Il n'y a pas de suppression de comptes ni de produits (volontairement : l'historique les référence). Pour repartir de zéro sur un dépôt **de test uniquement** :
`php artisan tenants:migrate-fresh --tenants=chez-paul` (⚠ efface tout, ne jamais oublier `--tenants`), puis `depot:provision` et `depot:demo` de nouveau.
