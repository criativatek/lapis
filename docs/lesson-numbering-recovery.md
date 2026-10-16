# Recuperação da 0.169.0 (números de lição indicados pelo professor)

Este procedimento cobre o caso de a 0.169.0 ter de ser retirada ou contida depois do deploy. Nunca apaga aulas,
sumários nem auditoria, e não usa o restauro integral da base como recuperação normal. A regra da numeração está em
`docs/adr/0016-lesson-numbering-fixed-and-suggested.md`.

**O que a 0.169.0 escreve que a 0.168.0 não sabe ler:**

| Dado novo | Como a 0.168.0 (e anteriores) o trataria |
|---|---|
| `lessons.lesson_number_manual = true` (número indicado pelo professor) | Ignora a marca. Volta a derivar as aulas abertas pela posição na turma e **escreve por cima** do número indicado. |
| `lessons.lesson_number_end` (intervalo «11–12») | Não o lê: a aula passa a contar como uma só lição e o fim fica órfão. |
| Aulas lecionadas com número fora da posição (uma correção manual a meio do ano) | A guarda antiga compara com a posição e **recusa** eliminar, inserir, registar ausência ou reconciliar o horário nessa turma. |

As colunas ficam na base em qualquer cenário: **nunca `migrate:rollback`**, que as apagaria com os números lá dentro.

## 1. Que cenário? (com a 0.169.0 ainda instalada)

Entrar em manutenção, para que nada seja gravado entre a verificação e a decisão, e verificar — **guardando já o ficheiro
dos números, fora do diretório do pacote de deploy**:

```bash
cd $APP_DIR            # o diretório da aplicação, ver docs/deployment.md
php artisan down --retry=60
php artisan lapis:lesson-numbering-recovery-check --export=/var/backups/lapispro/lesson-numbers-$(date +%F).json
```

O comando só lê. Conta, por organização, as aulas com número indicado e as com intervalo, e diz o cenário (só ids,
números e contagens — nunca nomes). O ficheiro leva, por aula, `lesson_ulid`, `class_ulid`, `organization_id`,
`lesson_number`, `lesson_number_end` e `lesson_number_manual`.

- **sai com 0** → cenário a): não há nenhum número indicado nem intervalo;
- **sai com 1** → cenário b): há números que só a 0.169.0 respeita.

## 2. Cenário a) — logo a seguir ao deploy, antes de qualquer número indicado

O rollback só de código para a 0.168.0 é, aqui, uma **recuperação funcional completa**:

1. Com a aplicação ainda em manutenção, repor o pacote anterior (`update-0.168.0.tgz`) com o bloco de deploy de
   `docs/deployment.md`, **sem** `migrate` e **sem** `migrate:rollback`.
2. `php artisan config:cache && php artisan route:cache && php artisan view:cache`, `php artisan up`, reiniciar o SSR.
3. `php artisan lapis:release-check --expect-version=0.168.0 --expect-commit=<commit da 0.168.0>`.

As duas colunas ficam na base, vazias e inertes: a 0.168.0 ignora-as.

## 3. Cenário b) — depois de haver números indicados

**Não se repõe código abaixo da 0.169.0.** Seria um rollback que reescreve os números dos professores. A alternativa
segura tem dois passos.

### 3.1 Conter: congelar a numeração (a 0.169.0 fica instalada)

```bash
cd $APP_DIR
grep -q '^LAPIS_LESSON_NUMBERING_FROZEN=' .env \
  && sed -i 's/^LAPIS_LESSON_NUMBERING_FROZEN=.*/LAPIS_LESSON_NUMBERING_FROZEN=true/' .env \
  || echo 'LAPIS_LESSON_NUMBERING_FROZEN=true' >> .env
php artisan config:cache
php artisan lapis:lesson-numbering-recovery-check     # tem de dizer «numeração congelada: ligada»
php artisan up
```

Com a numeração congelada:

