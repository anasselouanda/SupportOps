# Progression SupportOps — dashboard connecté

## Reprise MVP du 19 octobre 2026

Relecture des fonctionnalités réellement présentes et des corrections non committées sur `feature/mvp-validation`. Aucun blocage majeur identifié dans l'audit et les scénarios exécutés : **407 tests backend / 2 074 assertions**, **87 tests frontend**, lint/build et **3 régressions ReactDOM navigateur** réussis. La recette connectée ciblée est détaillée dans `recette.md`, avec ses limites explicites.

Avant intégration, sauvegarde hors dépôt `C:\Users\admin\Desktop\SupportOps-backups\20261019-200600` : bundle complet vérifié, patches binaires index/non index, inventaires des références/SHA et copie vérifiée SHA-256 des sept fichiers non suivis. Les références initiales `main`, `origin/main` et `feature/mvp-validation` pointent sur `6e03f30fca519aa8eac34c432597ce6311dfbb37`. Seul le travail MVP validé est autorisé à être committé et poussé ; le futur module matériel doit rester non committé sur `feature/assets-api`.

Intervention du 18 octobre 2026 dans `C:\Users\admin\Desktop\SupportOps`. Branche active : **feature/dashboard**. Les nouveaux changements restent non indexés, non committés et non poussés. Aucun tag ni release v0.1.0 créé.

## Prérequis et intégration de l’étape précédente

Le worktree Desktop est le dépôt autorisé. Le cwd initial du chat est un ancien worktree détaché, conservé intact. Seul `backend/AGENTS.md` est applicable ; les règles ont été lues et aucun `.ai/rules` n’existe. Versions vérifiées : PHP 8.3.30, Laravel 13.34.0, Sanctum 4.3.3, PHPUnit 12.5.37 ; React 19.2.8, Axios 1.20, Router 7.18.4 et build Vite 8.3.2.

Connexion effective Laravel/MySQL : pilote mysql, requête `SELECT 1 AS connected` réussie et tables tickets/commentaires présentes. La vérification dépasse le simple endpoint health. Aucun .env changé, aucune migration ni aucun seeder sur la base de développement, aucune base réinitialisée.

Authentification Sanctum par cookies, rôles, désactivation, scope `Ticket::visibleTo`, API/liste/détail des tickets, affectation/transitions/commentaires/historique et documentation existants relus. Les composants et le client Axios existants sont réutilisés. Aucun blocage de code constaté dans le parcours tickets ; sa recette connectée navigateur reste non vérifiée et est reportée dans recette.md.

Avant intégration : **385 tests backend / 1 928 assertions**, réussis en 34,18 s ; **68 tests frontend**, lint et build réussis (2,17 s). Après fusion sur main : **385 / 1 928**, réussis en 31,35 s ; lint, build (1,52 s) et 68 tests Node rejoués avec succès.

Les tests DB sont isolés par phpunit.xml, tests/bootstrap.php et TestCase : env testing forcé, SQLite `:memory:`, DB_URL vide, cache/session array, refus de la configuration en cache. Ils ne touchent pas MySQL de développement. Le skill testing-best-practices demandé par AGENTS est toujours introuvable ; conventions existantes suivies. PHP Laragon 8.3 utilisé explicitement avec `PAO_DISABLE=1` ; PHP 8.0 du PATH incompatible.

## Sauvegarde et opérations Git

Sauvegarde hors dépôt **`C:\Users\admin\Desktop\SupportOps-backups\20261018-195925`** :

- `SupportOps.bundle` complet et vérifié ;
- patches binaires staged (vide initialement) et unstaged ;
- copies des 24 fichiers non suivis utiles, chemins conservés et SHA-256 source/copie identiques ;
- inventaires des chemins et des SHA des branches ;
- `after-integration.bundle` complet et vérifié avant suppression de branche ;
- `integration-inventory.txt` avec les références intégrées.

