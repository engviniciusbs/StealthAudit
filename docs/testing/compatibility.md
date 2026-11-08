# Contratos de compatibilidade

Verificados em 2026-11-07 durante a fase 0 como alvos; a matriz de runtime foi executada na fase 8a (2026-11-08), ver [Matriz verificada](#matriz-verificada-fase-8a) e [verification.md](verification.md). Ver [plano](../plans/2026-11-07-implementation-plan.md) e [cobertura de eventos](event-coverage.md).

## Plataforma e ferramentas

| Componente | Alvo fixado | Evidência / condição |
| --- | --- | --- |
| WordPress | 7.1.4, single-site | [API oficial](https://api.wordpress.org/core/version-check/1.7/) retornou current/version 7.1.4; [arquivo de releases](https://wordpress.org/download/releases/) e [tag de código](https://github.com/WordPress/wordpress-develop/tree/7.1.4) conferidos. Usar pacote release, não trunk nem a árvore -src diretamente como distribuição. |
| PHP | 8.3 como base; 8.4 e 8.5 na matriz | [Recomendação WordPress](https://wordpress.org/about/requirements/) e [suporte PHP](https://www.php.net/supported-versions.php). Fase 8a: 8.3.36/8.3.x, 8.4.27 e 8.5.12 aprovados com WordPress 7.1.4 (ver matriz). |
| Banco | MariaDB 10.11; MySQL 8.4 LTS como segunda família | InnoDB obrigatório nas tabelas próprias. Fase 8a: MariaDB 10.11.19 e MySQL 8.4.13 aprovados (integração, concorrência real e e2e). MySQL 8.0 (fim de vida em abril de 2026), 9.x e MariaDB 11.x não testados. |
| Node | >=22.13.0 | Requisito publicado de @wordpress/env 12.1.0. Node 22.17.0 disponível na descoberta. |
| wp-env | 12.1.0 | [Pacote publicado](https://registry.npmjs.org/@wordpress/env/12.1.0). Usar configs distintas para desenvolvimento/teste e mappings MU; não presumir banco CLI separado do web dentro da mesma config. |
| PHP-Scoper | 0.18.20 | [Release estável](https://github.com/humbug/php-scoper/releases/tag/0.18.20) revalidado. Ferramenta de build isolada, não dependência do host. |
| Docker | Engine 28.3.0 observado | Ambiente local; não requisito da hospedagem final. PHP/Composer/WP-CLI não disponíveis no PATH local na descoberta. |

Extensões: requisitos normais do WordPress, mysqli para banco; sodium para cifra autenticada; iconv e XMLWriter para QR SVG. A falta de requisitos do painel impede acesso, não autoriza bypass. Coleta deve usar somente os requisitos que efetivamente necessita.

O loader MU deve checar a versão PHP antes de incluir arquivos com sintaxe mais nova. APIs de usuário/pluggable só ficam disponíveis na fase apropriada do bootstrap WordPress. Não depender de activation hooks para tabelas, regras de rota ou agendamento.

## Bibliotecas de produção e versões de contrato

As versões abaixo foram verificadas em releases/manifestações publicadas, sem instalação nesta fase. A fase 1 resolve e registra toda a árvore em composer.lock. Não substituir contrato publicado por branch de desenvolvimento silenciosamente.

| Pacote | Versão de referência | PHP e dependências diretas | Licença |
| --- | --- | --- | --- |
| spomky-labs/otphp | 11.5.0 | PHP >=8.1; paragonie/constant_time_encoding, psr/clock, symfony/deprecation-contracts | MIT |
| bacon/bacon-qr-code | 3.1.1 | PHP ^8.1; iconv, dasprid/enum; backend SVG exige XMLWriter | BSD-2-Clause |
| geoip2/geoip2 | 3.4.0 | PHP >=8.1; json, maxmind-db/reader, maxmind/web-service-common | Apache-2.0 |

Fontes versionadas: [OTPHP manifesto](https://github.com/Spomky-Labs/otphp/blob/11.5.0/composer.json), [release](https://github.com/Spomky-Labs/otphp/releases/tag/11.5.0), [Bacon manifesto](https://github.com/Bacon/BaconQrCode/blob/v3.1.1/composer.json), [GeoIP manifesto](https://github.com/maxmind/GeoIP2-php/blob/v3.4.0/composer.json).

Correção da descoberta inicial: 11.6.x existe como branch, mas o release estável publicado de OTPHP observado é **11.5.0**. GitHub releases e Packagist concordaram; source reference publicada: 877683d6352b80cdc7020fd43a725629c2524435. Não planejar um requisito Composer 11.6 antes de publicação verificada. A política do projeto mantém as três minor branches mais recentes; revalidar antes de atualizar.

## APIs permitidas para 2FA e QR

Confirmadas em [TOTP.php 11.5.0](https://github.com/Spomky-Labs/otphp/blob/11.5.0/src/TOTP.php) e [OTP.php 11.5.0](https://github.com/Spomky-Labs/otphp/blob/11.5.0/src/OTP.php):

- `TOTP::generate(?ClockInterface $clock = null, ?int $secretSize = null): self` e `TOTP::createFromSecret(string $secret, ?ClockInterface $clock = null): self`.
- `withPeriod(int): self`, `withDigest(string): static`, `withDigits(int): static`, `withLabel(string): static` e `withIssuer(string): static`; usar os objetos retornados, pois o padrão with clona em vez de editar o original.
- `verify(string $otp, ?int $timestamp = null, ?int $leeway = null): bool`, `at(int $timestamp): string`, `getProvisioningUri(): string` e `getSecret(): string`.
- Clock PSR explícito evita a depreciação de omitir relógio. Não usar create()/setters mutáveis como padrão novo.
- Perfil interoperável do painel: SHA-1, seis dígitos e 30 segundos. Leeway é segundos e deve ser menor que o período; não passar 30 para representar um passo. O adaptador verifica os candidatos documentados e persiste atomicamente o passo aceito; verify não previne replay sozinho. [RFC 6238](https://www.rfc-editor.org/rfc/rfc6238).

QR: [Writer 3.1.1](https://github.com/Bacon/BaconQrCode/blob/v3.1.1/src/Writer.php), ImageRenderer/RendererStyle/SvgImageBackEnd e `Writer::writeString(...)`. Gerar em memória e manter quiet zone; não usar writeFile ou fornecedor remoto de QR. [Backend SVG](https://github.com/Bacon/BaconQrCode/blob/v3.1.1/src/Renderer/Image/SvgImageBackEnd.php) verifica XMLWriter em runtime.

Cifra: [sodium XChaCha20-Poly1305](https://www.php.net/manual/en/function.sodium-crypto-aead-xchacha20poly1305-ietf-encrypt.php), chave 32 bytes, nonce novo de 24 bytes e AAD de contexto; decrypt pode falhar. Chave fora do banco, envelope versionado/key-id, sem fallback que conceda acesso.

Identidade: [wp_get_session_token](https://developer.wordpress.org/reference/functions/wp_get_session_token/) apenas extrai token; validar autenticação/cookie e [WP_Session_Tokens::verify](https://developer.wordpress.org/reference/classes/wp_session_tokens/) para o mesmo usuário. [wp_check_password](https://developer.wordpress.org/reference/functions/wp_check_password/) reconfirma senha. Nonce REST é controle CSRF, não identidade nem segundo fator.

## Persistência, diagnóstico e GeoIP

- dbDelta exige verificar o resultado real da migração, tabela/engine/índices. prepare usa placeholders e listas permitidas para identificadores/ordenação.
- Replay, recovery e limites usam estado autoritativo e consumo condicional atômico; caches/transients não oferecem esse contrato. SQL CAS deve distinguir uma linha afetada, disputa zero e falha false.
- Handler PHP respeita máscara error_reporting e o handler anterior. Shutdown é melhor esforço; não captura todo fatal, error_log arbitrário ou falha anterior ao carregamento.
- [GeoIP Reader 3.4.0](https://github.com/maxmind/GeoIP2-php/blob/v3.4.0/src/Database/Reader.php): construir com filename/locales, city/country, metadata e close. Tratar resultado ausente/campos nulos, IP inválido e MaxMind\Db\Reader\InvalidDatabaseException. Nenhuma chamada ao cliente web.
- MMDB é fornecido pelo operador sob licença própria; não é coberto pela licença do reader. Ausência/falha mantém IP e informa localização indisponível. Não baixar/aceitar termos em nome do operador nem incluir base no ZIP.

## Decisão de licença e avisos

O código original do StealthAudit será distribuído sob **GPL-3.0-or-later**. A escolha acomoda os componentes permissivos de referência e o reader Apache-2.0: a [Apache Software Foundation](https://www.apache.org/licenses/GPL-compatibility.html) documenta compatibilidade de Apache-2.0 com GPLv3, não com GPLv2-only. Não trocar por GPLv2-only sem nova revisão do inventário. WordPress aceita licenças GPL-compatíveis, conforme suas [diretrizes](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#1-plugins-must-be-compatible-with-the-gnu-general-public-license).

Na fase 1, adicionar o texto oficial LICENSE e SPDX aos arquivos próprios após resolver composer.lock. No build, preservar LICENSE/NOTICE/copyright de **todas** as dependências transitivas, mesmo quando prefixadas por PHP-Scoper; gerar inventário e THIRD-PARTY-NOTICES a partir da árvore real. Fontes/ícones/assets também precisam de licença verificável. Ferramentas de desenvolvimento têm inventário separado; não distribuir vendor dev no ZIP.

A base GeoIP não é relicenciada pelo projeto. Termos de download, atualização, atribuição e eventual redistribuição do fornecedor continuam separados. Não é prometida publicação no diretório WordPress.org nesta versão.

## Saída da fase 0

Releases/APIs e hooks foram revalidados em fontes primárias; nenhuma instalação ou teste de runtime foi executado. Os próximos gates são lockfiles, disponibilidade das extensões e smoke tests na fase 1, seguidos da matriz e limites medidos na fase 8. Não declarar suporte demonstrado antes desses testes.

## Matriz verificada (fase 8a)

Executada em 2026-11-08 com `npm run matrix` em ambientes isolados adicionais (procedimento, versões exatas, digests e defeitos corrigidos em [verification.md](verification.md)). WordPress 7.1.4 single-site em todas as linhas.

| PHP | MariaDB 10.11.19 | MySQL 8.4.13 |
| --- | --- | --- |
| 8.3 (8.3.36) | aprovado: unidade, integração, e2e (ambiente original de testes) | aprovado: unidade, integração, e2e |
| 8.4 (8.4.27) | aprovado: unidade, integração, e2e | aprovado: unidade, integração, e2e; também com prefixo `wpx9_` e `utf8mb4_0900_ai_ci` (sem e2e) |
| 8.5 (8.5.12) | aprovado: unidade, integração, e2e | aprovado: unidade, integração, e2e |

Prefixo `sa_alt_` com `utf8mb4_general_ci` no MariaDB: ver [verification.md](verification.md). "Integração" inclui os testes com dois processos PHP reais. Runner: PHPUnit 9.6.38 com yoast/phpunit-polyfills 4.0.0, compatível nas três versões de PHP. Aviso de infraestrutura: o WP-CLI 2.12.0 emite deprecations próprias no PHP 8.5; não é código do StealthAudit.

Suporte declarado: somente as combinações aprovadas acima. Qualquer outra versão de PHP, banco, WordPress, multisite ou hospedagem sem Docker continua sem evidência.
