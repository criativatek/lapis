# Lapispro

*Mais simples. Mais tempo.*

App web modular para professores do básico e secundário em Portugal: perfis de
avaliação, turmas, instrumentos, grelhas, propostas de classificação
determinísticas, evidências, autoavaliações, intervenções e relatórios.

> ⚠️ A aplicação trata dados de alunos, na maioria **menores**. Proteção de dados,
> isolamento por organização e rastreabilidade são requisitos estruturais.

## Stack

Laravel 13.20 · PHP 8.4 · MySQL 9.7 (porta local **3308**) · Vue 3 + TypeScript ·
Inertia · Vite · Tailwind 4 · Fortify (2FA + passkeys) · PHPUnit 12 · Pint ·
Larastan (nível 7).

## Quickstart (local — Herd + DBngin, Windows)

Passos completos e específicos da máquina em **[docs/setup-local.md](docs/setup-local.md)**.

```powershell
git clone git@github.com:criativatek/lapis.git
cd lapis
composer setup       # install, .env, key:generate, migrate, npm install, npm run build
php artisan db:seed  # EntitlementsSeeder (obrigatório) + DemoDataSeeder
```

App em **http://lapis.test** (`D:\HERD` é um parked path do Herd — sem `herd link`).

**Login de demonstração** (via `DemoDataSeeder`): `ana.martins@lapis.test` / `password`.
Cria a turma «7.º A» de Português com ingresso tardio, uma ausência e dados para
todas as vistas.

## Comandos do dia-a-dia

```powershell
php artisan test         # ~205 testes, SQLite in-memory
composer lint            # Pint
composer types:check     # Larastan (nível 7)
composer ci:check        # tudo o que a CI corre — verde antes de fechar
npm run dev              # Vite
```

CI (`.github/workflows/tests.yml`) corre em **MySQL 9.7** — é lá que CHECK
constraints, colunas geradas e a collation são realmente exercidos (local é SQLite).

## Docs

| Documento | O que é |
|---|---|
| [docs/setup-local.md](docs/setup-local.md) | Montar o ambiente local (Herd + DBngin) |
| [docs/domain-model.md](docs/domain-model.md) | O modelo de dados de avaliação — **autoridade** |
| [docs/adr/](docs/adr/) | Decisões e o seu porquê (MySQL, tenancy, starter kit, regras) |
| [docs/workflow.md](docs/workflow.md) | Fluxo de trabalho assistido por IA (orquestrador + Codex) |
| [docs/status.md](docs/status.md) | O que está feito, o que falta, bloqueadores |
| [docs/backoffice.md](docs/backoffice.md) | Backoffice `/admin`: gerir contas, criar professores, configurar SMTP, impersonar |
| [docs/deployment.md](docs/deployment.md) | Deploy + redeploy (CloudPanel · lapis.criativatek.com) |
| [CLAUDE.md](CLAUDE.md) · [AGENTS.md](AGENTS.md) | Regras do projeto para Claude Code / Codex |

## Trabalhar com IA

Este projeto é desenvolvido com Claude Code + Codex, e o fluxo é **portável**: os
agentes vivem em [.claude/agents/](.claude/agents/) (explorer, architect,
implementer, reviewer), o Codex lê o [AGENTS.md](AGENTS.md), e o
**[docs/workflow.md](docs/workflow.md)** explica quando usar cada um, o loop por
slice, a verificação no browser e as armadilhas de ambiente. Modelos: Sonnet
(implementação/revisão), Haiku (exploração), Opus (raro). **Codex usa-se
ativamente.** Sem Fable.

## Regras invioláveis (resumo — ver [CLAUDE.md](CLAUDE.md))

- **Tenancy no container**, nunca na sessão; o global scope lança se não houver tenant.
- **Vazio ≠ zero** — um aluno sem resultado calculável não recebe nota, nunca um `0`.
- **O professor decide** — o sistema propõe, nunca confirma sozinho.
