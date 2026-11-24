=== Assis Prime Manager ===
Contributors: ecomcore
Stable tag: 0.9.80
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Custom management system for Assis Prime.

== Description ==
Assis Prime Manager is a private, custom-built management panel for Assis Prime Spa e Beleza. It provides appointment scheduling, client management, medical records, inventory, finance, CRM, and WhatsApp notifications in a single integrated interface built on top of WooCommerce and WordPress.

== Changelog ==

= 0.9.80 =
* IMPORTANTE — corrige um erro grave introduzido nas versões 0.9.78 e 0.9.79 (não chegaram a ser instaladas): um nome de variável JavaScript duplicado fazia o painel inteiro falhar ao carregar (ficava preso em "Carregando Assis Prime…"), não só a tela de Agenda. Encontrado ao gerar o print de demonstração da lista de horários livres, antes da instalação. A partir de agora, toda alteração de JavaScript passa por uma verificação de sintaxe de verdade (não só PHP) antes de ser empacotada.

= 0.9.79 =
* Corrige um efeito colateral da lista de horários livres adicionada na versão anterior: ao clicar num horário vago direto na grade da Agenda (em vez de "+ Novo agendamento"), a lista de horários livres do modal não era atualizada e podia continuar mostrando os horários do último agendamento que tinha sido aberto antes — mesmo sendo de outro profissional/serviço. Agora atualiza corretamente em todos os pontos de entrada do modal de Agendamento. Encontrado numa validação de ponta a ponta de todas as funções reportadas pelo cliente, feita a pedido antes de seguir com novos itens.

= 0.9.78 =
* Adiciona a lista de horários livres no modal de Agendamento (painel): depois de escolher cliente, serviço, profissional e data, aparecem botões com os horários realmente disponíveis daquele profissional (considerando atendimentos já marcados e bloqueios de agenda), em vez de um campo de hora em branco para preencher de cabeça. Ao editar um agendamento existente, o próprio horário atual continua aparecendo como opção.

= 0.9.77 =
* Corrige a mensagem de confirmação ("Prontinho! ... Sinal pago de X") sendo enviada por WhatsApp e e-mail no momento em que um agendamento manual é criado pelo painel, mesmo quando o sinal ainda não foi pago — por isso a cliente recebia "tudo certo, sinal pago" e, logo em seguida, o link pedindo para pagar o sinal. Agora essa mensagem só sai quando não há sinal a cobrar ou quando o sinal já foi efetivamente pago; com sinal pendente, a única notificação é a que a equipe dispara pelo botão "Enviar link de pagamento" (como já era esperado).
* Corrige a ficha de anamnese não ser enviada automaticamente em agendamentos criados pelo painel (manuais) — o envio automático após o pagamento do sinal só acontecia no agendamento público, mesmo com a opção "Enviar ficha de anamnese automaticamente" ligada em Configurações > Geral. Agora funciona do mesmo jeito nos dois casos.

= 0.9.76 =
* Corrige o "Enviar link de pagamento" (botão no agendamento) não dar nenhuma pista do motivo quando o link não chegava para a cliente por e-mail ou WhatsApp. Por e-mail: aplicado o mesmo motivo encontrado no teste de envio de e-mail — faltava ler o canal interno (wp_mail_failed) que plugins de SMTP usam para avisar por que recusaram o envio. Por WhatsApp: a mensagem de erro da Z-API (ex.: "sessão desconectada, escaneie o QR Code novamente") estava sendo descartada e trocada por um aviso genérico. Agora os dois mostram o motivo real na janela do link de pagamento, para a equipe saber exatamente o que corrigir.

= 0.9.75 =
* Corrige o diagnóstico do "Testar envio de e-mail" (Configurações > Geral) não informar o motivo real da recusa: quando um plugin de SMTP (como o WP Mail SMTP) recusa o envio, ele avisa o WordPress por um canal interno (a action wp_mail_failed) que o teste do Assis Prime não lia — por isso a mensagem de erro não dizia nada útil, mesmo com o motivo certo disponível internamente (o mesmo que o WP Mail SMTP mostra na tela dele). Agora o teste mostra esse motivo (ex.: falha de autenticação, remetente recusado etc.) diretamente na mensagem de erro.

= 0.9.74 =
* Corrige o procedimento pré-selecionado do agendamento online nunca ser salvo: o botão "Salvar configurações" da aba Geral não enviava o campo "Procedimento pré-selecionado" (nem a política de cancelamento, Meta Pixel, GTM e o envio automático de ficha de anamnese) para o servidor — então qualquer escolha feita no seletor era descartada em silêncio e o link público sempre caía no primeiro procedimento ativo da lista. Agora os cinco campos são enviados corretamente e a escolha passa a valer de verdade no link de agendamento.
* Corrige o mesmo tipo de falha na aba WhatsApp: o texto da mensagem "link de pagamento" também não era enviado ao salvar, então uma alteração nesse template nunca era realmente gravada. Encontrado em auditoria de código proativa após o caso do procedimento pré-selecionado, antes que o cliente percebesse.

= 0.9.73 =
* Corrige o agendamento público travando em "hoje" sem nenhum dia pra escolher: o endpoint /public-bootstrap não devolvia os horários de funcionamento (opening_hours) nas configurações, então a página pública tratava todo dia como fechado. Achado e diagnosticado pela auditoria com navegador — obrigado pela investigação detalhada.


For the full release history see the plugin changelog file.
