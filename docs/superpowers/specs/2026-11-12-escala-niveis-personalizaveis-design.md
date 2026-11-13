# Escala com níveis personalizáveis — desenho

**Data:** 2026-11-12 · **Versão alvo:** 0.196.0 · **Ramo:** `feat/scale-custom-levels` · **Migrations:** nenhuma

Substitui a restrição «só os nomes são editáveis» da spec
`2026-11-11-mencoes-personalizadas-escala-design.md`. O resto dessa spec mantém-se
(escala derivada por perfil, copy-on-write, a escala do sistema nunca é tocada).

## 1. Porquê

Um professor (org 31) cuja escola usa quatro menções tentou encaixá-las nos cinco níveis
deslocando os nomes: 20–49 % passou a ler-se «Suficiente» e 90–100 % «Não Observado». O
diálogo «Personalizar menções» só deixava mudar nomes; os cortes e o número de níveis eram
fixos. Cada escola tem a sua escala e o Lapispro tem de a aceitar.

## 2. Decisões do utilizador (não reabrir)

1. **Por defeito nada muda.** Perfis novos continuam a abrir na «Escala 1 a 5» do sistema com
   Fraco / Insuficiente / Suficiente / Bom / Muito Bom e os cortes atuais (0, 20, 50, 70, 90).
2. **Número de níveis:** 5 por defeito; o professor pode ter entre **2 e 10**.
3. **Designação** de cada nível editável (como hoje; nomes repetidos continuam proibidos,
   sem distinguir maiúsculas nem espaços nas pontas).
4. **Cortes:** o professor escreve só «a partir de X %» em cada nível, **inteiros**. O
   primeiro nível começa sempre em 0 e não se edita.
5. **Inovar:** cada escola define no Inovar o seu número de níveis e os seus códigos, por
   isso o professor **escreve** o código de cada nível (texto livre curto; vazio = sem
   correspondência). Por defeito mantêm-se F, I, S, B, MB, com **sugestão automática**
   quando o nome coincide com um dos atuais. Códigos repetidos são aceites com aviso.
   (Revisto em 2026-11-12: a primeira versão desta decisão era uma lista fechada.)
6. **Negativa/positiva:** o professor indica «a partir de que nível é positiva»; a sugestão
   por defeito é o primeiro nível cujo corte é ≥ 50 %.
7. A personalização continua a ser **por perfil**.

## 3. Regras

### 3.1 Dos cortes às bandas

Dados os cortes inteiros `c1 = 0 < c2 < … < cN ≤ 100`, o nível `i` guarda:

- `band_min_normalized` = `0.000000` se `i = 1`; senão `ci − 0.5` (ex.: 50 → `49.500000`);
- `band_max_normalized` = `100.000000` se `i = N`; senão `c(i+1) − 0.500001`
  (ex.: próximo corte 50 → `49.499999`).

É exatamente a convenção do `SystemScalesSeeder` — com os cortes 0/20/50/70/90 o resultado
tem de ser idêntico, campo a campo, à «Escala 1 a 5» do sistema (teste obrigatório). Cobre
0–100 sem buracos nem sobreposições por construção.

### 3.2 Os outros campos de cada nível

- `sequence` = `numeric_value` = posição (1…N); `code` = a posição como texto («1»…«N»);
- `normalized_value` = `null` (como no seeder);
- `is_negative` = `posição < first_positive_level`;
- `inovar_code` = o escolhido, ou `null`.

Escala derivada: `kind = level`, `min_value = 1`, `max_value = N`,
`derived_from_scale_id` = a escala do sistema de origem.

### 3.3 Validação (servidor)

- `levels`: array, 2 a 10 elementos;
- `levels.*.label`: obrigatório, 1–64 caracteres; sem repetidos;
- `levels.*.from_percent`: inteiro 0–100; o primeiro tem de ser 0; estritamente crescente;
- `levels.*.inovar_code`: `null` ou texto de 1 a 8 caracteres (o tamanho da coluna), sem
  espaços; guardado sem espaços nas pontas;
- `first_positive_level`: inteiro entre 1 e N. (1 = sem níveis negativos; é permitido —
  `ScaleThreshold` já devolve `null` nesse caso e as leituras de negativa ficam sem fronteira.)
- `profile_name`: como hoje.

Mensagens em pt-PT, por campo (`levels.2.from_percent`, etc.), para o diálogo as mostrar
junto da linha certa.

### 3.4 Copy-on-write

Como hoje, com uma diferença: a escala de origem para a derivação continua a ser a do
sistema, mas os níveis **vêm do pedido**, não da cópia da origem.