La première écriture du patch avait échoué à cause de la syntaxe d’argument PowerShell. Aucune intégration n’a suivi cet échec ; l’argument a été corrigé et sauvegarde/patches/copies vérifiés avant de poursuivre. Dépendances, .env, secrets et dumps exclus ; il s’agit d’une sauvegarde de code et d’historique, pas de base.

Origin confirmé : `https://github.com/anasselouanda/SupportOps.git`. Le coordinateur a seul exécuté les commandes Git, séquentiellement. Les 31 fichiers du travail tickets ont été préparés explicitement, diff indexé et chemins examinés, puis commit **`544f1f72a98321c6e5e9cd2e960e386ee81b40e8`**, message `feat: connect ticket screens to the workflow API`.

Fetch origin, passage sur main, synchronisation `--ff-only origin/main` et intégration `--ff-only feature/tickets-frontend` réussis. Contrôles pertinents rejoués, puis push main réussi. Main, origin/main et la référence publiée ont été revérifiés sur ce SHA. Après vérification de la sauvegarde et de `git merge-base --is-ancestor feature/tickets-frontend origin/main`, suppression locale avec `git branch -d`. Aucune branche distante tickets-frontend n’existait ; origin annonçait uniquement main. Fetch prune réussi. Aucun travail non fusionné supprimé, aucun reset/clean/stash/force push.

Depuis main propre et synchronisée, création puis confirmation de **feature/dashboard**. Seul le travail précédent a été committé/poussé ; les nouveaux développements dashboard restent non indexés pour revue.

## Organisation des agents et fichiers

Contrat JSON et propriété exclusive définis avant le travail parallèle, sans Git ni DB de développement par les agents :

- Backend : DashboardController, DashboardRequest, DashboardResource, RecentTicketResource ; agrégation, validation des paramètres et ressources minimales.
- Frontend : DashboardPage et dossier `frontend/src/dashboard/` ; DashboardContent pur, hook useDashboardData, CSS dédié, réemploi header/nav/logout.
- Tests/revue : DashboardTest et fichiers `frontend/tests/dashboard*.test.mjs` ; périmètres, compteurs, transitions, API et rendu des états/liens.
- Coordinateur : routes backend, module API frontend partagé, configuration inchangée, documentation, intégration Git et validation finale.

Les fichiers produit n’ont pas été édités simultanément par plusieurs agents. Le coordinateur a effectué le formatage final Laravel après stabilisation des fichiers PHP.

## Contrat et calcul des données

**GET `/api/v1/dashboard`**, compte authentifié et actif, autorisation `TicketPolicy::viewAny` :

```json
{
  "data": {
    "stats": { "total": 0, "open": 0, "in_progress": 0, "resolved": 0, "closed": 0 },
    "recent_tickets": []
  }
}
```

Les zéros sont réels pour un périmètre vide. Les indicateurs comptent les états courants, pas les événements : total = open + in_progress + resolved + closed. Une unique agrégation SQL COUNT(*) + quatre SUM(CASE WHEN status = ? ...) avec COALESCE calcule des entiers sur l’ensemble du périmètre, sans pagination et sans charger tous les tickets en mémoire. Si une donnée contient un statut non conforme, une erreur de cohérence500 est signalée ; aucun ticket visible n’est silencieusement retiré du total. Cette protection a été ajoutée après revue de la colonne status, qui est une chaîne sans contrainte enum.

Les compteurs et les cinq récents réutilisent le scope partagé `Ticket::visibleTo`. L’employé voit ses tickets, le technicien ceux créés par lui OU assignés à lui, l’administrateur tous. La condition OR n’introduit pas de jointure ni de doublon. Deux utilisateurs peuvent donc voir des chiffres différents selon leurs droits.

