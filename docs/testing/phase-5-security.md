# Autorização do painel e MFA (fase 5)

O backend de autorização existe sem rotas de consulta ou interface. `Runtime::panel_access()` compõe o serviço somente quando configuração, chave, extensões e banco estão disponíveis. `panel_configured()` continua sendo apenas indicação de pré-requisitos.

## Contrato para a fase 6

Todas as operações usam a sessão real de navegador WordPress: HTTPS (`is_ssl()`), cookie `logged_in` assinado validado por `wp_validate_auth_cookie`, expiração sem grace period, usuário igual ao usuário atual e token confirmado em `WP_Session_Tokens` para esse usuário. O serviço não registra filtros de `authenticate`, não modifica wp-login e não aceita Application Password ou nonce como substituto do cookie. A allowlist externa é checada novamente a cada entrada. Proxies de TLS devem configurar corretamente o WordPress; cabeçalho encaminhado arbitrário não concede HTTPS.

Mutações exigem método POST e nonce WordPress de ação `stealthaudit`, passado no parâmetro final de cada método. A camada HTTP ainda deverá validar origem/CSRF das consultas, limitar corpos, responder com mensagens genéricas e aplicar `Cache-Control: no-store` a todas as respostas e QR. Nenhum segredo deve entrar em URL, log, analytics, captura visual ou armazenamento de navegador. Não existe transporte HTTP nesta fase.

API de `PanelAccess`:

- `status()` retorna `denied`, `password_required`, `enrollment_required`, `enrollment_pending`, `challenge_required`, `authorized` ou `recovery_locked`; polling não prolonga validade. `enrollment_required` aparece somente com senha reconfirmada e sem fator, com primeiro cadastro expirado ou após reset offline.
- `reconfirm(password, nonce)` confirma a senha WordPress por cinco minutos; não concede dados e remove uma prova anterior da sessão.
- `begin_enrollment(nonce)` exige reconfirmação e devolve `['qr' => SVG]` local de uma pendência por dez minutos, vinculada à sessão. Outra sessão não recebe seu QR.
- `confirm_enrollment(code, nonce)` consome TOTP e ativa o fator por CAS; retorna dez códigos recovery uma única vez. Somente hashes SHA-256 dos códigos aleatórios de 128 bits são persistidos.
- `authenticate(code, nonce)` consome um passo TOTP não usado antes e cria prova no servidor para a sessão específica.
- `recover(password, recoveryCode, nonce)` ou `replace_factor(password, currentTotp, nonce)` substituem o fator por pendência restrita, com nova geração e sem acesso a dados. Todos os códigos e provas anteriores deixam de valer juntos na mesma CAS. Novo TOTP deve ser confirmado antes de consultar.
- `authorized(activity = true)` é o gate obrigatório para cada consulta protegida. Retorna false em qualquer falha. `activity = false` serve apenas para status; uma consulta deliberada pode prolongar o idle, jamais o prazo absoluto.
- `revoke(nonce)` revoga a prova local do painel sem alterar o login WordPress. Logout/revogação da sessão WordPress invalida o gate por verificação do token a cada consulta.

TOTP usa OTPHP 11.5.0, SHA-1, seis dígitos, 30 segundos e candidatos corrente/anterior/seguinte. O passo aceito precisa ser maior que a marca já consumida e a troca usa revisão CAS. Duas verificações concorrentes não podem consumir o mesmo passo. Um passo futuro aceito impede passos menores, conservadoramente.

## Persistência e limites

A linha `factor:<ID>` guarda envelope cifrado, geração aleatória de 256 bits, flag `established`, pendência/owner, watermark TOTP e hashes recovery. A flag fica verdadeira após a primeira ativação e sobrevive a mudanças de fator. Uma troca/recovery abandonada e expirada entra em `recovery_locked`: senha e cookie sozinhos nunca reabrem cadastro de uma conta que já tinha fator. Nesse caso o operador executa reset offline. O primeiro cadastro ainda não estabelecido pode ser refeito após expirar com nova reconfirmação de senha.

Provas usam SHA-256 do ID e token de sessão específico; o token bruto nunca é persistido. Geração atual do fator é comparada a cada leitura. Inatividade de 1.800 segundos e prazo absoluto de 28.800 segundos encerram a prova. Payloads malformados ou envelope que não abre negam acesso inclusive a uma prova anteriormente válida.

Cada mutação reserva atomicamente uma tentativa por conta autorizada (10/300 segundos) e origem confiável (100/300 segundos). A origem usa o mesmo resolvedor de proxies da coleta; origem desconhecida compartilha um bucket. O limite por conta é compartilhado entre senha, cadastro, TOTP, recovery e revogação. NATs podem atingir o limite de origem; erros não são reembolsados (comprovado com contas diferentes atrás de uma origem e `consume()` concorrente em dois processos reais na fase 8a, ver [verification.md](verification.md)). Não há bloqueio permanente: o bucket expira após a janela iniciada na primeira tentativa. Conflito persistente ou falha SQL nega a operação. A reserva de conta pode permanecer contabilizada quando a de origem falha.

