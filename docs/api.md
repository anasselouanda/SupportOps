# API SupportOps

Authentification Sanctum par cookies et session web. React utilise l'instance Axios existante avec `withCredentials` et `withXSRFToken`. Avant un POST ou PATCH, obtenir le cookie CSRF avec `GET /sanctum/csrf-cookie`.

## Profil et rôles

Le profil public est limité à `id`, `name`, `email`, `role`, `is_active`. Les rôles sont `employee` (employé), `technician` (technicien) et `admin` (administrateur). `password` et `remember_token` ne sont jamais exposés.

## Tableau de bord

`GET /api/v1/dashboard` exige une session Sanctum et un compte actif, avec l’autorisation `TicketPolicy::viewAny`. Un invité reçoit 401 ; un compte désactivé reçoit 403 `account_disabled` et sa session est invalidée. Aucun paramètre n’est accepté : toute clé de query string ou de corps reçoit 422, notamment `user_id`, `requester_id`, `role`, `status` et `page`.

La réponse suit l’enveloppe Resource :

```json
{
  "data": {
    "stats": { "total": 0, "open": 0, "in_progress": 0, "resolved": 0, "closed": 0 },
    "recent_tickets": []
  }
}
```

Ces zéros correspondent à un périmètre vide. Les compteurs entiers décrivent l’état courant des tickets, sans compter les événements historiques. `total = open + in_progress + resolved + closed`. Une seule requête SQL utilise `COUNT(*)` et quatre sommes conditionnelles sur l’ensemble du périmètre, sans pagination et sans charger tous les tickets. Un statut non conforme dans ce périmètre provoque une erreur de cohérence 500, au lieu d’exclure silencieusement le ticket ou d’afficher des chiffres faux.

Les compteurs et les récents réutilisent exactement `Ticket::visibleTo` : employé = tickets qu’il a créés ; technicien = tickets créés par lui ou actuellement assignés à lui ; administrateur = tous. Un ticket créé et assigné au même technicien n’est compté qu’une fois. Deux utilisateurs peuvent donc voir des totaux différents, sans possibilité d’élargir leur accès depuis le navigateur.

`recent_tickets` contient au maximum cinq tickets, triés par `created_at` décroissant puis `id` décroissant. Chaque objet expose uniquement `id`, `reference`, `title`, `status`, `priority`, `category: { id, name }`, `technician: { id, name } | null` et `created_at` en ISO 8601 UTC. Catégorie et technicien sont préchargés ; aucun email, description ou profil complet n’est exposé.

React appelle cet endpoint via le client Axios partagé et les cookies existants. Chaque arrivée sur `/dashboard` recharge les données ; le bouton Actualiser permet une lecture manuelle, sans polling. Les réponses obsolètes sont annulées/ignorées et les données sont isolées par identité, rôle et activation. Une erreur affiche Réessayer, sans faux compteurs à zéro et sans conservation de données dans localStorage. Les cinq cartes ouvrent `/tickets`, puis `/tickets?status=open`, `in_progress`, `resolved` ou `closed` ; chaque ticket récent ouvre son détail. Les dates sont affichées en Africa/Casablanca.

Voir [la recette du dashboard et du MVP](recette.md) pour les vérifications exécutées et les contrôles restants.

## Authentification

| Méthode | Endpoint | Accès / réponse |
| --- | --- | --- |
| GET | `/api/health` | Public, 200 avec `status: "ok"` |
| POST | `/api/login` | Email et mot de passe, 200 `{ message, user }`, cinq tentatives par minute |
| GET | `/api/user` | Session active, 200 `{ user }` |
| POST | `/api/logout` | Session active, 200 `{ message }`, session invalidée |

L'inscription publique est retirée : `POST /api/register` répond 404. La page React `/register` redirige vers `/login`. Les comptes existants sont conservés.

Les identifiants invalides et les comptes inactifs donnent la même erreur de connexion 422 sur `email`. Sur une route protégée, une session appartenant à un compte désactivé est invalidée et reçoit :

```json
{
  "message": "Votre compte a été désactivé. Contactez un administrateur.",
  "code": "account_disabled"
}
```

Cette réponse a le statut 403. React retire alors le profil local et affiche le message sur la connexion. Une autre erreur 403 signifie un accès interdit et conserve la session active. Une requête ultérieure après invalidation reçoit 401.

## Administration des utilisateurs

Ces endpoints exigent un administrateur actif. L'authentification et l'autorisation sont vérifiées côté Laravel, indépendamment de la navigation React.

| Méthode | Endpoint | Succès |
| --- | --- | --- |
| GET | `/api/v1/admin/users` | 200, pagination Laravel |
| GET | `/api/v1/admin/technicians` | 200 `{ data: [{ id, name }], links, meta }` |
| POST | `/api/v1/admin/users` | 201 `{ message, user }` |
| PATCH | `/api/v1/admin/users/{user}` | 200 `{ message, user }` |

