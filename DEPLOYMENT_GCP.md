# Déploiement — Google Cloud Platform

Architecture cible : **Cloud Run** (API HTTP, autoscaling à zéro) + **Cloud Run job / service dédié** pour les workers Messenger + **Cloud SQL for PostgreSQL** + **Secret Manager**.

```
                         ┌──────────────────┐
   Internet ───────────► │   Cloud Run       │  (service HTTP, stateless)
                         │   chronos-api      │
                         └─────────┬─────────┘
                                   │ écrit un message
                                   ▼
                         ┌──────────────────┐
                         │  messenger_messages│  (Cloud SQL, transport Doctrine)
                         └─────────┬─────────┘
                                   │ consommé par
                                   ▼
                         ┌──────────────────┐
                         │   Cloud Run       │  (service "worker", min-instances ≥ 1,
                         │   chronos-worker   │   pas de trafic HTTP entrant)
                         └─────────┬─────────┘
                                   ▼
                         ┌──────────────────┐
                         │   Cloud SQL PG16  │
                         └──────────────────┘
```

> Le transport Doctrine (déjà utilisé en local) fonctionne tel quel en production — zéro service supplémentaire à provisionner pour démarrer. Pour un débit plus élevé, migrer le DSN Messenger vers **Google Cloud Pub/Sub** (section 6) sans changer le code applicatif.

## 1. Prérequis

```bash
gcloud auth login
gcloud config set project <PROJECT_ID>
gcloud services enable run.googleapis.com sqladmin.googleapis.com \
    secretmanager.googleapis.com artifactregistry.googleapis.com \
    vpcaccess.googleapis.com
```

## 2. Provisionner Cloud SQL (PostgreSQL 16)

```bash
gcloud sql instances create chronos-billing-db \
    --database-version=POSTGRES_16 \
    --tier=db-custom-2-4096 \
    --region=europe-west1 \
    --storage-type=SSD \
    --storage-size=20GB \
    --backup-start-time=02:00

gcloud sql databases create chronos_billing --instance=chronos-billing-db

gcloud sql users create chronos \
    --instance=chronos-billing-db \
    --password="$(openssl rand -base64 24)"
```

> Stockez le mot de passe généré directement dans Secret Manager (étape suivante), ne le laissez jamais dans un historique de terminal partagé.

## 3. Secrets (Secret Manager)

```bash
echo -n "postgresql://chronos:<PASSWORD>@/chronos_billing?host=/cloudsql/<PROJECT_ID>:europe-west1:chronos-billing-db&sslmode=require" \
  | gcloud secrets create chronos-database-url --data-file=-

echo -n "$(openssl rand -hex 32 | sha256sum | cut -d' ' -f1)" \
  | gcloud secrets create chronos-api-key-hash --data-file=-

echo -n "$(openssl rand -hex 32)" \
  | gcloud secrets create chronos-app-secret --data-file=-
```

## 4. Build & push de l'image (Artifact Registry)

```bash
gcloud artifacts repositories create chronos-repo \
    --repository-format=docker --location=europe-west1

gcloud builds submit --tag europe-west1-docker.pkg.dev/<PROJECT_ID>/chronos-repo/chronos-api:latest .
```

## 5. Déployer le service HTTP (Cloud Run)

```bash
gcloud run deploy chronos-api \
    --image europe-west1-docker.pkg.dev/<PROJECT_ID>/chronos-repo/chronos-api:latest \
    --region europe-west1 \
    --platform managed \
    --add-cloudsql-instances <PROJECT_ID>:europe-west1:chronos-billing-db \
    --set-env-vars APP_ENV=prod,APP_DEBUG=0,DB_SSL_MODE=require \
    --set-secrets DATABASE_URL=chronos-database-url:latest,API_KEY_HASH=chronos-api-key-hash:latest,APP_SECRET=chronos-app-secret:latest \
    --min-instances 1 \
    --max-instances 20 \
    --cpu 1 \
    --memory 512Mi \
    --no-allow-unauthenticated=false \
    --concurrency 40
```