`StateRepository::replace_expired` substitui uma linha fisicamente expirada com um único UPDATE condicionado à expiração, nova revisão aleatória e TTL futuro. Provas e limites podem voltar a funcionar sem aguardar o Cron da fase 7. A autoridade nunca usa delete/create, cache ou transients. A capacidade auxiliar permanece conservadora; falhas de criação podem sobrecontar e causar indisponibilidade até reconciliação, sem liberar acesso.

`installation_id` externo é obrigatório para o painel: identificador único estável, de 1 a 128 caracteres ASCII alfanuméricos, `_` ou `-`. Não é URL nem segredo; preserve-o em mudança de domínio e rotação de chave. O envelope XChaCha20-Poly1305 é versionado com key-id, nonce novo de 24 bytes e AAD de contexto/instalação/usuário. A chave tem 32 bytes fora do banco. Perda de chave/tamper/key-id diferente não abre fallback.

Auditoria registra somente ações fechadas `mfa_*`, resultado e identidade/contexto permitido. Senhas, OTP, recovery, URI, QR e chave não entram em eventos. Parâmetros sensíveis e a closure de mutação têm `SensitiveParameter`, e erros públicos são opacos.

## Evidência e limites

Comandos do ambiente de testes existente: `npm run test:unit`, `npm run test:integration`, `npm run quality`. Não subir outro wp-env para rodar esses comandos.

Verificado no ambiente WordPress 7.1.4/PHP 8.3.36/MariaDB 10.11 existente: unidade 171 testes/537 asserções (um skip explicado abaixo); integração completa 45 testes/392 asserções; lint, WPCS/PHPCompatibilityWP e PHPStan nível 6 com 512 MiB aprovados. A revisão encontrou e corrigiu reabertura indevida de cadastro após expiração de troca/recovery com o marcador established persistente; regressões cobrem ambos os fluxos e outra sessão. A verificação final corrigiu `status()`, que informava `password_required` mesmo com senha reconfirmada após reset offline ou primeiro cadastro expirado; o teste de rotação/reset agora percorre o recadastro completo com nova sessão WordPress, nova chave e confirmação TOTP antes de autorizar. O teste de trace inspeciona os frames do serviço com zend.exception_ignore_args=0, sem parâmetros ou closure sensíveis. A integração usa cookies assinados, sessões WordPress reais, tabelas InnoDB isoladas e dois processos PHP reais para TOTP/recovery, incluindo recuperação de linhas de prova e limite expiradas sem manutenção. Testes simulam HTTPS em `$_SERVER` para o gate; não equivalem a navegação TLS real. QR foi validado por SVG/URI local e vetores RFC; escanear em aplicativo Authenticator real continua pendente na fase 6/8. O teste de XMLWriter ausente é pulado quando a extensão está compilada estaticamente no binário `php -n` deste ambiente. PHP 8.4/8.5, MySQL e pacote prefixado ainda não foram validados.

## Smoke operacional do script offline

Executado no contêiner `cli` do ambiente de testes contra o banco do site de testes (tabela `wp_stealthaudit_state` sem fatores antes do teste), com dois usuários sintéticos (A, B), sessões WordPress, fatores ativos com dez hashes recovery, provas válidas e duas chaves aleatórias descartáveis em `/tmp`, fora do webroot. Não é automação da suíte porque o script carrega `wp-load.php` com a configuração e o prefixo do site, não com as tabelas isoladas da integração; a lógica de `FactorMaintenance` já é coberta lá. Resultado observado:

- Allowlist ativa não vazia: `reset` e `rotate` recusados (saída 1, mensagem genérica).
- `reset A` com painel pausado: A inativo, `established=false`, zero recovery, nova generation, sessões WordPress revogadas e prova antiga sem correspondência; B intacto. Repetir `reset A` também conclui com nova generation. ID inexistente, `0`, não numérico e ação desconhecida falham sem alteração.
- Guardas da rotação: sem arquivo novo, arquivo dentro do webroot, mesmo key-id, installation_id diferente ou allowlist ativa recusam; nenhum fator alterado.
- `validate 0` antes da migração falha. `rotate 0` → `Processed 2; migrated=2; complete=yes`: envelopes com o novo key-id, não abrem mais com a chave antiga, abrem com a nova, segredo idêntico ao original (comparado por hash), generation alterada e prova de B invalidada sem revogar sua sessão WordPress. Segunda execução → `migrated=0`, generation inalterada. `validate 0` e `validate` a partir do último ID concluem; validar contra a chave antiga com a nova ativa falha.
- `reset A` depois da troca da configuração ativa para a nova chave conclui.
- Nenhuma chave, nonce, ciphertext ou URI `otpauth` nas 38 linhas de saída do script.
- Limpeza: usuários e linhas de estado sintéticos removidos (zero fatores restantes) e arquivos temporários apagados.

Operação da chave e recovery: [runbook offline](../operations/panel-security.md).
