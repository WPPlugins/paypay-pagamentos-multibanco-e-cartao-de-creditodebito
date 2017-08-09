=== PayPay - Pagamentos Multibanco, Cartão de Crédito/Débito e MB WAY ===
Contributors: paypayacin
Tags: payment, gateway, plugin
Requires at least: 4.6
Tested up to: 4.7.3
Stable tag: 1.2.7
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Módulo de pagamentos por Multibanco, Cartão de Crédito/Débito e MB WAY da PayPay

== Description ==
Módulo de pagamentos por Multibanco, Cartão de Crédito/Débito e MB WAY da PayPay que pode ser integrado em qualquer loja WooCommerce.

= Funcionalidades =
* Emissão direta de referências de pagamento Multibanco durante o processo de compra;
* Pagamentos por Cartão de Crédito/Débito e MB WAY de forma segura através da PayPay;
* Atualização automática do estado dos pagamentos via webhook.

== Installation ==

[Consulte o manual de configuração detalhado disponível no nosso site. ](https://www.paypay.pt/paypay/public/plugins/manuais/manual-woocommerce.pdf)

= Requisitos mínimos =
* WooCommerce 2.6 ou superior.

= Instalação Manual =
1. Fazer upload dos ficheiros fornecidos para o diretório `/wp-content/plugins/paypay`;
2. Activar o plugin na área de administração;
3. (Opcional) Teste a receção de pagamentos submetendo o formulário com as configurações de teste fornecidas;
3. Preencher o formulário na área de configuração do plugin com os dados de acesso fornecidos pelo apoio da PayPay.

== Screenshots ==
1. Visualização das opções de pagamento (checkout).
2. Consulta dos dados de pagamento.
3. Confirmação do pagamento da encomenda.

== Changelog ==

= 1.0 =
* Versão inicial.

= 1.1 =
* Adicionado suporte multi-língua (traduções em inglês e espanhol);
* Correção de um problema na configuração do webhook/callback através das configurações;
* Possibilidade de realizar o checkout sem registo.

= 1.1.1 =
* Correção de um problema de apresentação das opções de pagamento.

= 1.2 =
* Adicionado suporte para pagamentos com a aplicação MB WAY;
* Filtragem das formas de pagamento de acordo com a configuração da integração.

= 1.2.1 =
* Correções de estrutura da base de dados.

= 1.2.2 =
* Correção no filtro de formas de pagamento apresentadas.

= 1.2.3 =
* Revisão geral da gestão do estado das encomendas;
* Revisão do funcionamento de atualização manual de pagamentos.

= 1.2.4 =
* Adicionada compatibilidade com o plugin WooCommerce Gateways Country Limiter.

= 1.2.5 =
* Correção do estado da encomenda na conclusão da compra de forma a enviar email da confirmação da encomenda.

= 1.2.6 =
* Correção do redirecionamento para a página de confirmação da encomenda para utilizadores sem sessão.

= 1.2.7 =
* Redução do número de notificações de confirmação e pagamento.

= 1.3.0 =
* Adicionada compatibilidade com o plugin WooCommerce 3.0.x.