- **Nenhum número que já exista muda.** O triplo (`lesson_number`, `lesson_number_end`, `lesson_number_manual`) de uma
  aula que já tem número nunca é alterado: nem pelo professor, nem pela renumeração automática (`LessonNumbering` só
  escreve aulas cujo número gravado é NULL), nem pela passagem do número numa ausência do professor (a aula ausente
  continua a ficar sem número, como sempre).
- O professor continua a **guardar sumários**. O campo «N.º da lição» fica só de leitura, com a frase «A numeração das
  lições está temporariamente bloqueada. Pode guardar o sumário sem alterar o número.»; indicar ou repor um número
  (e a pré-visualização) responde 422 com essa frase.
- **As aulas novas** (sem número) recebem a sugestão normal. A guarda do histórico mantém-se.
- **Restauros de backup continuam permitidos:** copiam o número tal como está, não derivam. (Restaurar para uma turma
  que já tem aulas continua bloqueado se as lições fixas colidirem.)
- O que **não** acontece enquanto estiver congelada: a numeração das aulas abertas não acompanha eliminações, inserções
  ou ausências — fica como estava até o congelamento acabar.

### 3.2 Corrigir: avançar, não recuar

- **O defeito é da numeração:** corrigir numa 0.169.x a partir de `main`, instalá-la pelo fluxo normal e só depois
  desligar o congelamento (`LAPIS_LESSON_NUMBERING_FROZEN=false`, `php artisan config:cache`).
- **O defeito é noutra parte da 0.169.0 e o código tem de sair:** construir uma 0.169.x com `git revert` do commit
  culpado, mantendo a numeração. Nunca a 0.168.0.

### 3.3 Se por engano correu código antigo

Voltar à 0.169.x (pacote da 0.169.0 ou posterior) e repor os números a partir do ficheiro do passo 1:

```bash
php artisan lapis:lesson-numbering-recovery-check --restore=/var/backups/lapispro/lesson-numbers-AAAA-MM-DD.json            # só relata
php artisan lapis:lesson-numbering-recovery-check --restore=/var/backups/lapispro/lesson-numbers-AAAA-MM-DD.json --apply    # repõe
```

Sem `--apply` só relata o que mudaria (por aula: id, turma, número atual → número do ficheiro). Com `--apply`, numa
só transação, repõe o triplo nas aulas que ainda existem (por ulid, e só se a turma for a mesma), e depois renumera as
aulas livres das turmas tocadas pelo caminho automático, à volta dos números repostos. Um ficheiro inválido não escreve
nada. **A reposição não é recusada com a numeração congelada:** repor é precisamente o que se quer; a renumeração
seguinte, essa, respeita o congelamento (só escreve aulas sem número). É idempotente. Aulas que entretanto foram
eliminadas contam como «já não existem» e ficam por repor.

### 3.4 O que não se faz no cenário b)

- Repor a 0.168.0 ou anterior.
- `migrate:rollback`.
- Corrigir números à mão na base.
- Restaurar a base inteira a partir de um dump.

## 4. Verificar no fim

- `php artisan lapis:lesson-numbering-recovery-check` — as contagens por organização (números indicados e intervalos)
  são iguais às do ficheiro exportado no passo 1 (o número de linhas do ficheiro é a soma das aulas com número
  indicado ou intervalo).
- `php artisan lapis:lesson-numbering-recovery-check --restore=<ficheiro>` — deve dizer «A repor: 0 aula(s)».
- `php artisan lapis:release-check` com a versão esperada.

## 5. Ensaio

Coberto por testes automáticos (`tests/Feature/Console/LessonNumberingRecoveryCheckTest.php`,
`tests/Feature/Lessons/LessonNumberingFreezeTest.php`): 0 e 1 do comando, exportação só com ids e números, reposição
em simulação e com `--apply` (inclusive congelada), ficheiro inválido sem escritas, e a contenção a não deixar mudar
nenhum número existente.
