# Menções personalizadas da escala de níveis — desenho

**Data:** 2026-11-11 · **Estado:** aprovado em conversa, por rever no ficheiro · **Versão alvo:** 0.193.0

## 1. Problema

A apreciação qualitativa da Pauta de Avaliação (e de tudo o que lê uma menção:
fotografias, intercalares, relatórios, autoavaliação, exportação Inovar) só
existe quando a escala do perfil tem níveis com bandas de conversão. Hoje só a
escala do sistema «Escala 1 a 5» as tem. Dois efeitos observados em produção:

1. Um professor que criou os perfis em «Percentagem (0 a 100)» vê só números na
   pauta e não percebe porquê (caso de 2026-11-11: três perfis, cinco turmas).
   O formulário do perfil não sugere escala nenhuma.
2. As menções «Fraco, Insuficiente, Suficiente, Bom, Muito Bom» são fixas. Há
   escolas que usam «Muito insuficiente» em vez de «Fraco», ou outra
   terminologia, e o professor não tem forma de a ajustar.

## 2. Decisões tomadas com o utilizador

- **Por defeito nada muda:** o Lapispro continua a mostrar «Fraco, Insuficiente,
  Suficiente, Bom, Muito Bom» a quem não fizer nada. A personalização é uma
  escolha do professor, nunca uma obrigação.
- **Só as menções.** Mantêm-se os cinco níveis, os códigos 1 a 5, as bandas de
  percentagem aprovadas (Q1 do modelo de domínio) e os códigos Inovar. Nem o
  número de níveis nem as fronteiras são editáveis — inventar limiares continua
  proibido (§10.4).
- **Por perfil.** As menções personalizadas pertencem ao perfil de avaliação
  que as pediu; outro perfil pode reutilizá-las, mas não é obrigado.
- **Perfis novos abrem em «Escala 1 a 5».** Quem já está noutra escala vê um
  aviso na Pauta a explicar a ausência de menções, com ligação ao perfil. Nada
  migra sozinho.

## 3. Âmbito

**Dentro:** escala derivada por perfil; diálogo «Personalizar menções» no
formulário do perfil; escala pré-selecionada nos perfis novos; aviso na Pauta;
testes.

**Fora:** editar escalas do sistema; bandas ou número de níveis personalizados;
uma página de gestão de escalas; migração automática de turmas; alterações ao
fluxo «Migrar perfil», que já serve tal como está.

## 4. Abordagem escolhida: escala derivada por perfil

Entre três caminhos — (A) escala da organização derivada da do sistema, (B)
nomes alternativos guardados em JSON na versão do perfil, (C) renomear os níveis
da escala do sistema por organização — escolheu-se **A**.

Razão: tudo o que hoje mostra uma menção lê `ScaleLevel.label` por identidade
do nível. Uma escala derivada É um nível de escala a sério, e por isso a pauta,
as fotografias, os relatórios, a autoavaliação e a exportação Inovar funcionam
sem uma linha alterada. (B) obrigaria a aplicar a substituição em cada um
desses sítios, e qualquer um esquecido mostraria «Fraco» à mesma. (C) contraria
a escolha «por perfil» e a imutabilidade das escalas do sistema.

O modelo de domínio (§2.3) já prevê exatamente isto: `scales.derived_from_scale_id`
(rasto da cópia), `frozen_at` preenchido no primeiro uso por uma versão ativa
(`ActivateProfileVersion::freezeScale`) e a regra «editar uma escala congelada
cria uma escala nova». Nenhuma tabela nem coluna nova.

## 5. Dados e regras

### 5.1 A escala derivada

| Campo | Valor |
|---|---|
| `organization_id` | a organização atual |
| `name` | «Escala 1 a 5 · {nome do perfil}», único na organização; em colisão, «… (2)», «… (3)» |
| `kind` | `level` (copiado) |
| `min_value` / `max_value` | 1 / 5 (copiados) |
| `derived_from_scale_id` | o id da «Escala 1 a 5» do sistema |
| `frozen_at` | null até à primeira ativação de uma versão que a use |

