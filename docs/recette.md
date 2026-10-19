# Recette SupportOps — dashboard et parcours MVP

## Recontrôle du 19 octobre 2026 avant le module matériel

Les corrections de `feature/mvp-validation` ont été relues : révocation du compte pendant une création de ticket, isolation des réponses entre sessions/rôles, verrouillage d'une sauvegarde administrateur et protection contre le renvoi d'une création au résultat incertain. La suite isolée passe avec **407 tests / 2 074 assertions** ; les **87 tests frontend**, ESLint et le build passent. Les trois scénarios ReactDOM de `tests/mvpRuntime.html` ont été exécutés dans le navigateur et passent.

Une nouvelle base SQLite de recette, créée exclusivement dans un répertoire temporaire `SupportOps-mvp-20261019-200710`, utilise sa propre clé, ses sessions et son stockage. Aucun `.env` ni aucune donnée de développement modifiés. Des comptes fictifs temporaires ont été créés dans cette base uniquement ; leurs secrets ne figurent pas dans ce rapport.

Vérifications connectées réellement observées : connexion employé/technicien/administrateur, dashboards avec périmètres distincts, création d'un ticket employé, commentaire avec auteur, affectation administrateur, démarrage puis résolution avec note par le technicien, clôture par le demandeur et relecture du ticket clos avec ses six événements. Capture hors dépôt : `SupportOps-backups/20261019-200600/mvp-closed-ticket.png`. **43 contrôles HTTP réels passent** avec cookies et CSRF, dont droits, recherche/pagination, réouverture et refus de mutation d'un ticket clos. Les listes de contrôle ci-dessous restent la référence pour les scénarios non rejoués ; ce contrôle ciblé ne vaut pas validation complète de toutes les tailles d'écran ni du déploiement MySQL.

Intervention du 18 octobre 2026. Utiliser exclusivement des comptes et tickets identifiés pour le test. Aucun mot de passe à publier dans les captures ou rapports. Aucune migration ni aucun seeder exécuté sur la base de développement pour cette étape.

## Indicateurs attendus

| Carte | Définition sur le périmètre visible | Destination |
| --- | --- | --- |
| Tous les tickets | Tous les tickets visibles, tous états courants confondus | `/tickets` |
| Ouverts | `status=open` | `/tickets?status=open` |
| En cours | `status=in_progress` | `/tickets?status=in_progress` |
| Résolus | `status=resolved` | `/tickets?status=resolved` |
| Clos | `status=closed` | `/tickets?status=closed` |

Invariant : `total = open + in_progress + resolved + closed`. Les indicateurs ne comptent ni événements ni commentaires et ne sont pas limités à une page de résultats. Le technicien voit l’union des tickets créés par lui et assignés à lui, sans doublon ; l’employé voit ses propres tickets ; l’administrateur voit tous les tickets. Les cinq récents utilisent le même scope, triés par création décroissante puis id décroissant.

## Préparation

Ouvrir `http://127.0.0.1:5173/dashboard`, backend habituel sur `http://127.0.0.1:8000`. Conserver le même hôte pour les cookies Sanctum. Le contrôle Laravel de connexion à MySQL (`SELECT 1`) réussit lors de cette intervention. Aucun changement de .env n’est nécessaire.

Prévoir un employé, un autre employé, un technicien et un administrateur de test actifs, une catégorie active et des tickets de test dans chacun des quatre états. Ne réinitialiser aucune base et ne réutiliser aucune donnée métier comme fixture jetable. S’il manque ces accès, conserver les parcours connectés comme non vérifiés.

## Dashboard connecté — checklist manuelle