- Fonte é derivada da organização e **não congelada** → sincroniza em cima, por `sequence`:
  atualiza os que existem, cria os que faltam, apaga os que sobram; atualiza `max_value` e o
  nome se o número de níveis mudou. Se apagar um nível for recusado pela base de dados
  (alguma referência inesperada), não se força: cria-se uma derivada nova.
- Fonte é do sistema, ou derivada **congelada** → escala nova. As versões de perfil ativas,
  classificações decididas e fotografias continuam a apontar à antiga, intacta.

Nome: `Escala 1 a {N} · {perfil}` (+ ` (2)`, ` (3)`… se já existir).

O guard de origem mantém-se: só se personaliza a partir de uma escala de níveis com bandas.

### 3.5 Inovar

`InovarCodeResolver` deixa de ter uma lista fechada: o código de um nível é válido quando é
um texto não vazio. F, I, S, B, MB ficam só como sugestões por defeito. `covers()` e
`missingFrom()` mantêm o significado (todos os níveis têm de ter código) e a exportação
escreve o código da própria escala. Todos os consumidores de `inovar_code` têm de seguir os
códigos da escala, sem listas literais.

O diálogo avisa, sem bloquear: quando algum nível fica sem código («Sem código em todos os
níveis, esta escala não exporta para o Inovar.») e quando dois níveis têm o mesmo código
(«Dois níveis têm o mesmo código: no Inovar ficam indistinguíveis.»).

## 4. Interface

O botão passa de «Personalizar menções» a **«Personalizar escala»** e o diálogo ganha o
mesmo título. Descrição: «Os níveis que a sua escola usa: quantos são, como se chamam e a
partir de que percentagem começa cada um.»

Uma linha por nível, por ordem:

| N.º | Designação | A partir de (%) | Inovar | remover |
|---|---|---|---|---|

- O «a partir de» do nível 1 mostra `0` e está desativado.
- Ao lado de cada linha, o intervalo resultante em texto («20 – 49 %»), calculado no cliente
  a partir dos cortes, para o professor ver o que está a definir.
- **«Adicionar nível»** acrescenta no fim (desativado aos 10); o corte sugerido é o ponto
  médio entre o último corte e 100, arredondado, e maior que o último.
- **Remover** em cada linha (desativado quando restam 2). Remover o nível 1 faz o novo
  primeiro nível passar a começar em 0.
- **«A partir de que nível é positiva»**: lista com as designações atuais. Abre no que a
  escala tem (na de origem, «Suficiente», o primeiro corte ≥ 50). A fronteira é de um
  **nível**, não de uma percentagem: mexer nos cortes nunca a muda, e ao remover um nível
  abaixo dela acompanha o nível. Só «Repor» volta à sugestão dos 50 %. (Corrigido no QA: a
  primeira versão recalculava-a a cada corte, e baixar «Suficiente» para 45 % punha-o
  negativo em silêncio.)
- **Inovar**: campo de texto curto por nível («Código no Inovar — o que a sua escola definiu
  no Inovar para este nível»). Ao escrever uma designação que coincide (sem
  maiúsculas/acentos/espaços) com Fraco, Insuficiente, Suficiente, Bom ou Muito Bom, o código
  correspondente é preenchido — só enquanto o professor não tiver escrito nesse campo.
- **«Repor a escala do Lapispro»** volta aos cinco níveis de origem.
- A legenda «Lapispro: …» por linha **desaparece**: mostrava o nome já personalizado como se
  fosse o original e deixa de fazer sentido com níveis variáveis.

O diálogo abre pré-preenchido com os níveis da escala selecionada (designação, corte,
Inovar, fronteira). Para isso `formOptions()` passa, por nível: `code`, `label`,
`from_percent` (inteiro: 0 no primeiro; `band_min + 0.5` nos restantes), `inovar_code`,
`is_negative`.

Erros de validação do servidor aparecem junto da linha. Validação equivalente no cliente
(cortes crescentes, nomes repetidos) só para feedback imediato — o servidor é a autoridade.

## 5. Servidor

- `POST scales/{scale}/mencoes` mantém a rota e o nome; o corpo passa a ser
  `{ levels: [{label, from_percent, inovar_code}], first_positive_level, profile_name }`.
  O campo `labels` deixa de existir (o único cliente é o `ProfileForm.vue`).
- `ScaleMentionsRequest` → regras de §3.3.
- `DeriveScaleWithLabels` passa a receber os níveis completos. Pode ser renomeado
  (`DeriveCustomScale`) se o nome antigo ficar enganador; continua a ser **o único sítio**
  que cria estas escalas e o único que converte cortes em bandas.