Notes :
- `--min-instances 1` évite le cold start sur le chemin d'ingestion à fort trafic.
- `--concurrency` reste modéré : PHP-FPM gère un nombre de workers limité par instance ; ajustez selon le `pm.max_children` réel du conteneur.
- Exécutez les migrations **avant** le premier déploiement de trafic (job one-shot, section 7).

## 6. Déployer le worker asynchrone (Cloud Run, sans trafic HTTP)

Un service Cloud Run peut tourner sans jamais recevoir de requête HTTP entrante, en exécutant directement la commande `messenger:consume` comme process principal — utile pour rester sur Cloud Run plutôt que de provisionner GKE pour ce seul besoin.

```bash
gcloud run deploy chronos-worker \
    --image europe-west1-docker.pkg.dev/<PROJECT_ID>/chronos-repo/chronos-api:latest \
    --region europe-west1 \
    --add-cloudsql-instances <PROJECT_ID>:europe-west1:chronos-billing-db \
    --set-env-vars APP_ENV=prod,APP_DEBUG=0,DB_SSL_MODE=require \
    --set-secrets DATABASE_URL=chronos-database-url:latest,API_KEY_HASH=chronos-api-key-hash:latest,APP_SECRET=chronos-app-secret:latest \
    --no-cpu-throttling \
    --min-instances 1 \
    --max-instances 5 \
    --command "php" \
    --args "bin/console,messenger:consume,async,--time-limit=3600,--memory-limit=256M"
```

`--no-cpu-throttling` est important : un worker Messenger doit continuer à consommer du CPU même sans trafic HTTP entrant, ce que Cloud Run limite par défaut hors requête active.

### Migration vers Google Cloud Pub/Sub (montée en charge)

Pour dépasser le débit raisonnable d'un transport Doctrine/PostgreSQL, remplacez uniquement le DSN Messenger :

```bash
composer require symfony/google-pubsub-messenger  # bridge communautaire, à valider avant usage prod
```

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            async: 'gps://default/chronos-events-topic'
```

Aucune ligne de `LogEventMessage`, `LogEventMessageHandler` ou du Use Case n'a besoin de changer — c'est la preuve concrète de l'inversion de dépendance mise en place dès le départ.

## 7. Exécuter les migrations (job one-shot)

```bash
gcloud run jobs create chronos-migrate \
    --image europe-west1-docker.pkg.dev/<PROJECT_ID>/chronos-repo/chronos-api:latest \
    --region europe-west1 \
    --add-cloudsql-instances <PROJECT_ID>:europe-west1:chronos-billing-db \
    --set-env-vars APP_ENV=prod,APP_DEBUG=0,DB_SSL_MODE=require \
    --set-secrets DATABASE_URL=chronos-database-url:latest,APP_SECRET=chronos-app-secret:latest \
    --command "php" \
    --args "bin/console,doctrine:migrations:migrate,--no-interaction"

gcloud run jobs execute chronos-migrate --region europe-west1 --wait
```

## 8. Observabilité en production

Les logs JSON écrits sur `stderr` (voir `config/packages/monolog.yaml`, section `when@prod`) sont automatiquement collectés par **Cloud Logging** sans agent supplémentaire sur Cloud Run. Pour l'exploitation :

- Créez un **log-based metric** sur `jsonPayload.message="chronos.event.duplicate_rejected"` pour alerter sur un taux de doublons anormal (souvent révélateur d'un bug client ou d'une attaque par replay).
- Créez une alerte Cloud Monitoring sur `chronos.rate_limit.exceeded` pour détecter les abus.
- Reliez Cloud SQL Insights pour surveiller la latence des requêtes `INSERT` sur `processed_idempotency_keys` sous forte concurrence.

## 9. Checklist avant mise en production réelle

- [ ] Remplacer la clé API statique par un modèle multi-clé + rotation via Secret Manager
- [ ] Activer Cloud Armor / WAF en frontal de `chronos-api`
- [ ] Configurer des sauvegardes Cloud SQL testées (restauration validée, pas seulement activée)
- [ ] Ajouter un scan de vulnérabilités d'image en CI (Artifact Registry scanning ou Trivy)
- [ ] Définir un budget d'alerte GCP sur le projet (le trafic d'ingestion pilote directement le coût Cloud Run + Cloud SQL)