Aucun paramètre de requête ou de corps n’est accepté : user_id, requester_id, role, status, page et toute clé inconnue reçoivent422. Les récents sont limités à cinq, triés created_at DESC puis id DESC ; catégorie et technicien préchargés. Champs stricts : id, reference, title, status, priority, category{id,name}, technician{id,name}|null, created_at UTC. Aucun profil complet, email, description ou commentaire dans cette réponse. Voir [api.md](api.md).

## Interface et sessions

`/dashboard` affiche accueil nominatif, périmètre selon rôle, cinq cartes, cinq récents maximum, Nouveau ticket et Tous les tickets. Les cartes ouvrent la liste avec le filtre status existant ; chaque récent ouvre le détail. Badges et dates Africa/Casablanca réutilisent les composants/helpers des tickets. Styles français cohérents, focus visible, lien d’accès au contenu, dispositions mobile/tablette/desktop et libellés lisibles sans dépendre de la couleur.

Le client `api/dashboard.js` utilise l’Axios existant avec credentials/XSRF et un AbortSignal. Pas de token ni stockage des données dashboard dans localStorage. À chaque remontage après retour d’un ticket, nouvelle lecture ; bouton Actualiser désactivé pendant la requête, aucun polling permanent. Les réponses obsolètes sont annulées/ignorées. L’identité id/rôle/activation masque les données d’une session précédente et remonte le composant lors d’un changement de compte.

Chargement annoncé, vrai état vide, erreur avec Réessayer et suppression des compteurs en erreur : aucun faux zéro. Les 401/comptes désactivés réutilisent AuthProvider ; un refus403 ordinaire ne déconnecte pas. La déconnexion existante conserve son comportement419 et son message « Déconnexion non confirmée. Réessayez. ».

React demande les données via Axios ; Laravel authentifie, applique le scope et agrège dans MySQL ; les Resources renvoient le JSON, puis React met à jour les cartes et récents. Aucune donnée simulée dans le produit, aucune bibliothèque graphique ou de gestion d’état ajoutée.

## Validation finale

| Contrôle | Résultat réellement exécuté |
| --- | --- |
| `php vendor/bin/pint --dirty --format agent` | Réussi |
| DashboardTest ciblé | **20 tests, 116 assertions**, réussis, 4,73 s |
| Suite backend complète après Pint | **405 tests, 2 044 assertions**, réussis, 46,22 s |
| Nouveaux tests Node dashboard | **17 réussis**, dont 7 API et 10 rendus SSR |
| Tous les tests frontend existants et nouveaux | **85 réussis**, 2,795 s |
| `npm.cmd run lint` | Réussi |
| `npm.cmd run build` | Réussi, Vite 8.3.2, 1 992 modules |
| Routes API | 19, dont GET dashboard avec auth Sanctum et activation |
| `git diff --check` / index vide | Réussis, avertissements LF/CRLF seulement |

Les tests dashboard couvrent les trois périmètres avec 17/24/29 tickets, au-delà de la taille de page15 ; union du technicien sans doublon ; compteurs entiers et invariant ; vide ; ordre création/id et cinq récents maximum ; champs minimaux et préchargement sans N+1 ; invité401 ; désactivation403 avec invalidation réelle de session ; rôle inconnu403 ; paramètres interdits422 ; changement de rôle ; résolution et réouverture par les API existantes ; non-régression liste/détail ; statut non conforme visible500 et statut non conforme hors périmètre sans effet.

Les tests Node vérifient le vrai module Axios avec adaptateur isolé : enveloppe, cookies/XSRF, annulation, absence de paramètres et propagation des erreurs sans reprise. Les rendus SSR du composant réel couvrent chargement/succès/vide/erreur/actualisation, absence de faux zéros et de données après erreur, cinq récents, accueil/périmètre selon rôle et destinations des cartes/détails. Ils ne simulent pas le cycle d’effets du hook : l’annulation, l’isolation de session et le remontage sont revus dans le code, sans prétendre à un test navigateur de ces comportements. Le serveur Vite SSR des tests utilise un cache distinct et est fermé après les tests ; aucun serveur de recette ni DB temporaire n’a été préparé.

