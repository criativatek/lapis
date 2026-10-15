# Recuperação da 0.167.0 (autoavaliação com critérios herdados)

Este procedimento cobre o caso de a 0.167.0 ter de ser retirada ou contida depois do deploy. Nunca apaga respostas,
modelos nem auditoria, e não usa o restauro integral da base como recuperação normal.

**O que a 0.167.0 escreve que a 0.166.0 não sabe ler:**

| Dado novo | Como a 0.166.0 o trataria |
|---|---|
| `self_assessment_responses.numeric_value` (respostas em 0 a 20, percentagem, escalas próprias) | Não o lê. O formulário diz «não há níveis… fica por responder» e Resultados, Pauta e exportações mostram vazio, embora o aluno tenha respondido. Os backups gerados pela 0.166.0 (schema 15) não o levam. |
| Duas autoavaliações do mesmo aluno no mesmo período (uma nova depois de «Iniciar nova autoavaliação») | Escolhe uma pela ordem em que a base as devolve, que não é garantida. Pode mostrar a anterior como se fosse a atual. |
| Modelos retirados por «nova autoavaliação», com respostas | A ficha do professor deixa de mostrar a autoavaliação anterior. Os dados ficam, mas ninguém os vê pela interface. |
| Marcas «critérios anteriores» e «anterior» | Não existem. Uma resposta em percentagem aparece ao lado de uma escala 1 a 5 sem aviso. |

## 1. Que cenário? (com a 0.167.0 ainda instalada)

Entrar em manutenção, para que nada seja gravado entre a verificação e a decisão, e verificar:

```bash
cd $APP_DIR            # o diretório da aplicação, ver docs/deployment.md
php artisan down --retry=60
php artisan lapis:self-assessment-recovery-check
```

O comando é só de leitura. Mostra quantas respostas numéricas existem, quantos alunos têm duas autoavaliações no mesmo
período e quantas novas autoavaliações foram iniciadas, e diz qual é o cenário:

- **sai com 0** → cenário a): ainda não há nada que a 0.166.0 não saiba ler;
- **sai com 1** → cenário b): há dados que só a 0.167.0 apresenta corretamente.

## 2. Cenário a) — logo a seguir ao deploy, antes de gravações novas

O rollback só de código para a 0.166.0 é, aqui, uma **recuperação funcional completa**:

1. Com a aplicação ainda em manutenção, repor o pacote anterior (`update-0.166.0.tgz`) com o bloco de deploy de
   `docs/deployment.md`, **sem** `migrate` e **sem** `migrate:rollback`.
2. `php artisan config:cache && php artisan route:cache && php artisan view:cache`, `php artisan up`, reiniciar o SSR.
3. `php artisan lapis:release-check --expect-version=0.166.0 --expect-commit=5f4da91`.

A coluna `numeric_value` fica na base, vazia e inerte: a 0.166.0 ignora-a, e uma gravação feita pela 0.166.0 não lhe
toca. Os modelos que acompanharam a turma numa migração feita na 0.167.0 são exatamente o que a 0.166.0 faria na
visita seguinte. As reflexões personalizadas aparecem com a redação gravada.

Não se faz `migrate:rollback`: retirar a coluna não ganha nada e, se entretanto alguém tivesse gravado, apagava
respostas.

## 3. Cenário b) — depois de haver respostas numéricas ou novas autoavaliações

**Não se repõe a 0.166.0.** Seria um rollback que preserva os dados mas os apresenta mal, pelos motivos da tabela
acima: respostas escondidas, a autoavaliação errada escolhida ao acaso, exportações que perdem os números. Não é
recuperação. A alternativa segura tem dois passos.

### 3.1 Conter: modo só de leitura da autoavaliação (a 0.167.0 fica instalada)

```bash
cd $APP_DIR
grep -q '^LAPIS_SELF_ASSESSMENT_READ_ONLY=' .env \
  && sed -i 's/^LAPIS_SELF_ASSESSMENT_READ_ONLY=.*/LAPIS_SELF_ASSESSMENT_READ_ONLY=true/' .env \
  || echo 'LAPIS_SELF_ASSESSMENT_READ_ONLY=true' >> .env
php artisan config:cache
php artisan lapis:self-assessment-recovery-check     # tem de dizer «modo só de leitura: ligado»
php artisan up
```

Com o modo ligado:

- Tudo continua visível e apresentado pela 0.167.0, sem informação incorreta: respostas numéricas, a autoavaliação
  atual e a anterior, e as marcas «critérios anteriores».
- **Nenhuma escrita na autoavaliação.** Gravar pelo professor ou pela ligação do aluno, iniciar uma nova
  autoavaliação e personalizar reflexões são recusados com uma mensagem («A autoavaliação está temporariamente só de
  leitura…»). Abrir um formulário também não cria nem retira modelos. Não há escritas incompatíveis.
- A migração de turmas continua a funcionar. O modelo sem respostas não acompanha a turma no momento: acompanha na
  primeira visita depois de o modo ser desligado (o caminho que já existia).
- Nada é apagado nem alterado: respostas, modelos e auditoria ficam como estão.

### 3.2 Corrigir: avançar, não recuar

- **O defeito é da autoavaliação:** corrigir numa 0.167.x a partir de `main` e instalá-la pelo fluxo normal. Depois
  desligar o modo só de leitura (pôr `LAPIS_SELF_ASSESSMENT_READ_ONLY=false`, `php artisan config:cache`).
- **O defeito é noutra parte da 0.167.0 e o código tem de sair:** construir uma 0.167.x com `git revert` do commit
  culpado, mantendo a leitura da autoavaliação. Nunca a 0.166.0.

### 3.3 O que não se faz no cenário b)

- Repor a 0.166.0.
- `migrate:rollback`: retira a coluna `numeric_value` **com as respostas que lá estão**.
- Apagar autoavaliações, respostas ou modelos novos, ou reativar modelos retirados à mão.
- Restaurar a base inteira a partir de um dump.

### 3.4 Limitações enquanto o modo só de leitura está ligado

- Professores e alunos não conseguem preencher nem corrigir autoavaliações. A ligação do aluno abre, mas não grava.
- O restauro de um backup (Exportar/Importar dados) **não** é bloqueado e continua a escrever autoavaliações. Num
  incidente, não restaurar backups até o modo ser desligado.
- A pré-visualização de uma migração continua a dizer que um modelo sem respostas acompanha a nova versão. Acompanha,
  mas só quando o modo for desligado.

## 4. Ensaio

Ensaiado a 2026-10-15 numa base isolada MySQL 8.0.43 com dados fictícios (ver a descrição da PR #70): o cenário a)
com a 0.166.0 a ler e a gravar sobre o esquema novo; o cenário b) com a demonstração do que a 0.166.0 esconderia ou
escolheria mal, o modo só de leitura a recusar todas as escritas sem alterar uma linha, e a reabertura das escritas
depois de desligado.
