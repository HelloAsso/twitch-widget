# HelloAsso Stream — Documentation utilisateur

HelloAsso Stream est un outil gratuit qui permet aux associations et aux streamers de suivre en temps réel les dons collectés via HelloAsso, directement sur un live (Twitch, YouTube, etc.) ou sur un site web. Il fonctionne grâce à des **widgets** personnalisables (barre de progression, alertes, carte de don) que vous intégrez dans votre logiciel de streaming (OBS, Streamlabs…) ou sur votre site via une iframe.

---

## Table des matières

1. [Créer un compte et se connecter](#1-créer-un-compte-et-se-connecter)
2. [Tableau de bord](#2-tableau-de-bord)
3. [Gérer les événements](#3-gérer-les-événements)
4. [Gérer les streams](#4-gérer-les-streams)
5. [Les widgets](#5-les-widgets)
6. [Intégrer un widget dans OBS / sur votre site](#6-intégrer-un-widget-dans-obs--sur-votre-site)
7. [Mode test](#7-mode-test)
8. [FAQ / Résolution de problèmes](#8-faq--résolution-de-problèmes)

---

## 1. Créer un compte et se connecter

### Inscription

1. Rendez-vous sur la page d'inscription.
2. Renseignez votre **adresse email** et un **mot de passe**.
3. Le mot de passe doit respecter les règles suivantes :
   - Au moins **8 caractères**
   - Au moins **une majuscule** et **une minuscule**
   - Au moins **un chiffre**
   - Au moins **un caractère spécial**
4. Cliquez sur **S'inscrire**.
5. Un **email de confirmation** est envoyé à votre adresse. Cliquez sur le lien pour activer votre compte.

> ℹ️ Le lien de vérification est valable **1 heure**. Si vous ne le recevez pas, vérifiez vos spams.

### Connexion

Connectez-vous avec votre email et votre mot de passe. Si votre email n'a pas encore été vérifié, un nouvel email de confirmation vous sera renvoyé automatiquement.

### Mot de passe oublié

1. Cliquez sur **Mot de passe oublié** sur la page de connexion.
2. Entrez votre adresse email.
3. Vous recevrez un email contenant un lien pour définir un nouveau mot de passe.

---

## 2. Tableau de bord

Une fois connecté, vous accédez au **tableau de bord** qui présente deux onglets principaux :

- **Événements** : liste de vos événements (campagnes multi-streams).
- **Streams** : liste de vos streams individuels (chacun lié à un formulaire de don HelloAsso).

Depuis ce tableau de bord, vous pouvez créer, éditer et supprimer vos événements et streams.

---

## 3. Gérer les événements

Un **événement** regroupe plusieurs streams. Son compteur agrège en temps réel la somme de tous les dons de ses streams rattachés. C'est utile pour suivre une campagne de collecte globale composée de plusieurs initiatives.

### Créer un événement

1. Dans l'onglet **Événements**, cliquez sur **Créer un événement**.
2. Renseignez un **titre**.
3. Validez.

### Éditer un événement

Depuis la page d'édition d'un événement, vous pouvez :

- Modifier le **titre**.
- Définir un **objectif** de collecte (en €).
- Voir la liste des **streams rattachés** et en créer de nouveaux directement.
- Configurer les **widgets** (barre de don, carte de don).
- Activer le **mode test** (voir section dédiée).

### Supprimer un événement

Cliquez sur le bouton de suppression depuis le tableau de bord. Une confirmation vous sera demandée.

---

## 4. Gérer les streams

Un **stream** est lié à un formulaire de don HelloAsso. Il récupère en temps réel les dons effectués sur ce formulaire pour alimenter les widgets.

### Créer un stream

Deux méthodes sont proposées :

#### Méthode 1 — Depuis mon association (recommandé)

1. Cliquez sur **Nouveau stream** puis choisissez **Depuis mon association**.
2. Cliquez sur **Connecter mon asso** : une fenêtre d'autorisation HelloAsso s'ouvre.
3. Connectez-vous à votre compte HelloAsso et autorisez l'accès.
4. Une fois connecté, vos **formulaires de don** sont automatiquement listés.
5. Sélectionnez le formulaire souhaité, donnez un titre au stream, puis validez.

#### Méthode 2 — Manuellement

1. Choisissez **Manuellement**.
2. Renseignez :
   - Le **slug de l'association** (la partie après `helloasso.com/associations/`).
   - Le **slug du formulaire de don** (la partie après `/formulaires/don/`).
   - Un **titre** pour le stream.
3. Validez.

### Options à la création

- **Événement parent** (optionnel) : rattacher le stream à un événement existant.
- **Appliquer le style de l'événement parent** : copie automatiquement le style du widget barre de don de l'événement vers le stream.

### Éditer un stream

Depuis la page d'édition d'un stream, vous pouvez :

- Modifier le **titre** et l'**objectif** (en €).
- **Lier / délier un événement parent** : rattacher le stream à un événement ou l'en détacher.
- Accéder au **formulaire HelloAsso** lié via le lien direct.
- Configurer les **3 types de widgets** : barre de don, alerte, carte de don.
- Activer le **mode test**.

### Supprimer un stream

Cliquez sur le bouton de suppression depuis le tableau de bord.

---

## 5. Les widgets

Chaque stream dispose de **3 widgets** personnalisables. Les événements disposent de 2 widgets (barre de don et carte de don). Tous se mettent à jour automatiquement en temps réel.

### 5.1 Widget barre de don 🎯

Une barre de progression horizontale qui affiche le montant collecté par rapport à l'objectif.

**Options de personnalisation :**

| Option | Description |
|---|---|
| Couleur du texte primaire | Couleur du texte affiché sur le fond de la barre |
| Couleur du texte secondaire | Couleur du texte affiché sur la partie remplie |
| Texte | Texte central affiché sur la barre (ex : « Objectif : Sauvons les forêts ! ») |
| Couleur de la barre | Couleur de la partie remplie (progression) |
| Couleur du fond | Couleur d'arrière-plan de la barre |

Un aperçu en direct est affiché sous le formulaire de configuration.

**Disponible sur :** Streams et Événements.

### 5.2 Widget alerte 🔔

Une alerte animée qui s'affiche à chaque nouveau don reçu. Idéal pour un overlay de stream.

**Options de personnalisation :**

| Option | Description |
|---|---|
| Image | Image affichée lors de l'alerte (GIF animé recommandé) |
| Durée de l'alerte | Temps d'affichage en secondes |
| Son | Fichier audio joué lors de l'alerte |
| Volume du son | Volume de 0 à 100 |
| Template de message | Le texte affiché lors du don, avec des variables dynamiques |

**Variables disponibles dans le template de message :**

- `{pseudo}` — le pseudo du donateur
- `{amount}` — le montant du don
- `{message}` — le message laissé par le donateur

Vous pouvez utiliser du **HTML** pour formater le texte du template.

Un bouton **Prévisualiser** permet de tester l'alerte directement depuis la page de configuration.

**Disponible sur :** Streams uniquement.

### 5.3 Widget carte de don 🃏

Une carte visuelle élégante qui affiche la progression de la collecte avec une image, un titre, une description et une barre de progression. Idéale pour une intégration sur un site web ou en superposition de stream.

**Options de personnalisation :**

| Option | Description |
|---|---|
| Image de fond | Image affichée à gauche de la carte |
| Tag | Étiquette courte (ex : « URGENCE NATURE ») |
| Titre | Titre principal de la carte |
| Description | Texte descriptif de la campagne |
| Couleurs | Fond de la carte, texte, barre de progression, fond de la barre, texte du tag, fond du tag |

La carte affiche automatiquement :
- Le **montant collecté**
- Le **pourcentage** de l'objectif atteint
- Le **nombre de donateurs**
- L'**objectif** en euros

Un aperçu en direct est affiché sous le formulaire de configuration.

**Disponible sur :** Streams et Événements.

---

## 6. Intégrer un widget dans OBS / sur votre site

### Dans OBS Studio / Streamlabs

1. Dans la page d'édition de votre stream ou événement, repérez l'**URL** du widget souhaité (affichée sous chaque widget).
2. Dans OBS, ajoutez une source **Navigateur** (Browser Source).
3. Collez l'URL du widget.
4. Ajustez la taille selon vos besoins :
   - **Barre de don** : largeur ~800 px, hauteur ~60 px
   - **Alerte** : largeur ~600 px, hauteur ~400 px
   - **Carte de don** : largeur ~720 px, hauteur ~340 px

### Sur un site web (iframe)

Pour le widget carte de don, un **code iframe prêt à copier** est disponible dans la page de configuration. Cliquez sur **📋 Copier** pour l'ajouter à votre site.

Exemple de code généré :

```html
<iframe src="https://votre-domaine.com/widget-stream/GUID/card"
        width="720" height="340" frameborder="0"
        style="border:none;overflow:hidden;"
        scrolling="no" allowtransparency="true"></iframe>
```

> 💡 Vous pouvez aussi utiliser directement l'URL de n'importe quel widget dans une iframe.

---

## 7. Mode test

Le mode test permet de **simuler des dons** sans passer par HelloAsso. C'est utile pour vérifier le bon fonctionnement de vos widgets avant un live.

### Activer le mode test

1. Dans la page d'édition d'un stream ou d'un événement, repérez la section **🧪 Mode test**.
2. Cliquez sur **Activer le mode test**.

### Simuler un don

Une fois le mode test activé, un formulaire de simulation apparaît :

- **Pseudo** : le pseudo du donateur fictif (par défaut : « Testeur »).
- **Montant** : le montant en euros du don simulé.
- **Message** : le message associé au don.

Cliquez sur **💸 Simuler un don** pour envoyer le don fictif. Les widgets se mettent à jour en temps réel.

### Remettre à zéro

Cliquez sur **🔄 Remettre à zéro** pour réinitialiser le compteur de test à 0 €.

### Désactiver le mode test

Cliquez sur **❌ Désactiver le mode test**. Le montant test est automatiquement remis à zéro et les widgets repassent en mode réel (données HelloAsso).

> ⚠️ En mode test, **aucun appel à l'API HelloAsso n'est effectué**. Les widgets affichent uniquement les montants simulés.

---

## 8. FAQ / Résolution de problèmes

### ⚠️ « Token invalide » s'affiche à côté d'un stream

Le token d'accès à l'API HelloAsso a expiré. Pour le renouveler :

1. Depuis le tableau de bord, notez le slug de l'association concernée.
2. Reconnectez l'association en créant un nouveau stream via la méthode **Depuis mon association**, ou demandez à un administrateur de relancer l'autorisation OAuth.

### Je ne reçois pas l'email de confirmation

- Vérifiez votre dossier **spam / indésirables**.
- Essayez de vous reconnecter : un nouvel email sera envoyé automatiquement si votre adresse n'est pas encore vérifiée.
- Le lien expire après **1 heure**. Si le délai est dépassé, tentez une nouvelle connexion pour recevoir un nouveau lien.

### Les widgets ne se mettent pas à jour

- Vérifiez que le stream n'est pas en **mode test** (les widgets afficheraient alors les montants simulés et non les vrais dons).
- Vérifiez que le token de l'association est valide (pas de badge ⚠️ dans le tableau de bord).
- Les widgets disposent d'un **cache** de quelques secondes. Patientez un instant avant que les nouveaux dons n'apparaissent.

### Puis-je rattacher plusieurs streams à un même événement ?

Oui, c'est précisément le rôle des événements. Chaque stream est lié à un formulaire de don différent, et l'événement agrège la somme de tous les dons de ses streams.

### Puis-je utiliser le widget carte de don sur mon site web ?

Oui. Utilisez le **code iframe** fourni dans la page de configuration du widget carte, ou copiez directement l'URL du widget dans une iframe personnalisée.

