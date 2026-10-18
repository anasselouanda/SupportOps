# Progression SupportOps — rôles et permissions

Branche de travail : `feature/roles-permissions`, créée depuis `main` après autorisation explicite. Le checkout `main` du Bureau reste attaché à son worktree. Le travail applicatif est réalisé dans `C:\Users\admin\.codex\worktrees\cd39\SupportOps`.

## Répartition des agents

- Backend : modèle User, migrations additives, policy, middleware d'activation, Form Requests, contrôleur/service administratifs et commande du premier administrateur.
- Frontend : rôles du dashboard, navigation, page et formulaires des utilisateurs, suppression de l'inscription et gestion des erreurs de session.
- Tests : isolation de base avant migrations, régression de l'authentification, permissions, validation, désactivation, migrations et commande.
- Coordinateur : branche, setup/dépendances, routes Laravel/React, configuration PHPUnit, contrat API, intégration, revue et documentation.

Les fichiers sont attribués exclusivement ; les routes, configurations et documents restent au coordinateur. Aucun commit ni push prévu.

## Fonctionnement

L'authentification vérifie l'identité grâce à l'email et au mot de passe, puis conserve la session Sanctum. L'autorisation décide quelles actions le rôle peut effectuer : seuls les administrateurs actifs gèrent les comptes. La désactivation interdit une nouvelle connexion et bloque une session déjà ouverte dès sa prochaine requête protégée, avec invalidation de session et code `account_disabled`.

Le contrat détaillé se trouve dans [api.md](api.md). Aucun développement de tickets ou de matériel, suppression de compte ou réinitialisation de mot de passe n'est prévu dans cette étape.

## Environnement et sécurité des tests

PHP du PATH initial : 8.0.30, incompatible avec ce projet. PHP compatible utilisé : `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`, version 8.3.30. Node 22.16.0 et npm 10.9.2 sont disponibles.

`backend/AGENTS.md` exigeait Laravel Boost : dépendance de développement installée, consignes générées pour Codex. Les packages applicatifs existants ont conservé leurs versions verrouillées. L'autoload optimisé Composer est resté bloqué ; un autoload normal a été généré en restaurant ensuite la configuration originale. Les permissions Windows ont nécessité des commandes hors sandbox pour les dépendances et le cache local.

Aucun `.env` n'est modifié. Aucune migration ni création d'administrateur n'est exécutée sur la base de développement. PHPUnit force `testing`, SQLite `:memory:`, cache/session `array`, et une URL de base vide. Son bootstrap refuse une configuration mise en cache ; TestCase vérifie la configuration effective avant `RefreshDatabase`. Une clé d'application aléatoire est générée uniquement dans le processus de test si nécessaire.

## Contrôles

- Avant les modifications métier : tests d'authentification existants réussis, 15 tests et 59 assertions.
- Frontend avant modifications : `npm run lint` et `npm run build` réussis.
- Formatage final : `php vendor/bin/pint --dirty --format agent`, réussi, uniquement fichiers PHP touchés.
- Suite complète après formatage : `php vendor/phpunit/phpunit/phpunit --colors=never`, **82 tests, 375 assertions, tous réussis** (9,697 s), SQLite en mémoire.
- Frontend final : `npm run lint` et `npm run build`, réussis après correction des réponses de session obsolètes (build 2,79 s).
- Vérification frontend en mémoire avec le code AuthProvider réel, hooks simulés et adaptateur Axios contrôlé : anciennes 401/désactivations après nouvelle session, 403 ordinaire, mauvais identifiants, notification et nettoyage des intercepteurs réussis. Aucun test navigateur revendiqué.
- Routes : `php artisan route:list --path=api --except-vendor --json`, réussi ; sept routes API présentes, inscription publique absente.
- `git diff --check`, réussi. Avertissement de conversion LF/CRLF sur AuthProvider lié à Git, sans erreur de whitespace.
- Parcours navigateur avec cookies/CSRF réels : **non vérifiés**.
- Concurrence avec deux connexions MySQL réelles : **non vérifiée** ; les tests isolés SQLite ne valident pas le comportement des verrous MySQL.

Les contrôles ont détecté deux défauts corrigés avant validation finale : un nom de méthode de la commande Artisan entrait en conflit avec Laravel, et le garde Sanctum conservait un utilisateur en mémoire après logout/invalidation. Les deux chemins d'invalidation nettoient désormais ce garde. Les anciennes réponses Axios ne peuvent plus effacer une connexion plus récente.

Une première suite a révélé onze échecs : six comparaisons de snapshots de tests ont été corrigées pour comparer les valeurs relues de la base, et cinq régressions de session ont conduit à la correction Sanctum ci-dessus. Les résultats finaux sont ceux des 82 tests réussis. Des revues automatiques de permissions ont échoué temporairement pour capacité du modèle ; les commandes concernées ont ensuite été autorisées et exécutées via cette même revue.

## Commandes locales restantes

Utiliser PHP 8.3 ou plus dans le terminal, par exemple :

```powershell
$env:PATH = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;' + $env:PATH
```

Depuis `backend` sur cette branche, avec votre configuration MySQL locale existante :

```powershell
php artisan migrate
php artisan supportops:create-admin
```

