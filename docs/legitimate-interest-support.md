# Ponderação de interesse legítimo — canal de suporte

> **Documento interno.** Não é publicado na aplicação nem no site. Existe para
> sustentar, por escrito, os tratamentos do canal de suporte que assentam no
> artigo 6.º, n.º 1, alínea f) do RGPD, e para poder ser mostrado se alguém o
> pedir.
>
> Escrito a partir do que o código faz, e verificável nele. Onde não há
> comportamento implementado, este documento não afirma um.

Cada afirmação de facto abaixo tem origem em: `app/Http/Controllers/PublicSupportController.php`,
`app/Actions/Support/*`, `app/Console/Commands/SupportRetention.php`,
`app/Models/Support*`, `config/retention.php`, e os testes em
`tests/Feature/Support/`.

## Âmbito

Quatro tratamentos, todos no canal de suporte:

1. **Contacto genérico** — alguém escreve-nos sem que o contacto se destine a
   preparar uma contratação nem diga respeito a um contrato em vigor.
2. **Prevenção de abuso do formulário** — impedir que o `/contacto` público seja
   usado para submissões automáticas em massa.
3. **Deteção de fraude** através do canal de suporte.
4. **Segurança do próprio canal** — garantir que um pedido não é acessível a
   quem não o escreveu.

Os restantes tratamentos do canal **não** assentam em interesse legítimo e
estão fora deste documento: um contacto que prepara uma contratação e o
suporte a quem já é utilizador assentam na alínea b) (diligências
pré-contratuais e execução do contrato); a conservação imposta por lei assenta
na alínea c).

## 1. Interesse prosseguido

Poder **receber, organizar e responder** às comunicações que nos são dirigidas,
e manter esse canal utilizável e seguro.

É um interesse próprio e imediato: sem ele não há forma de um professor nos
dizer que não consegue entrar na conta — que é, por construção, a pessoa que
não pode usar nenhum canal autenticado.

## 2. Necessidade

**O tratamento é necessário e não há alternativa menos intrusiva que sirva a
mesma finalidade.**

- Para responder é preciso um endereço de resposta. É o dado mínimo, e é o
  único dado de contacto recolhido.
- O nome é pedido para tratar a pessoa por ela; não é verificado nem cruzado
  com nada.
- O assunto vem de uma **lista fechada de sete opções**, não de texto livre — o
  que permite encaminhar sem ler o conteúdo.
- O corpo é escrito pela própria pessoa e é o objeto do pedido: sem ele não há
  o que responder.
- **Não é recolhido nada mais.** Em particular, não são guardados endereço IP
  nem identificador de navegador (ver §5).

Considerámos e afastámos:

- **Só email, sem formulário** — mantém-se disponível e continua a funcionar,
  mas não substitui o formulário para quem não tem cliente de email
  configurado ou não conhece o endereço.
- **Exigir conta para pedir ajuda** — excluiria exatamente quem mais precisa:
  quem não consegue autenticar-se.
- **Consentimento** — afastado por não ser o fundamento adequado: retirá-lo
  implicaria não poder responder ao pedido que a própria pessoa fez, o que
  torna o consentimento aparente e não livre.

## 3. Impacto previsível no titular

**Baixo, e limitado ao que a pessoa decidiu escrever-nos.**

- O contacto é **iniciado pelo titular**. Não há recolha passiva, não há
  rastreio, não há enriquecimento com dados de outras fontes.
- Não há decisões automatizadas, não há definição de perfis, não há
  publicidade: o produto não os faz e a Política afirma-o.
- Os dados **não são usados para outra finalidade** que não responder ao
  pedido. Contactar o suporte não autoriza comunicações comerciais.
- O risco residual concreto é o campo livre: uma pessoa pode escrever ali um
  nome de aluno. É o risco que as salvaguardas de §5 tratam.

## 4. Expectativas razoáveis

Quem escreve para um formulário de suporte **espera** que a mensagem seja lida
por alguém da equipa, que lhe respondam para o endereço que indicou, e que o
pedido fique registado enquanto durar o assunto.

Nada neste tratamento é surpreendente para o titular. O que poderia sê-lo — ser
usado para marketing, ficar guardado indefinidamente, ser visível para o
empregador — **não acontece**, e a Política di-lo expressamente.

## 5. Salvaguardas aplicadas

Todas implementadas e cobertas por testes.

| Salvaguarda | O que é, em concreto |
| --- | --- |
| **Minimização na recolha** | Cinco campos, dois dos quais de lista fechada ou limitados. Sem anexos. |
| **IP não persistido** | Usado apenas em memória pelo limitador de 5 pedidos/minuto; não é escrito na base de dados nem na auditoria. |
| **User-Agent não persistido** | Nunca lido nem guardado. |
| **Aviso no ponto de recolha** | Pedido explícito para não incluir nomes de alunos, dados de saúde ou outros dados desnecessários, visível acima dos campos. |
| **Guest sem portal** | Um visitante não tem forma de consultar o pedido depois de o submeter: não há GET público, não há URL assinada. |
| **A referência não autentica** | `SUP-XXXXXX` é um número de protocolo; nenhuma rota o aceita como credencial. |
| **Emails sem conteúdo** | As notificações levam referência, categoria e estado. Nunca o resumo escrito pela pessoa, nunca a descrição, nunca mensagens do histórico. |
| **Acesso limitado** | Um pedido é visível a quem o escreveu e aos administradores de plataforma. Nem colegas da mesma organização, nem quem a administre. |
| **Auditoria sem conteúdo livre** | O rasto nunca guarda descrição, corpo de mensagem, assunto livre ou a nota de suspensão. Um teste com sentinela prova-o. |
| **Criação sem autor no rasto** | O evento de abertura de um pedido não fica ligado ao utilizador nem à organização, precisamente para que a anonimização futura seja possível. |
| **Retenção definida e executada** | 23 / 30 dias e 24 meses, por rotina diária, e não por decisão avulsa. |
| **Anonimização verdadeira** | Findos os 24 meses, os identificantes vão a nulo e as mensagens são eliminadas — sem marcas de substituição. |
| **Suspensão restrita** | Só um administrador de plataforma, só com motivo de lista fechada, e trava apenas a anonimização. |

## 6. Conclusão

**O interesse legítimo prevalece**, para os quatro tratamentos do âmbito.

O tratamento é iniciado pelo titular, limitado ao mínimo necessário para lhe
responder, não é usado para nenhuma finalidade que ele não esperasse, tem prazo
e tem eliminação efetiva. As salvaguadas acima reduzem o impacto ao residual, e
o único risco material — dados desnecessários escritos no campo livre — é
mitigado no ponto de recolha e resolvido pelo prazo de eliminação.

**Direito de oposição.** Quando o fundamento for a alínea f), o titular pode
opor-se ao tratamento; a Política indica-o na secção «Contactos e suporte» e
remete para «Os seus direitos». Uma oposição procedente implica eliminar o
pedido, com a consequência — que lhe é dita — de deixar de ser possível
responder-lhe por esse canal.

## Revisão

Este documento é revisto quando mudar algum dos factos em que assenta: os
campos recolhidos, os prazos de `config/retention.php`, as regras de acesso, ou
o conteúdo das notificações. Não há revisão periódica automática, e por isso
não é declarada nenhuma.