Os cinco `scale_levels` são copiados **campo a campo** da escala de origem —
`code`, `inovar_code`, `sequence`, `numeric_value`, `normalized_value`,
`band_min_normalized`, `band_max_normalized`, `is_negative` — e só `label`
recebe o nome escolhido. O serviço que faz a cópia (`DeriveScaleWithLabels`)
é o único sítio onde isso acontece.

### 5.2 Validação dos nomes

Cinco nomes obrigatórios, 1 a 64 caracteres (o limite da coluna), sem
repetidos depois de aparar espaços e ignorar maiúsculas. A ordem é a dos
níveis (1 a 5) e não se altera.

### 5.3 Editar depois de criada

- **Não congelada** (nenhuma versão ativa a usa): os nomes atualizam-se nos
  mesmos `scale_levels`. Nada mais aponta para eles.
- **Congelada:** cria-se uma escala derivada nova (derivada da mesma origem,
  nome com sufixo numérico), e a versão em rascunho do perfil passa a
  apontar-lhe. As versões antigas, as classificações decididas, as decisões por
  domínio, as respostas de autoavaliação e as fotografias continuam a apontar
  à escala antiga, intacta. É a regra copy-on-write que o modelo de domínio
  decidiu; aqui só se aplica.
- Uma escala derivada que ficou sem uso (nenhuma versão de perfil a aponta)
  não é apagada automaticamente. Fica em «Escalas personalizadas» e pode ser
  reutilizada. YAGNI: apagar é um pedido à parte.

### 5.4 O que uma escala derivada NÃO permite

Não se editam código, bandas, Inovar, `is_negative` nem o número de níveis.
O pedido só aceita os cinco `labels`. Uma escala derivada aparece no
formulário em «Escalas personalizadas», como as de mínimo/máximo, e o botão
«Personalizar menções» continua disponível quando ela está selecionada (para
voltar a mudar os nomes).

## 6. Formulário do perfil (`assessment-profiles/ProfileForm.vue`)

- **Pré-seleção:** no perfil novo, `scale_id` abre com o id da «Escala 1 a 5»
  do sistema (o controlador envia `default_scale_id`). No perfil existente,
  a escala atual, como hoje.
- **Botão «Personalizar menções»**, ao lado de «Criar escala personalizada»,
  ativo quando a escala selecionada é de níveis (`kind = level`) — do sistema
  ou derivada. Para uma escala de intervalo o botão fica inativo com o título
  «Só uma escala de níveis tem menções».
- **Diálogo:** cinco linhas, cada uma com o código fixo («1» a «5»), a menção
  de origem a cinzento como referência e um campo com o nome atual. Texto de
  cabeçalho: «As menções que esta escola usa. Os níveis, as percentagens de
  cada um e a correspondência com o Inovar não mudam.» Botões «Guardar» e
  «Cancelar».
- **Ao guardar:** `POST /scales/{scale}/mencoes` com os cinco nomes. O servidor
  devolve a escala derivada (nova ou atualizada) e o formulário seleciona-a em
  `scale_id`, como já faz com «Criar escala personalizada».
- Nada no formulário do perfil muda para quem não carrega no botão.

## 7. Servidor

- **Rota** `POST scales/{scale}/mencoes` → `ScaleMentionsController::store`,
  módulo `assessment_profiles`, `Gate::authorize('create', Scale::class)`.
  `{scale}` é a escala selecionada no formulário (do sistema ou derivada).
- **Pedido** `ScaleMentionsRequest`: `labels` (array de 5 strings), regras de
  §5.2, e `profile_name` (string, até 120) para batizar a escala.
