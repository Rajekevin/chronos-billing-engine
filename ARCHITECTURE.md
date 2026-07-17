# Architecture — Chronos Billing Engine

Ce document a deux publics : une lecture **non-technique** (ce que le projet démontre et pourquoi c'est difficile) et une lecture **technique** (comment c'est construit).

---

## 1. Pour un public RH / Tech Lead non-développeur

### Le problème métier simulé

Chronos simule un système qui **encaisse un très grand nombre d'événements** envoyés par des clients d'une API (par exemple : chaque appel qu'un client fait à un service qu'on lui facture à l'usage), puis **calcule une facture** en fin de période. C'est le même type de problème que Stripe, Twilio ou AWS résolvent pour facturer leurs propres clients à l'usage.

Deux contraintes rendent ce problème réellement difficile en production, et c'est précisément ce que ce projet démontre savoir résoudre :

1. **Le volume** : des milliers d'événements par seconde ne peuvent pas être traités un par un de façon synchrone sans ralentir voire crasher le système sous charge.
2. **La fiabilité des compteurs** : si un même événement est compté deux fois (à cause d'un retry réseau, d'un bug client, d'un crash serveur pendant le traitement), le client final se retrouve **facturé en trop** — un incident business, pas juste technique.

### Ce que l'architecture apporte, en langage clair

| Choix technique | Bénéfice business |
|---|---|
| **Traitement asynchrone** (file d'attente) | Le système absorbe les pics de trafic sans ralentir la réponse au client |
| **Vérification d'idempotence** | Garantit qu'un même événement n'est jamais facturé deux fois, même en cas de problème réseau ou de crash |
| **Séparation stricte du code métier** (Architecture Hexagonale) | Le calcul de facturation peut être testé, audité et modifié sans risquer de casser la base de données ou l'API — réduit le coût et le risque de la maintenance |
| **Tests automatisés (TDD)** | Chaque règle de facturation est vérifiée automatiquement à chaque modification du code, avant mise en production |
| **Journalisation structurée (logs)** | En cas d'incident, on peut reconstituer précisément ce qui s'est passé, quand, pour quel client — essentiel pour le support et l'audit |
| **Authentification par clé API + limitation de débit** | Protège le système contre les abus et les accès non autorisés |

### Ce que ce projet démontre chez le candidat

- Capacité à concevoir un système **prêt pour la production**, pas juste un CRUD de démonstration.
- Compréhension des enjeux **business** derrière les choix techniques (ici : ne jamais sur-facturer un client).
- Rigueur de méthode : tests écrits en même temps que le code, sécurité pensée dès la conception (et non ajoutée après coup).
- Autonomie complète sur la chaîne : conception, code, tests, containerisation, déploiement cloud.

---

## 2. Pour une lecture technique

### 2.1 Architecture Hexagonale : pourquoi trois couches strictement séparées

```
┌─────────────────────────────────────────────────────────┐
│                    Infrastructure                        │
│   (Symfony Controllers, Doctrine, Messenger, Sécurité)   │
│                         ▲                                 │
│                         │ implémente les ports            │
│  ┌──────────────────────┴──────────────────────────┐     │
│  │                  Application                      │     │
│  │        (Use Cases, orchestration, DTOs)            │     │
│  │                         ▲                          │     │
│  │                         │ appelle                  │     │
│  │  ┌──────────────────────┴───────────────────┐      │     │
│  │  │                Domain                      │      │     │
│  │  │   PHP pur — zéro dépendance externe        │      │     │
│  │  │   Entités, Value Objects, règles métier    │      │     │
│  │  └────────────────────────────────────────────┘      │     │
│  └────────────────────────────────────────────────────┘     │
└─────────────────────────────────────────────────────────┘
```

La règle est simple et non négociable : **les dépendances ne pointent que vers l'intérieur**. `Domain` ne connaît ni Symfony, ni Doctrine, ni Messenger. Cela permet de tester la logique de facturation (`BillingCalculator`) en quelques millisecondes, sans base de données ni kernel HTTP — voir `tests/Unit/Domain/BillingCalculatorTest.php`.

Les **ports** (interfaces) sont définis dans `Domain/Port/` et implémentés dans `Infrastructure/Persistence/Doctrine/Repository/`. Le câblage concret (quelle implémentation sert quelle interface) se fait dans `config/services.yaml` — c'est le seul endroit qui "connaît" les deux mondes.

### 2.2 Traitement asynchrone (Symfony Messenger)

**Flux complet d'un événement :**

1. `EventController::__invoke()` reçoit la requête HTTP POST, applique une limite de débit (`RateLimiterFactory`), valide sommairement le payload, calcule/récupère une clé d'idempotence, puis **dépose un message `LogEventMessage` sur le bus Messenger** et répond immédiatement `202 Accepted`.
2. `LogEventMessageHandler` (annoté `#[AsMessageHandler]`), exécuté par un ou plusieurs processus `messenger-worker` indépendants, consomme le message et délègue à `LogClientEventUseCase::execute()`.
3. Le Use Case applique la règle métier (idempotence, persistance) et journalise le résultat.

**Pourquoi cette séparation ?** Le tier HTTP (contrôleurs, Nginx, PHP-FPM) et le tier de traitement (workers Messenger) scalent indépendamment. Sous forte charge d'ingestion, on augmente le nombre de workers (`docker compose up --scale messenger-worker=N`, ou en production, le nombre de révisions Cloud Run job / pods Kubernetes) sans toucher au tier HTTP, et sans jamais faire attendre le client final au-delà du temps de dépôt en queue.

Le transport utilisé en local est `doctrine://` (table `messenger_messages` dans PostgreSQL — zéro infrastructure supplémentaire pour la démo). En production, on peut swapper le DSN vers Google Cloud Pub/Sub ou RabbitMQ sans changer une ligne de code métier — c'est encore un bénéfice direct de l'inversion de dépendance.

### 2.3 Idempotence — comment on évite la double facturation

C'est le point le plus sensible business : **chaque événement ne doit être compté qu'une seule fois**, même si :
- le client retente sa requête HTTP après un timeout (il ne sait pas si le premier essai a réussi) ;
- le worker Messenger crash après avoir persisté l'événement mais avant d'accuser réception (redélivrance "at-least-once") ;
- deux requêtes strictement identiques arrivent en concurrence.

**Mécanisme, en deux temps :**

1. **Clé d'idempotence** (`IdempotencyKey`) : fournie par le client via le header `Idempotency-Key`, ou dérivée par défaut d'un hash SHA-256 du payload (`ClientId + endpoint + corps brut`) — voir `IdempotencyKey::fromPayload()`.
2. **Garde d'idempotence** (`IdempotencyGuardInterface`, implémentée par `DoctrineIdempotencyGuard`) : avant tout traitement, `LogClientEventUseCase` vérifie si la clé a déjà été traitée. Si oui → exception `DuplicateEventException`, capturée silencieusement par le handler (pas de retraitement infini). Si non → persistance de l'événement, **puis** marquage de la clé comme traitée.

Le point technique important : le marquage repose sur une **contrainte UNIQUE en base** (`uniq_idempotency_key` sur `processed_idempotency_keys`), pas seulement sur un `SELECT` applicatif préalable. Un simple `SELECT` puis `INSERT` créerait une **race condition** sous forte concurrence (deux requêtes identiques passant le `SELECT` avant que l'une des deux n'ait fait son `INSERT`). En laissant PostgreSQL arbitrer via la contrainte unique, l'idempotence est garantie **atomiquement**, même à fort trafic concurrent — voir `DoctrineIdempotencyGuard::markAsProcessed()`.

### 2.4 Observabilité (Monolog)

Tous les points de décision métier journalisent en JSON structuré (Monolog, formatter JSON) plutôt qu'en texte libre :

- `chronos.event.accepted` — requête HTTP acceptée (avant traitement async)
- `chronos.event.ingested` — événement effectivement persisté
- `chronos.event.duplicate_rejected` — doublon détecté et rejeté
- `chronos.invoice.generated` — facture calculée
- `chronos.rate_limit.exceeded` — abus détecté
- `chronos.request.exception` — erreur inattendue (avec classe et message, jamais de trace complète exposée au client)

Ce format JSON structuré est directement ingérable par Google Cloud Logging (Cloud Run) sans agent supplémentaire (`config/packages/monolog.yaml`, section `when@prod`) — chaque log devient filtrable/alertable par `client_id`, par type d'événement, etc.

### 2.5 TDD : ce que les tests fournis démontrent

- `BillingCalculatorTest` : teste la règle de facturation (seuil gratuit + tarif au-delà) **sans aucune dépendance** — le test s'exécute en millisecondes.
- `LogClientEventUseCaseTest` : teste le Use Case en **mockant** les deux ports (`EventRepositoryInterface`, `IdempotencyGuardInterface`), avec deux scénarios explicites : ingestion réussie, et rejet d'un doublon sans double persistance (`$repository->expects(self::never())->method('save')`).

C'est cette isolation via interfaces qui rend le TDD réellement praticable ici : aucun test unitaire ne nécessite de base de données, de kernel Symfony, ni de conteneur Docker démarré.