Commandes reproductibles :

```powershell
cd C:\Users\admin\Desktop\SupportOps\frontend
npm.cmd run lint
npm.cmd run build
node --test tests/ticketUi.test.mjs tests/ticketApi.test.mjs tests/dashboardApi.test.mjs tests/dashboardContent.test.mjs

cd C:\Users\admin\Desktop\SupportOps\backend
$env:PAO_DISABLE = '1'
& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' artisan test --compact --no-ansi
```

## Recette et opérations locales restantes

Le navigateur local affiche `/dashboard` redirigé vers `/login` en mode invité. Une lecture HTTP réelle de `/api/v1/dashboard` répond401 JSON. Une connexion manuelle avec compte de test existant a été sollicitée sans demander de mot de passe dans le chat. Aucun compte temporaire n’a été créé et aucune ancienne préparation de serveurs/DB de recette refusée n’a été contournée.

**Non vérifié dans le navigateur :** dashboards employé/technicien/administrateur ; clics des cinq compteurs et des récents ; mutation puis retour au dashboard ; responsive 360/768/desktop et parcours clavier du dashboard connecté ; erreur réseau puis nouvelle tentative ; changement de compte et absence de données résiduelles en situation réelle. Le navigateur est disponible mais reste sans session de test. Aucun de ces contrôles n’est déclaré réussi par assimilation aux tests SSR ou PHPUnit.

La [checklist de recette](recette.md) détaille les huit contrôles dashboard et le parcours tickets complet. Tant que les scénarios connectés ne sont pas exécutés, le MVP est prêt à être soumis à une recette finale, sans être déclaré validé fonctionnellement. Les tests SQLite ne prouvent pas les verrous/concurrence MySQL multi-connexion ni les conversions TIMESTAMP MySQL réelles.

Pour tester localement : ouvrir `http://127.0.0.1:5173/dashboard` avec les serveurs habituels et des comptes de test identifiés. Aucune nouvelle migration. Si les serveurs sont arrêtés : PHP8.3 `artisan serve --host=127.0.0.1 --port=8000` depuis backend, `npm.cmd run dev -- --host 127.0.0.1` depuis frontend. Ne pas modifier .env ni exécuter de seeder pour cette étape.

## État Git final

**16 fichiers concernés : 4 modifiés, 12 nouveaux.** Modifiés : `backend/routes/api.php`, `frontend/src/pages/DashboardPage.jsx`, `docs/api.md`, `docs/progression.md`. Nouveaux : quatre classes backend DashboardController/DashboardRequest/DashboardResource/RecentTicketResource ; `backend/tests/Feature/DashboardTest.php` ; `frontend/src/api/dashboard.js` ; `frontend/src/dashboard/DashboardContent.jsx`, `useDashboardData.js`, `dashboard.css` ; `frontend/tests/dashboardApi.test.mjs`, `dashboardContent.test.mjs` ; `docs/recette.md`.

Main, origin/main et HEAD de feature/dashboard restent sur `544f1f72a98321c6e5e9cd2e960e386ee81b40e8`. Branches locales restantes : main et feature/dashboard ; seule branche distante main. Index vide, aucun nouveau commit/push dashboard, aucun tag/release.

```text
## feature/dashboard
 M backend/routes/api.php
 M docs/api.md
 M docs/progression.md
 M frontend/src/pages/DashboardPage.jsx
?? backend/app/Http/Controllers/Api/DashboardController.php
?? backend/app/Http/Requests/DashboardRequest.php
?? backend/app/Http/Resources/DashboardResource.php
?? backend/app/Http/Resources/RecentTicketResource.php
?? backend/tests/Feature/DashboardTest.php
?? docs/recette.md
?? frontend/src/api/dashboard.js
?? frontend/src/dashboard/
?? frontend/tests/dashboardApi.test.mjs
?? frontend/tests/dashboardContent.test.mjs
```
