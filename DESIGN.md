# Design System — Lapispro (aplicação autenticada)

> Criado por /design-consultation a 2026-09-04, a partir de três vozes (Claude
> principal + Codex «Quiet Ledger» + subagente «Papel, Lápis, Tinta»), pesquisa
> competitiva (Additio, iDoceo) e um mockup **aprovado visualmente** (variante
> C2 em `~/.gstack/projects/criativatek-lapis/designs/design-system-20260904/`).
> O `.impeccable.md` continua a mandar no **site público**; este ficheiro manda
> na **app autenticada** e evolui — nunca contradiz — a mesma marca.

## Contexto de produto

- **O que é:** avaliação para professores portugueses do básico/secundário —
  turmas, grelhas, propostas determinísticas, relatórios. Dados de menores.
- **Quando se usa:** fim do dia, portátil da escola ou telemóvel.
- **A coisa memorável (norte de todas as decisões):**
  **«Alguém tratou disto por mim.»** A emoção-assinatura é ALÍVIO.
- **Eureka de posicionamento:** a categoria (Additio «+150 funcionalidades»,
  iDoceo «Powerful. Helpful. Awesome.») vende PODER e mostra grelhas
  arco-íris. Poder é trabalho. O Lapispro mostra o ecrã **depois** do trabalho
  arrumado — o resumo, não a grelha.

## Direcção estética — «Papel, Lápis, Tinta»

- **Direcção:** o caderno de um professor extraordinariamente organizado:
  papel quente, precisão de registo pautado, calma de trabalho já arrumado.
- **A metáfora é o nome do produto:** proposta do sistema = escrita a
  **lápis** (cinza, itálico, sublinhado tracejado); decisão do professor =
  assente a **tinta** (navy, romano). Uma grelha diz de longe o que ainda é do
  sistema e o que já é de quem decide.
- **Decoração:** intencional — tinta de papel no fundo, lavagens âmbar raras;
  **zero padrões, clipes, rasgados ou skeuomorfismo** (decisão do dono:
  «não é preciso infantilizar»). Profissional e calmo, nunca fofo.
- **Referências:** a variante C2 aprovada; anti-referências: grelhas
  arco-íris do edtech, SaaS branco genérico, gamificação.

## Tipografia

- **UI (tudo):** Instrument Sans — mantida; jogo de pesos faz a hierarquia:
  **650** títulos, **600** subtítulos/rotulagem forte, **450** leitura, 520
  meta. Sentence case; maiúsculas só em códigos («7.º B»).
- **Voz humana (dose pequena):** **Newsreader** — APENAS na saudação do dia
  («Boa tarde, Ana.»), nas horas do «Arrumado» (itálico: *Guardado às 18:42*)
  e na frase de fecho (*Tudo guardado — podes fechar.*). Nunca em botões,
  tabelas, títulos de secção ou navegação.
- **Dados/tabelas:** Instrument Sans com `font-variant-numeric: tabular-nums
  lining-nums`; decimais alinhados; números à direita. (Sem terceira fonte.)
- **Escala:** display 32/38·650 — heading 22/28·650 — subheading 17/24·600 —
  body 15/22·450 — table 14/20·450 — label 13/18·600 — meta 12/16·520.
- **Loading:** Google Fonts (Instrument Sans já servida; Newsreader
  `wght@400;500;600` + itálico), com fallback serif/sans real.

## Cor

- **Abordagem:** balanced. Uma regra acima de todas: **âmbar é cuidado/feito,
  nunca CTA e nunca aviso.**
- **Claro (papel):** fundo `#FAF8F2` (papel, nunca branco puro) · cartão
  `#FFFFFF` · tinta/texto e ACÇÃO `#1B2A46` (navy, o actual `--primary`) ·
  secundário `#5C6577` · fios `#E8E4DA` · hover-linha `#F5F1E8` ·
  lápis-proposta `#767E90` · **âmbar** `#F4BB57` + lavagem `#FBEED3` ·
  esmeralda guardado/dados `#0E7A5F` + `#E3F1EA` · erro de sistema `#B0392F`.
- **Escuro («ardósia», mesmo matiz 219 — já instalado na 0.119.0):** fundo
  `#0F1626`-família · superfícies `#16202F`/`#1D2A3F` · fios `#29364D` ·
  texto-giz `#EAE7DE` · secundário `#939DB0` · lápis `#8B93A6` · âmbar
  `#F4BB57` intocado · esmeralda `#3FAE8F` · erro `#D26A62`.
