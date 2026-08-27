# AGENTS.md — Lapispro

Instruções para agentes de código (Codex, e qualquer ferramenta que leia
`AGENTS.md`). O Claude Code lê `CLAUDE.md`; este ficheiro é o espelho para o
Codex. **Fonte da verdade completa:** `CLAUDE.md` (regras do projeto) e `docs/`.
Lê-os — aqui fica só o essencial para uma sessão.

> A aplicação trata dados de alunos, na maioria menores. Proteção de dados,
> isolamento por organização e rastreabilidade são requisitos estruturais, não
> um polimento final.

## Stack

Laravel 13.20 · PHP 8.4 · MySQL 9.7 (porta local **3308**) · Vue 3 + TypeScript ·
Inertia · Vite · Tailwind 4 · Fortify · PHPUnit 12 · Pint · Larastan (nível 7).

## Regras de arquitetura (não-negociáveis)

- **Tenancy no container**, nunca na sessão (`App\Support\Tenancy\CurrentOrganization`).
  O global scope **lança** `TenantNotResolvedException` sem tenant — não o tornes um
  fallback para "todas as organizações". Jobs/comandos/seeders/testes entram num
  tenant com `CurrentOrganization::runFor(...)`. **Nunca `exists:` em dados
  tenant-owned** — usa `new BelongsToCurrentOrganization(Model::class)`. Ver ADR-0002.
- **Entitlements em tabelas**, não hardcoded. Rotas com `->middleware('module:x')`.
- **English no código/BD, pt-PT na UI.** Locale `pt_PT`, `Europe/Lisbon`, `utf8mb4_unicode_ci`.
- **ULID** nos URLs (`getRouteKeyName`), nunca ids sequenciais.
- **DECIMAL**, nunca `float`, para o que vira nota.
- Form Requests validam · Policies autorizam · Actions/Services têm os casos de uso · Enums para estados estáveis. Mass assignment opt-in via `#[Fillable]`.
- Nomes descritivos, nunca abreviados (`organization` não `org`).

## Regras de avaliação (§13.3) — não inventar

- **Vazio ≠ zero.** Nunca um valor mágico; usa os estados de resultado explícitos.
- **"Não aplicável" não entra no denominador.**
- **Ingresso tardio** não transforma instrumentos anteriores em zeros.
- **Versão de perfil ativa é congelada**; alterar cria nova versão, o histórico mantém a referência.
- **O professor decide.** O sistema propõe, nunca confirma sozinho.

## Loop por slice (workflow)

`migration → model → policy → service → controller → routes → Vue → testes →
composer ci:check → verificação no browser → versão+changelog → commit`.
Detalhe completo e armadilhas em **`docs/workflow.md`**. Estado do projeto e o
que falta em **`docs/status.md`**.

## Definition of Done (§30)

Autorização no backend · isolamento por organização considerado · validação no
servidor · estados de erro e loading · testes verdes · Pint e Larastan limpos ·
docs atualizados · sem dados reais nem segredos · migrações reversíveis · impacto
plano/módulo verificado · impacto proteção-de-dados verificado.

**Verifica sempre com `composer ci:check` antes de fechar.**

## Não fazer sem perguntar (§31)

Trocar a stack · trocar o motor de BD · Docker local · portal aluno/encarregado ·
pagamentos · escolher provedor de IA · **enviar dados identificáveis de alunos para
serviço externo** · alterar composição comercial dos planos · alterar fórmulas de
avaliação · apagar histórico · SSO · pacote de multi-tenancy · redesenhar a
estrutura raiz da UI · **deploy para produção**.