La liste accepte `page` (entier >= 1) et `per_page` (entier de 1 à 100, défaut 15). Elle renvoie `data` (profils publics), `current_page`, `last_page`, `per_page`, `total` et les autres métadonnées standard du paginateur Laravel.

La liste dédiée `/api/v1/admin/technicians` retourne uniquement les techniciens actifs, triés par `name`, puis `id`. Elle accepte seulement `page` et `per_page` avec les mêmes bornes et renvoie la pagination API Resource dans `links` et `meta`. Les profils sont limités à `id` et `name`, sans email ni état du compte. Un invité reçoit 401 ; un employé ou technicien reçoit 403 ; un administrateur désactivé reçoit la réponse `account_disabled` et sa session est invalidée. Les paramètres invalides ou inconnus reçoivent 422. React parcourt toutes les pages de cet endpoint, par lots de 100, pour proposer tous les techniciens actifs lors d’une affectation.

Création : `name`, `email`, `role`, `password`, `password_confirmation`, et `is_active` facultatif (true par défaut). Le nom et l'email sont limités à 255 caractères, l'email est unique, le mot de passe confirmé contient au moins huit caractères. Le rôle doit appartenir aux trois valeurs autorisées et l'activation est un booléen.

Modification partielle : seulement `name`, `email`, `role`, `is_active`. Les champs inconnus sont rejetés avec 422. Aucun endpoint de suppression ou de réinitialisation du mot de passe n'est disponible.

Erreurs : 401 sans session, 403 sans autorisation, 404 pour un utilisateur introuvable, 422 pour validation incorrecte avec `{ message, errors }`. La désactivation ou la rétrogradation du dernier administrateur actif reçoit 422 sur le champ concerné.

Les écritures administratives utilisent une transaction et une ligne de verrou commune. Après acquisition du verrou, Laravel relit l'acteur et les administrateurs actifs avec des lectures verrouillées afin de protéger le dernier administrateur même sous l'isolation MySQL REPEATABLE READ.

