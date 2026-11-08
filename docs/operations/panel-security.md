# Chave, rotação e recuperação offline

Runbooks do operador: [instalação, configuração e atualização](installation.md) · [manutenção, backups, recuperação, desativação e remoção de dados](maintenance-and-data.md) · [GeoIP](geoip.md) · chave e fatores (este documento).

O operador controla PHP, configuração externa e backup do banco. Estes procedimentos são locais e nunca oferecem bypass HTTP. A ferramenta offline vem no pacote em `wp-content/mu-plugins/stealthaudit/bin/security-operator.php` (no repositório, `bin/security-operator.php`) e roda com o PHP 8.3+ do próprio host WordPress e o autoloader prefixado do pacote: Composer não é necessário no servidor. Só funciona em linha de comando (por HTTP sai antes de qualquer ação, sem saída) e recusa agir se o StealthAudit carregado pelo WordPress não for a cópia do mesmo diretório — uma cópia antiga ou avulsa do script não opera sobre uma instalação mais nova. Execute-o como o usuário do PHP do site (o mesmo que lê o arquivo de configuração).

## Configuração e backup

Configure IDs autorizados, `key_base64`, `key_id` e `installation_id` no arquivo externo ou em `wp-config.php` conforme o exemplo. Gere 32 bytes com um CSPRNG no cofre/provisionador da equipe; não passe o segredo como argumento de shell nem imprima em terminal/histórico/CI. Forneça o arquivo por secret manager ou canal local protegido, com permissão somente do operador e do processo PHP necessário. O arquivo de exemplo não contém chave utilizável.

Guarde chave/key-id e installation_id em backup cifrado separado do banco. Teste a restauração em instalação isolada antes de depender dele. Banco sem chave não recupera segredos TOTP. Preserve o installation_id ao mover domínio, diretório ou chave; clonagem para outra instalação requer migração controlada e identidade própria, não copiar provas ativas.

Antes de operação, pause o painel alterando **a configuração ativa externa** para `authorized_user_ids => array()`. Aguarde terminar requisições em voo e impeça novas consultas na janela operacional; faça backup consistente das tabelas de estado e do arquivo de configuração. Coletores podem continuar operando. Verifique que a instância ativa realmente usa a allowlist vazia. O script confirma a configuração carregada e recusa configuração inválida ou allowlist não vazia.

## Reset de fator perdido

Mantenha chave atual e installation_id válidos; a ausência de chave antiga não impede reset controlado desde que uma nova chave válida esteja configurada e os outros fatores tenham plano de recuperação. Pause o painel e execute com o ID numérico do operador, nunca senha/código:

```text
STEALTHAUDIT_WORDPRESS_ROOT=/caminho/wordpress php /caminho/wordpress/wp-content/mu-plugins/stealthaudit/bin/security-operator.php reset 123
```

A sintaxe acima é de shell Unix no host PHP; em PowerShell configure `$env:STEALTHAUDIT_WORDPRESS_ROOT` antes de executar `php …\wp-content\mu-plugins\stealthaudit\bin\security-operator.php reset 123`. O caminho não é segredo. O reset substitui fator e recovery por CAS, gera generation aleatória e revoga todas as sessões WordPress desse usuário. Nenhum código/chave é impresso. Repetir o reset do mesmo ID é seguro: gera outra generation e revoga sessões de novo; ID sem fator falha sem alterar nada. Reative o ID autorizado somente depois do reset bem-sucedido. O usuário precisa novo login WordPress, senha reconfirmada e novo cadastro/confirm TOTP antes dos dados.

Recovery pelo painel com senha mais código invalida o conjunto inteiro e permite apenas confirmar um novo fator. Abandonar esse recadastro por mais de dez minutos mantém `recovery_locked`; não restaura o fator antigo nem libera novo cadastro com senha apenas. O operador então usa este reset offline. Também se aplica à troca de fator abandonada.

## Rotação de chave e migração

A rotação é offline: não há necessidade de duas chaves simultâneas no atendimento HTTP. Mantenha a allowlist vazia durante **todos** os lotes. Prepare fora do webroot um segundo arquivo PHP retornando configuração com nova chave de 32 bytes, novo key-id e o mesmo installation_id. Faça backup protegido de ambas as chaves antes de migrar. Nunca remova a antiga antes da validação completa.

Configure `STEALTHAUDIT_WORDPRESS_ROOT` e `STEALTHAUDIT_NEW_CONFIG_FILE` com os caminhos; valores das chaves permanecem nos arquivos externos protegidos. A configuração ativa ainda usa a chave antiga e allowlist vazia.

```text
php wp-content/mu-plugins/stealthaudit/bin/security-operator.php rotate 0
php wp-content/mu-plugins/stealthaudit/bin/security-operator.php rotate ULTIMO_ID
php wp-content/mu-plugins/stealthaudit/bin/security-operator.php validate 0
php wp-content/mu-plugins/stealthaudit/bin/security-operator.php validate ULTIMO_ID
```

Cada comando lê no máximo 100 fatores por ID e imprime somente quantidade, último ID e se concluiu; `rotate` informa também quantos foram migrados nesse lote (`migrated=0` numa repetição indica que todos já abriam com a nova chave). Substitua ULTIMO_ID pelo `last_user` anterior, repetindo até `complete=yes`. A migração abre o envelope antigo, cifra com a nova chave, compara o plaintext da nova abertura em memória e só então grava por CAS. Geração muda e provas antigas deixam de valer. Reiniciar em zero é seguro: linhas que já abrem com a nova chave são reconhecidas. Uma falha interrompe o lote e mantém o painel pausado; não publique configuração de uma migração parcial.

Depois de todos os lotes, execute o passe `validate` desde zero até o fim, ainda com o painel pausado. Só após todos os fatores abrirem com a nova chave mude a configuração ativa para nova chave/key-id e reative a allowlist. Retenha a chave antiga no backup durante a política de rollback; não a deixe em endpoints ou opções do WordPress. Um rollback requer restaurar banco+config consistentes ou recifrar controladamente com a chave correta, nunca alternar apenas o arquivo num banco parcialmente migrado.

Se perdeu a chave sem backup, não é possível abrir fatores existentes. Pause o painel, configure nova chave/key-id válidos, preserve installation_id e execute reset individual de todos os IDs com fator existente, revogando suas sessões. Confira todos os resets antes de reabrir acesso. O reset não apaga auditoria e erros. Backups históricos possuem política própria.
