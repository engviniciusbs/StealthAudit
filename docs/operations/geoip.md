# GeoIP local: base do operador, termos e substituição atômica

A localização é **opcional** e **estimada na leitura**: quando o painel lista eventos, os IPs exibidos naquela página são consultados numa base MMDB local fornecida pelo operador. O resultado não é gravado em lugar nenhum, nenhum IP é enviado a serviço web e nada roda no caminho de gravação da auditoria. A coluna "Local estimado (base atual)" indica onde a base **atual** situa o IP, não onde ele estava quando o evento aconteceu. Detalhes e testes: [phase-7-geoip.md](../testing/phase-7-geoip.md).

## Licença e termos

- O StealthAudit não inclui, não baixa e não atualiza base alguma, e não guarda chave de licença/download. A biblioteca `geoip2/geoip2` (Apache-2.0) licencia só o código; os **dados** têm licença própria, aceita pelo operador em seu nome. O plugin não aceita termos por ninguém.
- GeoLite (gratuita, MaxMind): conta e EULA da MaxMind. Na versão consultada (atualizada em 2026-02-12) a EULA pede, entre outras coisas, atribuição do uso à MaxMind (seção 3), uso imediato das atualizações e destruição das versões antigas em até 30 dias após uma nova versão (seção 6.3), e restringe a divulgação a terceiros (seção 6.1). Leia os termos vigentes; este resumo não é parecer jurídico.
- O painel não mostra atribuição automaticamente (ele não sabe qual contrato cobre a sua base). Se a sua licença exigir, registre a atribuição onde o uso é apresentado na sua organização (por exemplo, na política interna de auditoria ou na documentação do painel).
- Bases comerciais GeoIP2 City/Country seguem o contrato comercial correspondente.
- Nunca coloque a base no webroot, no repositório ou no pacote do plugin: o plugin recusa uma base dentro do webroot (estado "dentro da pasta pública"), porque ela poderia ser baixada por qualquer um.

## Configuração

```php
'mmdb_path'         => '/srv/geoip/GeoLite2-City.mmdb', // absoluto, fora do webroot, legível pelo PHP
'mmdb_max_age_days' => 30,                              // padrão 30; aceita 1–3650
```

Tipos aceitos: bases cujo tipo contém `City` (país, região mais específica e cidade) ou `Country` (só país). Outros tipos (ASN, Anonymous-IP, Enterprise etc.) ficam "tipo de base não suportado". A política de idade usa o `build_epoch` da metadata: base compilada há mais de `mmdb_max_age_days` dias não é usada e a localização aparece "indisponível". Com GeoLite, 30 dias corresponde ao prazo de destruição citado acima; um valor maior não muda as suas obrigações.

Sem `mmdb_path` não há coluna nem campos de localização na API. Com ele, a API de eventos acrescenta:

```json
"geolocation": { "state": "available", "basis": "current_database", "database_built": "2026-10-22T18:52:57Z" },
"items": [ { "ip": "81.2.69.160", "location": { "status": "estimate", "country_code": "GB", "country": "Reino Unido", "region": "Inglaterra", "city": "Londres" } } ]
```

`state` ∈ `available`, `missing` (arquivo ausente ou ilegível), `misplaced` (dentro do webroot), `invalid` (corrompido, metadata inválida, erro durante a consulta), `unsupported`, `outdated`. Fora de `available` todo item vem com `status: "unavailable"` e campos nulos; com `available`, um IP privado ou sem resultado também. O IP é sempre exibido, e falha da base nunca falha a consulta nem a auditoria.

## Atualização e substituição atômica

Use a ferramenta de atualização do fornecedor (por exemplo `geoipupdate`), com a chave de licença na configuração **dela** (ex.: `GeoIP.conf` com permissão restrita), nunca na configuração do StealthAudit nem em options do WordPress. O processo deve trocar o arquivo de forma atômica:

1. Baixe para um arquivo temporário **no mesmo sistema de arquivos e diretório** da base (ex.: `/srv/geoip/.GeoLite2-City.mmdb.tmp`).
2. Verifique a integridade (o `geoipupdate` confere o hash; num processo próprio, confira checksum e abra a base uma vez antes de publicar).
3. Publique com `rename()`/`mv` sobre o caminho configurado. A troca é atômica no mesmo sistema de arquivos: uma consulta em andamento termina com a base antiga já aberta e a próxima abre a nova.
4. Nunca copie por cima (`cp nova.mmdb atual.mmdb` trunca e reescreve o arquivo em uso): durante a cópia as consultas veem uma base parcial e mostram "corrompida".
5. Apague versões antigas conforme a sua licença; o plugin não guarda cópias.

Agendamento sugerido: semanal (ou conforme o fornecedor publica), pelo agendador do host ou do pipeline de deploy. Sem atualização, a política de idade passa a mostrar "indisponível" em vez de uma estimativa velha.

## Custo

A base é aberta no máximo uma vez por consulta de página e só os IPs válidos e únicos daquela página são resolvidos (no máximo 100). Tamanho, tempo e memória com uma base real não foram medidos neste projeto: não havia base GeoLite/GeoIP real disponível, só as bases de teste oficiais da MaxMind (cerca de 18 KB). Meça no seu ambiente antes de publicar números; o leitor PHP puro abre a base por arquivo, sem carregá-la inteira.
