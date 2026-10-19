# Matériel, droits et affectations

Le matériel est la fiche d'un équipement identifié par un code inventaire unique. Son affectation courante est la période dont `ended_at` est nul. L'historique conserve toutes les périodes, leurs bénéficiaires et l'administrateur ayant ouvert chacune d'elles. Un transfert ferme une période et en ouvre une autre ; il ne remplace pas l'ancien bénéficiaire dans le passé.

## Schéma

| Table | Colonnes et contraintes |
| --- | --- |
| `assets` | id ; inventory_code unique, 50 caractères, canonique majuscule ; type token minuscule 50 ; brand/model obligatoires 100 ; serial_number nullable 100 ; status ; purchase_date nullable ; timestamps |
| `asset_assignments` | id ; asset_id ; user_id ; assigned_by ; started_at UTC ; ended_at UTC nullable ; timestamps ; index par matériel/date et bénéficiaire/fin |
| `tickets` | nouvelle FK asset_id nullable vers assets ; les tickets existants gardent null |

Les migrations du 19 octobre sont nouvelles et ordonnées : assets, affectations, puis association aux tickets. Les anciennes migrations restent intactes. Les références historiques utilisent RESTRICT, aucune suppression en cascade. Aucun endpoint de suppression définitive n'existe.

La colonne générée stockée `active_asset_id = CASE WHEN ended_at IS NULL THEN asset_id ELSE NULL END` possède un index unique. Elle garantit au niveau SQL au plus une affectation active par matériel ; elle n'est ni modifiable ni exposée par l'API. Aucun utilisateur courant n'est dupliqué sur assets. Ce mécanisme utilise les [colonnes générées de MySQL](https://dev.mysql.com/doc/refman/8.4/en/create-table-generated-columns.html) et le modificateur [storedAs de Laravel](https://api.laravel.com/docs/13.x/Illuminate/Database/Schema/ColumnDefinition.html).

## Matrice des permissions

Toutes les routes exigent une session Sanctum et un compte actif. Un compte désactivé conserve ses références historiques mais perd l'accès.

| Action | Administrateur actif | Employé actif | Technicien actif |
| --- | --- | --- | --- |
| Inventaire/détail | Complet | Affectation actuellement à lui, maintenance comprise | Matériels liés aux tickets actuellement assignés à lui |
| Métadonnées, affectation, transfert, restitution, état | Oui | Non | Non |
| Historique d'affectations | Oui | Non | Non |
| Bénéficiaires recherchables/paginés | Utilisateurs actifs uniquement | Non | Non |
| Association à la création de son ticket | Tout matériel existant | Matériel actuellement affecté à lui | Matériel actuellement affecté à lui |
| Correction/retrait d'association d'un ticket | Oui, ticket non clos | Non | Non |
| Résumé du matériel dans un ancien ticket visible | Oui | Oui, sans nouveau bénéficiaire | Oui, sans bénéficiaire ni historique |

Les filtres et recherches s'appliquent après le périmètre serveur. Un identifiant matériel absent ou hors périmètre renvoie 404 ; une action interdite sur une fiche visible renvoie 403. Une référence `asset_id` indisponible dans un formulaire ticket renvoie une erreur de champ 422 générique. Le technicien ne reçoit jamais les périodes d'affectation, les bénéficiaires ni la liste des tickets du matériel.

Le résumé dans un ticket contient uniquement id, code inventaire, type, marque, modèle, série et état. Le booléen serveur `asset_can_view` indique si un lien vers la fiche complète est permis ; il ne révèle pas l'identité du nouveau bénéficiaire. Les résumés et ce droit sont préchargés avec sous-requêtes SQL, sans requête par ticket.

## États et transitions

| État courant | Action | Résultat |
| --- | --- | --- |
| available | Affecter à un utilisateur actif | assigned ; ouverture d'une période |
| assigned | Affecter au même utilisateur actif | Aucun changement ni période fictive |
| assigned | Transférer à un autre utilisateur actif | Clôture puis ouverture à la même date serveur ; état assigned |
| assigned | Restituer (`user_id: null`) | Clôture ; available |
| available/assigned | Maintenance | maintenance ; période courante conservée si présente |
| maintenance | Nouvelle affectation/transfert | Refus 422 |
| maintenance | Restituer | Clôture ; reste maintenance |
| maintenance | Sortir | assigned si période active, sinon available ; cible incohérente refusée 422 |
| available/assigned/maintenance | Retirer | Clôture éventuelle ; retired ; historique conservé |
| retired | Affecter ou changer d'état | Refus 422 ; état terminal |

available signifie zéro période active, assigned exactement une. Demander un état déjà courant ou restituer sans période est sans effet. La répétition d'une affectation identique en maintenance reste sans effet si le bénéficiaire est actif ; une demande non nulle visant un bénéficiaire désactivé reçoit 422. Le PATCH général accepte seulement les métadonnées et ne peut modifier l'état ni le bénéficiaire.

Chaque mutation recharge l'acteur, verrouille le matériel et les périodes, puis contrôle le bénéficiaire dans la transaction. Les verrous `FOR UPDATE` et l'index unique empêchent deux ouvertures concurrentes. Le serveur produit les dates et l'acteur ; une erreur pendant une fermeture, une ouverture ou un enregistrement annule l'ensemble. Les deadlocks sont rejoués au niveau transaction, au maximum cinq tentatives. Aucun navigateur ne peut écrire directement l'historique.

## Association aux tickets

`asset_id` est facultatif à la création. Un transfert ou un retrait du matériel ne modifie jamais les anciens tickets. Pour corriger l'association d'un ticket non clos, l'administrateur transmet `asset_id` avec un identifiant ou null pour retirer le lien. Omettre la clé conserve le lien sans revalider arbitrairement son ancien bénéficiaire. Un changement réel produit `asset_changed` avec les identifiants avant/après ; une valeur identique ne crée pas d'événement. La création conserve l'identifiant sélectionné dans son événement initial.

## Checklist API

Avec un administrateur actif et des sessions de test isolées :

1. `POST /api/v1/assets` avec inventory_code, type, brand et model ; conserver l'id retourné (201, available).
2. `PATCH /api/v1/assets/{id}/assignment` avec `{"user_id": employeId}` ; vérifier assigned et une période active.
3. Avec cet employé, `POST /api/v1/tickets` avec les champs habituels et asset_id ; vérifier le résumé limité et l'événement créé.
4. Avec l'administrateur, transférer via assignment vers un autre utilisateur actif ; vérifier deux périodes, une seule active. L'ancien demandeur conserve le résumé de son ticket mais n'accède plus à la fiche.
5. Restituer avec `{"user_id": null}` ; vérifier available et aucune période active.
6. `GET /api/v1/assets/{id}/assignments` ; vérifier dates/acteurs et pagination. Tester ensuite maintenance, sortie cohérente et retrait.

Ne jamais exécuter cette checklist sur des données métier utilisées comme fixtures jetables.
