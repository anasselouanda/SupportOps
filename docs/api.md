# API SupportOps

Authentification Sanctum par cookies et session web. React utilise l'instance Axios existante avec `withCredentials` et `withXSRFToken`. Avant un POST ou PATCH, obtenir le cookie CSRF avec `GET /sanctum/csrf-cookie`.

## Profil et rôles

Le profil public est limité à `id`, `name`, `email`, `role`, `is_active`. Les rôles sont `employee` (employé), `technician` (technicien) et `admin` (administrateur). `password` et `remember_token` ne sont jamais exposés.

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
| POST | `/api/v1/admin/users` | 201 `{ message, user }` |
| PATCH | `/api/v1/admin/users/{user}` | 200 `{ message, user }` |

La liste accepte `page` (entier >= 1) et `per_page` (entier de 1 à 100, défaut 15). Elle renvoie `data` (profils publics), `current_page`, `last_page`, `per_page`, `total` et les autres métadonnées standard du paginateur Laravel.

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

La liste et `TicketPolicy` utilisent le même filtrage `visibleTo`. Un détail hors périmètre et un identifiant inexistant répondent 404. Aucune opération d’affectation ou de transition n’est disponible dans cette étape.

Paramètres facultatifs de liste :

| Paramètre | Validation / défaut |
| --- | --- |
| `search` | Texte, 255 caractères maximum, recherche sur titre ou référence ; `%` et `_` sont littéraux |
| `status` | `open`, `in_progress`, `resolved`, `closed` |
| `priority` | `low`, `normal`, `high`, `urgent` |
| `page` | Entier, minimum 1 ; défaut 1 |
| `per_page` | Entier, 1 à 100 ; défaut 15 |

Une valeur invalide ou une clé inconnue reçoit 422 avec `{ message, errors }`. Les conditions de recherche sont groupées à l’intérieur du périmètre autorisé. Le tri est décroissant sur `created_at`, puis sur `id` pour départager les dates identiques. Les métadonnées standard Laravel sont dans `meta` (`current_page`, `last_page`, `per_page`, `total`, etc.) et les liens dans `links`.

La ressource ticket expose `id`, `reference`, `title`, `description`, `priority`, `status`, `category: { id, name }`, `requester: { id, name }`, `technician: { id, name } | null`, `created_at`, `updated_at`, `resolved_at`, `closed_at`. Les dates sont sérialisées en ISO 8601 ; les deux dernières sont nulles à la création. Les relations sont chargées à l’avance, sans requête par ticket. Aucun email, rôle, état du compte, mot de passe, token ou événement interne n’est exposé par cette ressource.

### Parcours d’une création

Requête avec cookie et CSRF → authentification et activation → autorisation `TicketPolicy` et validation du Form Request → transaction avec vérification de catégorie → insertions du ticket et de l’événement en base → sérialisation par API Resource et réponse JSON 201. Une validation invalide répond 422 avant l’écriture ; une erreur de transaction ne laisse pas de ticket partiel.

### Préparation locale

Depuis `backend`, avec PHP 8.3 ou plus et votre configuration MySQL habituelle : `php artisan migrate`. Cette commande reste à exécuter localement : aucune migration ni aucun seeder n’a été lancé sur la base de développement pendant cette intervention. Les migrations ajoutent les trois tables et leurs contraintes ; elles ne modifient pas les données des tables existantes. Aucune catégorie métier ni donnée de démonstration n’est insérée automatiquement. Il faut disposer de catégories actives dans la base pour créer des tickets ; aucun endpoint de gestion des catégories n’est inclus.
