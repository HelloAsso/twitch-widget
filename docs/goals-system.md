# Système d'objectifs de collecte

## Vue d'ensemble

Les streams et les événements peuvent définir une liste ordonnée d'objectifs (montants en €). La barre de don et le widget carte avancent vers l'objectif actif ; une fois atteint, l'objectif suivant devient actif automatiquement, avec une animation de célébration.

---

## Base de données

### Table `{prefix}goals`

```sql
CREATE TABLE {prefix}goals (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    charity_stream_guid VARCHAR(255) NULL,
    charity_event_guid  VARCHAR(255) NULL,
    amount              INT NOT NULL,               -- montant en euros (pas en centimes)
    INDEX idx_stream_guid (charity_stream_guid),
    INDEX idx_event_guid  (charity_event_guid)
);
```

- Un objectif appartient soit à un stream, soit à un événement (les deux colonnes GUID sont mutuellement exclusives).
- Les objectifs d'un stream et d'un événement sont **indépendants** : un événement a ses propres objectifs, et chacun de ses streams a les siens.
- `amount` est en **euros** (pas en centimes), contrairement aux montants de dons qui sont en centimes dans le reste du code.

---

## Backend

### `GoalRepository`

| Méthode | Description |
|---|---|
| `selectAmountsByStreamGuid(string $guid): int[]` | Retourne les montants triés ASC pour un stream |
| `selectAmountsByEventGuid(string $guid): int[]` | Retourne les montants triés ASC pour un événement |
| `replaceForStream(string $guid, int[] $amounts): void` | Supprime + recrée tous les objectifs d'un stream (transactionnel) |
| `replaceForEvent(string $guid, int[] $amounts): void` | Idem pour un événement |
| `deleteByStreamGuid(string $guid): void` | Suppression en cascade lors de la suppression d'un stream |
| `deleteByEventGuid(string $guid): void` | Suppression en cascade lors de la suppression d'un événement |

### `resolveActiveGoal(array $goals, int $amountInCents): int`

Méthode privée de `WidgetController`. Détermine l'objectif actif à afficher.

```
goals = [500, 1000, 2000]   amountInCents = 75000 (= 750 €)
→ retourne 1000  (premier objectif > 750 €)

goals = [500, 1000, 2000]   amountInCents = 210000 (= 2100 €)
→ retourne 2000  (tous dépassés → dernier objectif)

goals = []
→ retourne 1     (valeur par défaut si aucun objectif défini)
```

**Comportement quand tous les objectifs sont atteints :** la barre reste à 100 % (le diviseur ne change plus). Le flag `allGoalsReached` est calculé séparément.

---

## Endpoints de fetch (polling)

Tous les 10 secondes, les widgets appellent leur endpoint de fetch. Depuis l'ajout des objectifs multiples, la réponse inclut :

| Champ | Type | Description |
|---|---|---|
| `amount` | int | Montant collecté **en centimes** |
| `goal` | int | Objectif actif **en euros** (résolu par `resolveActiveGoal`) |
| `allGoalsReached` | bool | `true` si le montant collecté ≥ dernier objectif |
| `donors` | int | Nombre de donateurs (card uniquement) |

| Endpoint | Fichier JS consommateur |
|---|---|
| `GET /widget-stream-donation/{id}/fetch` | `stream.js` |
| `GET /widget-event/{id}/fetch` | `event.js` |
| `GET /widget-stream-card/{id}/fetch` | `cardStream.js` |
| `GET /widget-event-card/{id}/fetch` | `cardEvent.js` |

---

## Interface d'administration

### Chips UI (stream et événement)

- Les objectifs s'affichent sous forme de chips triés par ordre croissant.
- **Ajouter** : saisir un montant dans le champ puis cliquer "＋ Ajouter" ou appuyer sur Entrée.
- **Supprimer** : cliquer le × sur le chip.
- **Enregistrement automatique** : chaque ajout ou suppression soumet immédiatement le formulaire (pas de bouton "Enregistrer"). Le formulaire POST contient `save_goals=1` et un tableau `goal_amounts[]`.
- Les objectifs sont toujours maintenus triés ASC côté JS avant l'envoi.

### Contraintes

- Au moins un objectif est obligatoire.
- Les objectifs d'un stream et de son événement parent sont indépendants — modifier l'un ne touche pas l'autre.

---

## Widgets

### Barre de don (`donation.html.twig`)

```
window.goalAmount    → objectif actif en euros (initialisé depuis Twig)
window.currentAmount → montant collecté en centimes (mis à jour par polling)
```

Le remplissage de la barre est calculé dans `utilities.js` :

```js
const percentage = Math.min(100, (currentAmountUnit / window.goalAmount) * 100);
// clip-path CSS appliqué sur div.front
```

### Widget carte (`card.html.twig`)

Même logique, barre de progression HTML classique (largeur en %) à la place du clip-path.

---

## Détection du franchissement d'un objectif

Dans `stream.js` / `event.js` / `cardStream.js` / `cardEvent.js`, la condition est évaluée **avant** de mettre à jour `window.currentAmount` :

```js
const justCrossedGoal =
    json.goal !== window.goalAmount              // objectif intermédiaire atteint
    || (json.allGoalsReached                     // OU dernier objectif atteint
        && window.currentAmount / 100 < window.goalAmount);
```

Le second cas est nécessaire car `resolveActiveGoal` retourne toujours la même valeur (le dernier objectif) quand tous sont dépassés — `json.goal` ne change donc plus, et la première condition serait `false` sans lui.

---

## Animations

### Barre de don — `triggerGoalAnimation(newGoal, counterback, counterfront, isLastGoal)`

| Phase | Durée | Ce qui se passe |
|---|---|---|
| 1 | 0 → 1 s | Barre se remplit jusqu'à 100 % via la transition CSS existante |
| 2 | 1,2 → 3,7 s | Shimmer (reflet blanc qui balaie la barre 3×) + glow pulsant (`front--celebrating`) |
| 3 | 3,7 s | Si `isLastGoal` : reste à 100 %. Sinon : `window.goalAmount` → `newGoal`, textes mis à jour, barre redescend via transition CSS |

Le flag `isAnimating` empêche deux animations simultanées.

### Widget carte — `triggerGoalCelebration(newGoal, isLastGoal)`

| Élément | Comportement |
|---|---|
| 5 explosions de particules | Positions aléatoires, couleurs variées, durée ~1,4 s avec décalage progressif |
| Banner central | Apparaît au centre de la card, animation bounce-in → 5 s de présence → fade-out |
| Texte du goal | Flash + mise à jour vers `newGoal` (ignoré si `isLastGoal`) |

**Texte du banner :**
- Objectif intermédiaire : `🎯 Objectif atteint ! Prochain objectif : X €`
- Dernier objectif : `🏆 Tous les objectifs atteints !`

---

## Cycle de vie complet

```
Admin ajoute [500, 1000, 2000] sur un stream
        ↓
Widget se charge → resolveActiveGoal([500,1000,2000], 0) = 500
        ↓
Don reçu → montant passe à 550 €
        ↓
Polling (10 s) → goal=1000, allGoalsReached=false
    justCrossedGoal: 1000 ≠ 500 → true
    → triggerGoalAnimation(1000, ..., false)
    → barre remplit à 100 %, shimmer, reset à 55 % (550/1000)
        ↓
Dons → montant passe à 2100 €
        ↓
Polling → goal=2000, allGoalsReached=true
    justCrossedGoal: allGoalsReached && 2100/100 >= 2000 → true
    → triggerGoalAnimation(2000, ..., true)
    → barre remplit à 100 %, shimmer, reste à 100 %
```