1. **Employé** : vérifier son nom et le périmètre annoncé ; comparer les cinq compteurs avec tous ses tickets, au-delà de quinze résultats si ce volume existe. Aucun ticket de l’autre employé ne doit apparaître.
2. **Technicien** : comparer les tickets créés par lui ou actuellement assignés à lui. Un ticket remplissant les deux conditions doit compter une seule fois ; un ticket réaffecté hors de son périmètre et non créé par lui doit disparaître.
3. **Administrateur** : vérifier la vue globale, y compris les tickets des deux employés. Comparer l’invariant et les quatre statuts.
4. **Cinq cartes** : ouvrir chaque carte, vérifier le statut dans l’URL et dans le filtre de liste ; Total retire le filtre. Les totaux dashboard portent sur toute la liste, pas seulement la page affichée.
5. **Récents** : vérifier cinq éléments maximum, ordre décroissant date/id et ouverture de chaque détail. Vérifier titre, référence, catégorie, priorité, statut et technicien sans données supplémentaires sensibles.
6. **Retour après mutation** : depuis un ticket de test, démarrer, résoudre avec note puis revenir au dashboard par la navigation ; vérifier le transfert entre compteurs sans recharger manuellement la page. Refaire après clôture.
7. **Réouverture** : sur un ticket résolu, rouvrir avant clôture ; vérifier resolved -1, open +1 et total inchangé. L’ancienne note reste dans l’historique du ticket.
8. **Actualisation** : cliquer Actualiser ; bouton désactivé et message de chargement pendant la lecture, puis données confirmées. Pas de polling permanent.
9. **Erreur réseau** : interrompre uniquement l’accès API dans un environnement de recette, cliquer Actualiser ; aucun faux compteur zéro ne remplace les données. Rétablir l’accès et cliquer Réessayer ; vérifier le chargement puis les compteurs réels.
10. **Session** : se déconnecter puis ouvrir un autre compte de test ; aucune donnée du premier compte ne doit rester affichée. Vérifier aussi la session expirée et le compte désactivé, sans confusion avec un simple refus 403.
11. **Sans ticket** : avec un compte de test sans ticket visible, vérifier cinq vrais zéros, un état vide utile et le lien Nouveau ticket.
12. **Mobile et clavier** : refaire à 360, 768 et desktop ; vérifier absence de débordement, textes longs, focus visible, navigation des cartes et liens, accès au contenu et annonce chargement/erreur.

## Parcours tickets du MVP — checklist restante

1. Employé crée un ticket ; erreurs 422 près des champs, brouillon conservé, absence de doublon sur double clic.
2. Actualisation puis liste : référence et informations persistées.
3. Administrateur affecte un technicien actif ; confirmation et historique présents.
4. Technicien démarre ; demandeur et technicien commentent avec leur auteur serveur.
5. Technicien résout avec une note ; demandeur clôture après confirmation.
6. Ticket clos en lecture seule, sans ajout de commentaire ni modification.
7. Autre employé incapable d’ouvrir le détail hors périmètre.
8. Recherche, filtres et pagination conservés après actualisation et Retour/Avancer ; un filtre modifié revient à page 1.
9. Sur un autre ticket, réouverture avant clôture, puis nouveau démarrage/résolution et clôture ; vérifier les notes historiques.
10. Après erreur réseau d’une création ou d’un commentaire, vérifier le résultat avant tout nouvel envoi ; aucune reprise automatique.

Voir aussi [le workflow API détaillé](ticket-workflow.md) et [le contrat API](api.md).

## Vérifications automatisées et limites

Les résultats définitifs de cette intervention figurent dans [progression.md](progression.md). Les tests backend utilisent uniquement SQLite `:memory:` avec gardes de configuration ; ils ne valident pas les verrous MySQL réels. Les tests de rendu React utilisent des fixtures de test isolées et ne constituent pas une connexion navigateur réelle à Laravel/MySQL.

Résultats exécutés : **405 tests backend / 2 044 assertions**, dont **20 / 116** spécifiques au dashboard ; **85 tests frontend**, dont **17 nouveaux** (7 API, 10 SSR) ; Pint, lint et build réussis. La connexion MySQL Laravel et le refus HTTP401 du dashboard sans session sont confirmés. Dans le navigateur, seule la redirection de `/dashboard` invité vers `/login` a été vérifiée lors de cette phase.

Les douze contrôles du dashboard connecté et les dix étapes du parcours tickets ci-dessus restent **non vérifiés dans le navigateur**, faute de session de test disponible. Aucun compte de recette créé, aucun mot de passe conservé dans le rapport. Les liens et états testés en SSR ne sont pas comptabilisés comme des clics réels. Le MVP est prêt à être soumis à la recette finale, sans validation de bout en bout revendiquée.

La recette finale du MVP n’est validée que lorsque les parcours connectés ci-dessus ont été exécutés et consignés. Aucun tag ni release v0.1.0 n’est créé à cette étape.
