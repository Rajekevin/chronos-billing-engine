# Sécurité — Couverture OWASP

Ce document détaille comment Chronos Billing Engine adresse l'**OWASP Top 10 (2021)** et l'**OWASP API Security Top 10 (2023)**, avec pointeurs précis vers le code.

## OWASP Top 10 (2021)

| # | Risque | Mitigation dans Chronos | Fichier(s) |
|---|---|---|---|
| A01 | Broken Access Control | Authentification obligatoire par clé API sur le firewall d'ingestion ; `access_control` restreint `/api/v1/events` au rôle `ROLE_API_CLIENT` | `config/packages/security.yaml`, `Infrastructure/Security/ApiKeyAuthenticator.php` |
| A02 | Cryptographic Failures | Clé API jamais stockée en clair (comparaison de hash SHA-256 via `hash_equals` — résistant aux attaques temporelles) ; TLS forcé vers PostgreSQL en prod (`sslmode`) ; secrets externalisés (`.env`, jamais committés, `.gitignore`) | `ApiKeyAuthenticator.php`, `config/packages/doctrine.yaml` |
| A03 | Injection | Aucune requête SQL brute concaténée : Doctrine QueryBuilder avec paramètres liés partout (`setParameter`) ; désérialisation JSON stricte (`JSON_THROW_ON_ERROR`) | `DoctrineEventRepository.php`, `EventController.php` |
| A04 | Insecure Design | Idempotence garantie par contrainte UNIQUE en base (pas de check-then-act applicatif racy) ; Value Objects qui rendent les états invalides **irreprésentables** (`ClientId`, `Price`, `IdempotencyKey` valident à la construction) | `Domain/ValueObject/*.php`, `DoctrineIdempotencyGuard.php` |
| A05 | Security Misconfiguration | Conteneur applicatif exécuté par un utilisateur **non-root** (`chronos`, uid 1000) ; dépendances de build (git/unzip) supprimées de l'image finale ; Nginx bloque explicitement l'accès aux dotfiles et aux `.php` hors `index.php` ; erreurs jamais renvoyées brutes au client (`ExceptionListener`) | `Dockerfile`, `docker/nginx/default.conf`, `Infrastructure/EventListener/ExceptionListener.php` |
| A06 | Vulnerable & Outdated Components | `composer audit` exécuté en CI à chaque push/PR ; versions de dépendances explicitement bornées | `.github/workflows/ci.yml`, `composer.json` |
| A07 | Identification & Authentication Failures | Authenticator Symfony dédié, firewall `stateless` (pas de session à voler), clé API requise sur toute écriture | `Infrastructure/Security/ApiKeyAuthenticator.php` |
| A08 | Software & Data Integrity Failures | CI exécute tests + analyse statique + audit avant tout merge ; `composer.lock` versionné pour builds reproductibles | `.github/workflows/ci.yml` |
| A09 | Security Logging & Monitoring Failures | Logs structurés JSON pour chaque décision métier sensible (ingestion, doublon rejeté, dépassement de quota, exception) ; jamais de payload client brut ni de clé API dans les logs | `LogClientEventUseCase.php`, `config/packages/monolog.yaml` |
| A10 | Server-Side Request Forgery (SSRF) | Aucun appel sortant piloté par une entrée utilisateur dans ce périmètre ; si des webhooks sortants sont ajoutés plus tard, valider/allowlister les hôtes de destination sera requis | — (risque non applicable au périmètre actuel) |

## OWASP API Security Top 10 (2023)

| # | Risque | Mitigation |
|---|---|---|
| API1 — Broken Object Level Authorization | `client_id` porté par le payload authentifié, jamais déduit d'un paramètre d'URL non vérifié |
| API2 — Broken Authentication | Voir A07 ci-dessus |
| API4 — Unrestricted Resource Consumption | **Rate limiting** (`sliding_window`, 200 req/min/IP) sur l'endpoint d'ingestion — protège contre le flooding et les coûts d'infrastructure incontrôlés | `config/packages/rate_limiter.yaml`, `EventController.php` |
| API8 — Security Misconfiguration | Voir A05 |

## Points d'attention pour un déploiement production réel

Ce lab pose les fondations ; une mise en production réelle devrait en plus couvrir :

- **Rotation des clés API** : remplacer le hash statique en `.env` par un stockage en Google Secret Manager avec rotation planifiée, et un modèle multi-clé par client (actuellement une seule clé partagée pour la démo).
- **WAF / Cloud Armor** en frontal de Cloud Run pour un filtrage L7 complémentaire au rate limiting applicatif.
- **Scan de vulnérabilités d'image** (Trivy / Artifact Registry vulnerability scanning) intégré en CI en plus de `composer audit`.
- **Audit trail** dédié et immuable pour les événements de facturation, distinct des logs applicatifs, si des exigences de conformité (SOC2, PCI si pertinent) s'appliquent.