Référence Laravel : [verrouillage pessimiste dans les transactions](https://laravel.com/framework/docs/13.x/queries#pessimistic-locking).

## Premier administrateur

Depuis `backend`, après les migrations normales :

```powershell
php artisan supportops:create-admin
```

La commande exige un terminal interactif, demande nom, email et mot de passe masqué avec confirmation, puis hache le mot de passe. Elle refuse un email déjà utilisé ou l'existence de tout administrateur, même inactif. Aucun mot de passe par défaut n'est fourni. Elle n'est pas exécutée sur la base de développement par les agents.

## Tickets — contrat v1

Toutes les routes suivantes exigent une session Sanctum et un compte actif. Un invité reçoit 401 ; un compte désactivé reçoit 403 avec `code: "account_disabled"`, puis sa session est invalidée.

| Méthode | Endpoint | Succès |
| --- | --- | --- |
| GET | `/api/v1/categories` | 200 `{ data: [{ id, name }] }`, catégories actives, triées par nom |
| POST | `/api/v1/tickets` | 201 `{ data: ticket }` |
| GET | `/api/v1/tickets` | 200 `{ data: [ticket], links, meta }` |
| GET | `/api/v1/tickets/{ticket}` | 200 `{ data: ticket }`, identifiant numérique |

### Créer un ticket

```json
{
  "title": "Connexion imprimante impossible",
  "description": "L’imprimante du bureau ne répond plus depuis ce matin.",
  "category_id": 1,
  "priority": "normal"
}
```

`category_id` doit correspondre à une catégorie active déjà présente. Le titre contient 3 à 255 caractères et la description 10 à 10 000 caractères. `priority` accepte `low`, `normal`, `high`, `urgent` et vaut `normal` si omise ; une valeur `null` est invalide. Toute clé supplémentaire reçoit 422, y compris demandeur, technicien, statut, référence, identifiant et dates internes.

Le serveur prend le demandeur dans la session, fixe `status` à `open` et laisse le technicien à `null`. La référence `TKT-<ULID>` est générée côté serveur et protégée par une contrainte unique en base. Une transaction crée le ticket et son événement `created` (acteur, date et métadonnées initiales) ; l’échec de l’une des écritures annule l’ensemble. La catégorie est revérifiée sous verrou dans cette transaction.

### Consulter et rechercher

| Rôle | Tickets visibles |
| --- | --- |
| `employee` | Créés par l’utilisateur courant |
| `technician` | Créés par lui ou assignés à lui |
| `admin` | Tous |

La liste et `TicketPolicy` utilisent le même filtrage `visibleTo`. Un détail hors périmètre et un identifiant inexistant répondent 404. Les mutations spécialisées et les lectures d’activité sont décrites ci-dessous et dans [le workflow et les permissions](ticket-workflow.md).

Paramètres facultatifs de liste :

| Paramètre | Validation / défaut |
| --- | --- |
| `search` | Texte, 255 caractères maximum, recherche sur titre ou référence ; `%` et `_` sont littéraux |
| `status` | `open`, `in_progress`, `resolved`, `closed` |
| `priority` | `low`, `normal`, `high`, `urgent` |
| `page` | Entier, minimum 1 ; défaut 1 |
| `per_page` | Entier, 1 à 100 ; défaut 15 |

Une valeur invalide ou une clé inconnue reçoit 422 avec `{ message, errors }`. Les conditions de recherche sont groupées à l’intérieur du périmètre autorisé. Le tri est décroissant sur `created_at`, puis sur `id` pour départager les dates identiques. Les métadonnées standard Laravel sont dans `meta` (`current_page`, `last_page`, `per_page`, `total`, etc.) et les liens dans `links`.

La ressource ticket expose `id`, `reference`, `title`, `description`, `priority`, `status`, `category: { id, name }`, `requester: { id, name }`, `technician: { id, name } | null`, `created_at`, `updated_at`, `resolved_at`, `closed_at` et `resolution_note`. Les dates sont sérialisées en ISO 8601 UTC ; les dates de résolution/clôture et la note sont nulles à la création. Les relations sont chargées à l’avance, sans requête par ticket. Aucun email, rôle, état du compte, mot de passe, token ou événement interne n’est exposé par cette ressource.

### Parcours d’une création

Requête avec cookie et CSRF → authentification et activation → autorisation `TicketPolicy` et validation du Form Request → transaction avec vérification de catégorie → insertions du ticket et de l’événement en base → sérialisation par API Resource et réponse JSON 201. Une validation invalide répond 422 avant l’écriture ; une erreur de transaction ne laisse pas de ticket partiel.

### Préparation locale

Depuis `backend`, avec PHP 8.3 ou plus et votre configuration MySQL habituelle, vérifier `php artisan migrate:status` et appliquer les migrations manquantes avec `php artisan migrate`. Au contrôle du 18 octobre 2026, les onze migrations sont déjà appliquées et une requête Laravel réelle vers MySQL réussit. Aucun migrate ni seeder n’a été exécuté sur la base de développement pendant cette intervention frontend. Aucune catégorie métier ni donnée de démonstration n’est insérée automatiquement. Il faut disposer de catégories actives pour créer des tickets ; aucun endpoint de gestion des catégories n’est inclus.

## Workflow, commentaires et historique

Même authentification Sanctum par cookies et contrôle d’activation que les routes précédentes. Toutes les routes utilisent un identifiant numérique de ticket. L’absence ou l’invisibilité du ticket donne 404 avant validation du contenu ou des paramètres.

| Méthode | Endpoint | Données / réponse |
| --- | --- | --- |
| PATCH | `/api/v1/tickets/{ticket}` | Champs partiels `title`, `description`, `category_id`, `priority` ; 200 `{ data: ticket }` |
| PATCH | `/api/v1/tickets/{ticket}/assignment` | `technician_id` obligatoire, entier ou null ; 200 `{ data: ticket }` |
| PATCH | `/api/v1/tickets/{ticket}/status` | `status`, et `resolution_note` uniquement pour résoudre ; 200 `{ data: ticket }` |
| POST | `/api/v1/tickets/{ticket}/comments` | `content` ; 201 `{ data: comment }` |
| GET | `/api/v1/tickets/{ticket}/comments` | 200 `{ data: [comment], links, meta }` |
| GET | `/api/v1/tickets/{ticket}/events` | 200 `{ data: [event], links, meta }` |

### PATCH général

Au moins un champ autorisé doit être transmis. Les bornes de titre, description et catégorie sont les mêmes qu’à la création, mais les champs omis ne sont pas exigés. Le demandeur peut modifier titre, description et catégorie seulement lorsque le ticket est `open` et non assigné. Seul un administrateur peut transmettre `priority` après création, même pour conserver sa valeur. L’administrateur peut corriger ces quatre champs sur tout ticket non clos. Le demandeur, le technicien, le statut, la référence, la note de résolution et les dates sont refusés sur cet endpoint. Un PATCH sans changement réel renvoie 200 sans nouvel événement et sans réécrire le ticket.

### Affectation

Seul un administrateur peut affecter ou réaffecter un ticket `open` ou `in_progress` à un utilisateur actif de rôle `technician`. Le retrait (`technician_id: null`) est possible uniquement sur `open`. L’affectation ne modifie pas le statut. Réaffecter `in_progress` conserve cet état. Une affectation identique ne crée aucun événement fictif ; l’état et l’éligibilité du destinataire restent contrôlés.

### Statut et résolution

Seules quatre transitions sont admises : `open → in_progress`, `in_progress → resolved`, `resolved → closed` et `resolved → open`. Le statut identique et toute autre transition sont invalides. Le démarrage et la résolution exigent l’administrateur ou le technicien actuellement assigné. Le démarrage exige en outre une affectation à un technicien toujours actif et de rôle `technician`.

Pour résoudre, transmettre par exemple `{ "status": "resolved", "resolution_note": "Connexion rétablie et impression vérifiée." }`. La note est obligatoire, non blanche et limitée à 10 000 caractères. Sa clé est interdite sur les autres transitions, même avec null. Le serveur fixe `resolved_at` en UTC. Seuls le demandeur ou un administrateur peuvent clôturer (`{ "status": "closed" }`) et fixer ainsi `closed_at`. Un technicien non demandeur ne peut pas clôturer.

La réouverture (`{ "status": "open" }`) est réservée au demandeur ou à l’administrateur depuis `resolved`. Elle efface `resolved_at`, `closed_at` et la note courante, conserve l’affectation et garde l’ancienne résolution dans l’historique. Le technicien sera revérifié avant le prochain démarrage. `closed` est terminal : lectures possibles, aucune mutation permise.

### Commentaires et événements

Un commentaire exige un ticket visible et non clos. `content` est une chaîne nettoyée des espaces de bord, non vide, de 1 à 5 000 caractères. L’auteur vient de la session. Toutes les clés supplémentaires sont rejetées, notamment `author_id`, `ticket_id`, `actor_id` et les dates. La ressource commentaire expose `id`, `ticket_id`, `content`, `author: { id, name }`, `created_at`, `updated_at`.

Les deux lectures d’activité acceptent seulement `page` (entier >= 1, défaut 1) et `per_page` (entier de 1 à 100, défaut 15). Les commentaires sont triés par `created_at`, puis `id`, croissants ; les événements par `occurred_at`, puis `id`, croissants. Les relations d’auteur et d’acteur sont préchargées. La ressource événement expose `id`, `type`, `actor: { id, name }`, `occurred_at`, `metadata`. Les nouveaux événements ont `metadata: { before, after }` ; les événements `created` de l’étape précédente conservent leurs métadonnées initiales plates. Aucun email, mot de passe ou jeton n’est exposé.

Chaque mutation et ses événements sont atomiques. Les événements sont `updated`, `priority_changed`, `assignment_changed`, `status_changed`, `resolved`, `closed`, `reopened`, `comment_added`, en complément de `created`. Un commentaire est référencé par `after.comment_id` sans recopier son contenu. Aucun endpoint d’édition/suppression des commentaires ou événements, ni de suppression de ticket, n’est ajouté.

Erreurs : 401 invité ; désactivation existante 403 `account_disabled` avec invalidation de session ; 404 ticket absent/invisible ; 403 action interdite pour le rôle sur un ticket visible ; 422 données, état ou transition invalides. Les permissions de rôle sont vérifiées avant les conditions métier. Les lectures restent possibles sur `closed`. Aucun mécanisme de version explicite ni réponse 409 ajouté : les écritures relisent l’état courant sous verrou et réévaluent les conditions dans leur transaction.

La note nullable et `ticket_comments` proviennent de deux migrations additives, déjà marquées appliquées lors du contrôle local du 18 octobre 2026. L’ajout de la liste des techniciens et de l’interface React ne nécessite aucune migration supplémentaire. Voir [les scénarios de recette, la matrice de permissions et les règles de concurrence](ticket-workflow.md).

## Consommation React

Les routes protégées `/tickets`, `/tickets/new` et `/tickets/:ticketId` utilisent `frontend/src/api/tickets.js`, qui réutilise le client Axios partagé et les cookies Sanctum. Les filtres `search`, `status`, `priority`, `page` et `per_page` sont conservés dans l’URL de la liste. Les requêtes de lecture obsolètes sont annulées ou ignorées.

L’API ne renvoie pas de capacités d’action : leur affichage est centralisé dans `ticketCapabilities`, tandis que Laravel garde le contrôle d’autorisation définitif. Les dates UTC sont affichées en `Africa/Casablanca`. Chaque écriture prépare le cookie CSRF, attend la confirmation serveur, puis recharge le ticket et ses activités. Un résultat réseau incertain ne déclenche aucune reprise automatique ; le détail impose une actualisation avant une nouvelle écriture. Une erreur 403 ordinaire conserve la session ; 401 et désactivation réutilisent le comportement existant d’AuthProvider. Une erreur 419 demande une reconnexion, sans rejouer l’écriture.