- **Serviço** `DeriveScaleWithLabels::for(Scale $source, array $labels, string $profileName): Scale`
  - origem do sistema → cria derivada (§5.1);
  - origem derivada e não congelada → atualiza os `labels` em cima;
  - origem derivada e congelada → cria derivada nova a partir da **mesma**
    origem do sistema (`derived_from_scale_id` da antiga), com os nomes novos.
  - Recusa origens que não sejam `kind = level` ou que não tenham cinco níveis
    com bandas (`ValidationException` com mensagem clara).
- **Auditoria:** evento `scale.mentions_customised` com `scale_id`, origem e os
  cinco nomes; sem dados de alunos.
- **`AssessmentProfileController::create`** passa `default_scale_id`.
- **`ScaleController::store`** (mínimo/máximo) não muda.

## 8. Aviso na Pauta

Em `evaluation-sheets/Show.vue`, quando a turma tem perfil e `scaleBands` vem
vazio (a escala não tem níveis com bandas), uma nota de uma linha, no ecrã e
fora do papel (`print-hide`), por cima da grelha:

> Esta escala («{nome da escala}») não tem menções qualitativas, por isso a
> pauta mostra só percentagens. Para ter Suficiente, Bom…, edite o perfil de
> avaliação e escolha a «Escala 1 a 5». [Abrir o perfil]

A ligação vai para `assessment-profiles/{ulid}/edit` do perfil da turma; o
controlador da pauta passa `profile_ulid`. Quem pode ver a pauta mas não gerir
perfis vê a nota sem a ligação. A nota não aparece numa pauta guardada
(`Snapshot.vue`): é uma fotografia, não há nada a configurar nela.

## 9. Migração das turmas existentes

Sem código novo. Mudar a escala do perfil cria a versão 2; «Ativar»; em cada
turma, escolher a versão 2 leva ao fluxo «Migrar perfil» com pré-visualização
(lida na escala de destino, por isso mostra «78 % → 4 · Bom»). Só as propostas
em aberto recalculam; decisões confirmadas ou publicadas e fotografias ficam
como estão. Isto é o comportamento atual, confirmado no código em 2026-11-11.

## 10. Testes

- **Unidade** (`DeriveScaleWithLabelsTest`): copia os cinco níveis campo a
  campo e só muda `label`; nome único com sufixo; origem não congelada
  atualiza em cima; origem congelada cria nova derivada da mesma origem do
  sistema; recusa escala de intervalo e escala sem bandas; validação dos nomes
  (vazio, >64, repetidos ignorando maiúsculas e espaços).
- **Feature:** `POST /scales/{scale}/mencoes` cria a derivada e devolve o id;
  exige o módulo e a permissão; o perfil novo recebe `default_scale_id` da
  «Escala 1 a 5»; uma pauta de perfil com escala derivada escreve «Muito
  insuficiente» em vez de «Fraco» (`BuildEvaluationSheet` lê o nível certo);
  a pauta de um perfil em Percentagem traz a sinalização para a nota e a de um
  perfil em 1 a 5 não; a exportação Inovar de uma derivada escreve o mesmo
  `inovar_code` da origem; ativar uma versão com a derivada congela-a
  (`frozen_at`).
- **Vue:** o diálogo pré-preenche com os nomes atuais, envia os cinco e
  seleciona a escala devolvida; o botão fica inativo numa escala de intervalo;
  o perfil novo abre com a «Escala 1 a 5»; a Pauta mostra a nota só quando
  `scaleBands` está vazio e com a ligação só quando há `profile_ulid`.
- **Guardas existentes:** `ReleaseVersionTest`, Pint, PHPStan, eslint,
  vue-tsc; testes novos de BD também em MySQL (os CHECKs de `scales.kind` não
  existem em SQLite).

## 11. Entrega

Uma PR, versão 0.193.0, só código: nenhuma tabela nem coluna nova, zero
migrations. Deploy pelo procedimento habitual, com autorização expressa.
Resposta de suporte ao professor do caso de 2026-11-11 depois do deploy, com
os passos de §9.