- **Semântica de estados:** continua em `lib/statusTone.ts` /
  `lib/qualitativeTone.ts` (verde/azul/âmbar/vermelho/neutro sobre a paleta da
  casa) — as PÍLULAS de dados não mudam. O âmbar-marca vive no CHROME (item
  activo, foco, régua de secção, coluna activa, pilha do feito).
- **«O vermelho nunca toca numa nota.»** Resultados baixos são tinta como os
  outros — a caneta vermelha é do professor, não da app. Vermelho = só erros
  de sistema. Corolário: zero gamificação (sem streaks, badges, mascotes).
- **Guardas:** `themeTokens.test.ts` já calcula AA dos pares nos dois temas e
  paridade de chaves — qualquer evolução destes valores passa por lá.

## Espaço

- **Base:** 4px; **escala deliberadamente irregular:** 4 · 8 · 12 · 16 · 24 ·
  32 · 48 · 64.
- **Densidade:** confortável-densa — linhas de grelha 40px (48px com
  controlos); padding de superfícies 24px (16px mobile); 32–48px entre
  regiões; alvos tácteis ≥44px. Cansaço pede menos alvos e mais separação,
  não espaço vazio gigante.

## Layout

- **Abordagem:** híbrida — grelha disciplinada nas listas e formulários;
  **entrada do dia editorial** (a home é «quanto falta», em prosa, antes de
  qualquer número: Agora → A seguir → Arrumado hoje → «podes fechar»).
- **Grelhas de notas = registo pautado:** sem linhas verticais; fios
  horizontais 1px; nome do aluno fixo à esquerda (âncora humana, 500);
  cabeçalho sticky; lavagem âmbar té-nue na coluna activa; célula vazia =
  traço curto (vazio ≠ zero, §13.3, também no pixel); «não aplicável» sai
  visivelmente do ritmo; sem zebra; validação por linha, nunca célula a
  célula verde.
- **Largura:** listas densas a toda a largura da coluna (regra SUP-9S72UL);
  páginas de leitura `max-w-3xl/4xl` centradas.
- **Raio hierárquico:** controlo 6px · cartão 10px · painel 14px · pílula
  999px. Nada de arredondamento bolha universal. Sombras raras
  (`card-soft` existente); hierarquia por tom de fundo, borda e espaço.

## Movimento

- **Abordagem:** minimal-funcional com 3 gestos assinados, e mais nenhum:
  1. **Arrumar** — fechar uma grelha encaixa o cartão na pilha «Arrumado»
     (~280ms, assentamento físico curto) e escreve a hora em serifa itálica.
  2. **Tinta a fixar** — confirmar proposta: lápis→tinta em ~200ms
     (peso 400→500), sem modal, sem confete.
  3. **Podes fechar** — autosave silencioso; no fim, a linha esmeralda
     «Tudo guardado — podes fechar.» Dar autorização de ir embora é o momento
     mais fiel à marca.
- **Easing único:** `cubic-bezier(.22, 1, .36, 1)` (`--ease-relief`);
  duração micro 50-100ms · curta 150-250ms · média 250-400ms.
- `prefers-reduced-motion` respeitado; nunca animar notas a contar, nunca
  celebrar com confetti.

## Componentes de referência (já existem — usar, não recriar)

`PageHeader` · `StatCard` (tons amber/mint/sky/plain de `lib/surfaces.ts`) ·
`EmptyState` (vazio diz o próximo passo) · `TableShell` (+`head-class`) ·
`statusTone`/`qualitativeTone` (cor semântica pelo VALOR do enum) ·
`card-soft`. Novos ecrãs compõem com estas peças.

## Registo de decisões

| Data | Decisão | Racional |
|------|---------|----------|
| 2026-09-04 | Sistema criado; memorável = «alguém tratou disto por mim» | Escolha do dono em /design-consultation |
| 2026-09-04 | Metáfora lápis→tinta para proposta→decisão | Regra pedagógica §3.3 tornada assinatura visual on-name |
| 2026-09-04 | Âmbar ressemantizado: cuidado/feito no chrome; pílulas de dados intocadas | Ponte entre marca e statusTone sem partir o instalado |
| 2026-09-04 | Sem clipes/rasgado/skeuomorfismo | Feedback do dono no board: «não é preciso infantilizar» |
| 2026-09-04 | Newsreader em dose pequena (saudação/horas/fecho) | Voz «Próximo» sem virar revista; custo ~30KB aceite |
| 2026-09-04 | Home de fecho editorial (C2 aprovada) | Eureka: a categoria vende poder; nós vendemos alívio |
| 2026-09-04 | Vermelho nunca em notas; zero gamificação | Ética visível; a avaliação é do professor |