- Auditoria: mantém `scale.mentions_customised`, com os níveis completos e
  `first_positive_level` nos metadados.

## 6. O que tem de continuar a funcionar sem alterações

Pauta, estatística, análise avançada, sinopse, autoavaliação, relatórios, fotografias
intercalares, exportação Inovar e Excel, partilha de configuração e backup leem os níveis da
base de dados. Antes de dar por terminado, procurar no código suposições de «exatamente 5
níveis» ou de códigos F…MB fixos (cores por índice, larguras de coluna, `size:5`, listas
literais) e corrigir ou reportar as que existirem. Em particular: partilha de configuração
(`GenerateConfigurationPackage` / `ValidateConfigurationPackage`) e backup
(`ValidateBackupPayload`, `WriteAssessmentStructure`) têm de aceitar e reproduzir uma escala
de 4 e de 7 níveis.

## 7. Testes

- Unidade do conversor: cortes por defeito ⇒ bandas iguais às do seeder; 4 níveis; 10 níveis;
  corte 100 no último nível.
- `ScaleMentionsTest` / `DeriveScaleWithLabelsTest` atualizados: criar com 4 e com 7 níveis;
  editar em cima (não congelada) reduzindo e aumentando níveis; congelada cria nova e a
  antiga fica intacta; validações (menos de 2, mais de 10, cortes não crescentes, primeiro ≠ 0,
  repetidos, Inovar inválido, fronteira fora do intervalo); isolamento entre organizações.
- Uma proposta calculada numa turma com escala de 4 níveis cai no nível certo nas fronteiras
  (49,4 / 49,5 com corte em 50).
- `ScaleThreshold` devolve o corte escolhido pelo professor.
- Inovar: exporta com códigos repetidos; recusa, com os nomes, quando falta um.
- Partilha de configuração e backup: ida e volta de uma escala de 4 níveis.
- `ProfileForm.test.ts`: adicionar/remover nível, limites 2 e 10, sugestão Inovar, sugestão
  da fronteira, «Repor», corpo do pedido. Atenção: os testes existentes dependem da ORDEM
  dos `useForm` (o das menções é o 3.º).
- Se alguma prop da Pauta mudar, regenerar `tests/Fixtures/AdvancedAnalysis/` (correr
  `BasePagesCharacterizationTest` e `BaseSurfacesUnchangedTest`).

## 8. Fora do âmbito

Cortes com décimas; escalas de intervalo (0–20) com menções; migração automática de perfis
existentes; alterar a «Escala 1 a 5» do sistema; correção automática da escala do professor
que originou o pedido (ele refaz no diálogo novo; como a versão dele está ativa, nasce uma
escala nova e uma versão nova do perfil, e cada turma passa por «Rever e aplicar alterações»).

## 9. Entrega

`config/app.php` → 0.196.0; `CHANGELOG.md`; `docs/domain-model.md` (secção das escalas);
artigo do Centro de Ajuda sobre perfis, se mencionar «Personalizar menções». Pint, Larastan
e testes verdes. Sem `prettier --write` global.

## 10. Escala da organização (acrescentado em 2026-11-12)

**Decisão do utilizador:** a organização tem de poder definir a escala uma vez para todos
os perfis.

- O diálogo «Personalizar escala» ganha **«Nome da escala»** (obrigatório, único na
  organização; pré-preenchido com `Escala 1 a {N} · {perfil}`) e a caixa **«Usar como escala
  da organização — os perfis novos abrem com ela»**.
- A organização guarda um apontador para a sua escala por defeito. Usa-se um mecanismo de
  definições por organização se já existir; senão, uma migration reversível com
  `organizations.default_scale_id` (nullable, FK para `scales`, `nullOnDelete`). Neste caso
  a secção «Migrations: nenhuma» do cabeçalho deixa de valer.
- **Perfis novos** abrem com a escala da organização; sem ela (ou se foi apagada), com a
  «Escala 1 a 5» do sistema, como hoje.
- **Perfis existentes não mudam sozinhos** (versão ativa é congelada; «sem migração
  automática» mantém-se). Escolhem a escala da organização na lista, onde aparece em primeiro
  lugar nas «Escalas personalizadas» com « (escala da organização)».
- Copy-on-write: se guardar criar uma escala nova e a caixa estiver marcada, o apontador
  passa para a nova. Desmarcar só limpa o apontador se ele apontava para esta escala.
- Autorização: a mesma de quem gere os perfis da organização; isolamento entre organizações
  testado.
- Fora do âmbito: aplicar a escala da organização, de uma vez, a todos os perfis existentes
  (exigiria criar versões novas de cada perfil e migrar cada turma).
