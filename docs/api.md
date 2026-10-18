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