La seconde commande est interactive ; le mot de passe est masqué et confirmé. Ne pas utiliser `migrate:fresh` ou `db:wipe`. Les migrations sont additives, conservent les utilisateurs et les rôles renseignés. Leur retour arrière ne supprime pas les colonnes ajoutées ni les données ; prévoir un traitement explicite si un retrait du schéma devient nécessaire.

Pour relancer les contrôles dans ce worktree :

```powershell
# backend, configuration PHPUnit isolée et non mise en cache
php vendor/phpunit/phpunit/phpunit
# frontend
npm run lint
npm run build
```

## Checklist locale navigateur et MySQL

- Se connecter avec chacun des trois rôles actifs ; vérifier le rôle sur le dashboard.
- Vérifier que seuls les administrateurs voient « Utilisateurs » ; l'employé et le technicien voient « Accès interdit » sur `/admin/users` et restent connectés.
- Créer un compte, vérifier les erreurs par champ, la liste paginée et la navigation clavier/mobile.
- Modifier nom/email/rôle/activation ; vérifier les confirmations sensibles et le profil courant après une modification de soi.
- Désactiver un compte depuis une autre session ; sa prochaine requête protégée doit l'envoyer vers la connexion avec le message dédié. Vérifier qu'il ne peut plus se connecter.
- Refuser la désactivation ou rétrogradation du dernier administrateur actif.
- Sur une base MySQL de test distincte, envoyer simultanément des requêtes qui tentent de retirer les derniers administrateurs actifs ; vérifier qu'au moins un subsiste après les transactions.
- Vérifier `/register` vers `/login`, `POST /api/register` inaccessible, `/api/health` public et déconnexion effective.
- Couper le réseau, vérifier les erreurs et les boutons de réessai ; restaurer le réseau puis réessayer.
- Vérifier le flux réel cookie CSRF/Sanctum entre les origines Laravel et Vite configurées localement.

## Fichiers modifiés

Backend : `app/Models/User.php`, `app/Http/Controllers/Api/AuthController.php`, `app/Http/Controllers/Api/Admin/UserController.php`, `app/Http/Middleware/EnsureUserIsActive.php`, les trois `app/Http/Requests/Admin/*User*Request.php`, `app/Policies/UserPolicy.php`, `app/Services/AdminUserService.php`, `app/Console/Commands/CreateInitialAdmin.php`, `database/factories/UserFactory.php`, les deux migrations du 5 octobre et `routes/api.php`.

Tests : `tests/bootstrap.php`, `tests/TestCase.php`, `tests/Feature/Auth/AuthenticationTest.php`, `AccountActivationTest.php`, les tests `Admin/ListUsersTest.php`, `StoreUserTest.php`, `UpdateUserTest.php`, `Console/CreateAdminTest.php`, `Database/UserAccessMigrationTest.php`, `Policies/UserPolicyTest.php`, `Services/AdminUserServiceTest.php`. Configuration/setup : `phpunit.xml`, `composer.json`, `composer.lock`, `boost.json`, `AGENTS.md` généré.

Frontend : `src/App.jsx`, `src/api/auth.js`, `src/api/users.js`, `src/auth/AuthProvider.jsx`, `src/auth/roles.js`, `src/auth.css`, `src/components/authErrors.js`, `src/components/UserForm.jsx`, `src/pages/LoginPage.jsx`, `DashboardPage.jsx`, `AdminUsersPage.jsx`, `src/routes/AdminRoute.jsx`. `RegisterPage.jsx` supprimée. Documentation : `docs/api.md` et ce rapport.

## État Git final

Branche active confirmée : `feature/roles-permissions`. Aucun commit ni push. Résultat de `git status --short` :

```text
 M backend/AGENTS.md
 M backend/app/Http/Controllers/Api/AuthController.php
 M backend/app/Models/User.php
 M backend/composer.json
 M backend/composer.lock
 M backend/database/factories/UserFactory.php
 M backend/phpunit.xml
 M backend/routes/api.php
 M backend/tests/Feature/Auth/AuthenticationTest.php
 M backend/tests/TestCase.php
 M frontend/src/App.jsx
 M frontend/src/api/auth.js
 M frontend/src/auth.css
 M frontend/src/auth/AuthProvider.jsx
 M frontend/src/components/authErrors.js
 M frontend/src/pages/DashboardPage.jsx
 M frontend/src/pages/LoginPage.jsx
 D frontend/src/pages/RegisterPage.jsx
?? backend/app/Console/
?? backend/app/Http/Controllers/Api/Admin/
?? backend/app/Http/Middleware/
?? backend/app/Http/Requests/
?? backend/app/Policies/
?? backend/app/Services/
?? backend/boost.json
?? backend/database/migrations/2026_10_05_120847_add_role_and_is_active_to_users_table.php
?? backend/database/migrations/2026_10_05_120849_create_user_administration_locks_table.php
?? backend/tests/Feature/Admin/
?? backend/tests/Feature/Auth/AccountActivationTest.php
?? backend/tests/Feature/Console/
?? backend/tests/Feature/Database/
?? backend/tests/Feature/Policies/
?? backend/tests/Feature/Services/
?? backend/tests/bootstrap.php
?? docs/
?? frontend/src/api/users.js
?? frontend/src/auth/roles.js
?? frontend/src/components/UserForm.jsx
?? frontend/src/pages/AdminUsersPage.jsx
?? frontend/src/routes/AdminRoute.jsx
```
