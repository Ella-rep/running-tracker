# GitHub Copilot Instructions

## Project Overview
Application de suivi running avec :
- **Backend** : Symfony 7 + API Platform 3 (REST auto-généré, doc Swagger)
- **Auth** : JWT via LexikJWTAuthenticationBundle
- **BDD** : PostgreSQL 15 + Doctrine ORM + migrations automatiques
- **Frontend** : Twig (pages) + JS vanilla (interactions via API + widgets dashboard)
- **Déploiement** : Docker Compose (Debian + PHP 8.4-FPM + Nginx + PostgreSQL)

Copilot should generate **production-ready, secure, and maintainable** code.

---

## General Guidelines
- Always generate code with a **backend API** first approach in mind.
- Always write **complete, runnable code** with necessary imports.
- Use **clear, descriptive variable and function names**.
- Include **docstrings** for all public functions and classes.
- Avoid deprecated APIs and insecure patterns.
- Always use **/caveman** to limit tokens in responses and ensure concise code generation.


## Security & Performance
- Validate all external inputs.
- Escape/parameterize SQL queries.
- Avoid blocking operations in async code.
- Optimize for readability first, then performance.
- Write unit tests for all new functions.
- Include example test cases in generated code when possible.
- If unsure about implementation details, generate TODO comments.
- Prefer clarity over brevity in generated code.
- Always assume code will be reviewed by another developer.
