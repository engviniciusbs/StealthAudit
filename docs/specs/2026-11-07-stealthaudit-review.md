# StealthAudit — revisão estruturada do design

Data: 2026-11-07. Documento: [design](2026-11-07-stealthaudit-design.md).

Revisores reais, invocados sequencialmente conforme a skill multi-agent-brainstorming. Orçamento: uma avaliação por Skeptic, Guardian e Advocate, correções do designer e um Arbiter final. Nenhuma implementação ou certificação de segurança.

## Skeptic / Challenger

Revisor: /root/skeptic. Resultado inicial: REVISE.

| Objeção | Resolução do designer | Motivo |
| --- | --- | --- |
| S1 — Alta: distinguir falha tolerada da coleta de falha do acesso. | Aceita. Autenticação/autorização negam acesso quando estado, limites ou consumo único não podem ser verificados. Acrescentado teste. | Coleta não bloqueante não pode se tornar bypass. |
| S2 — Média: recuperação por código não tem transição final definida. | Aceita. Senha e código permitem somente novo cadastro; revogar provas e códigos antigos, confirmar novo TOTP antes dos logs. Testar concorrência. | Resolve ambiguidade sem alterar 2FA obrigatório. |
| S3 — Média: retenção temporal não limita tempestade de eventos. | Aceita. Exigir limites configuráveis de capacidade/ritmo antes do release; interromper gravação da categoria e sinalizar descartes; sem expurgo silencioso. | Concretiza os limites de volume já aceitos e reconhece a limitação do ambiente compartilhado. |

## Constraint Guardian

Revisor: /root/guardian. Resultado: APPROVED, sem objeções bloqueantes de arquitetura.

Condições para o plano técnico, todas aceitas como detalhamento do escopo:

- G1 — Definir backup, rotação e recuperação da chave TOTP em migração/perda; falhas continuam negando acesso ao painel.
- G2 — Estabelecer orçamentos de gravação, consulta e limpeza, incluindo testes de banco lento/indisponível; limites de quantidade não provam latência aceitável.
- G3 — Verificar licença, tamanho e custo de atualização da base GeoIP; ausência/falha mantém auditoria e localização indisponível.

## User Advocate

Revisor: /root/advocate. Resultado: APPROVED, sem objeções bloqueantes de usabilidade.

- U1 — Aceita: distinguir falta de sessão WordPress de falta/expiração da prova StealthAudit. Sem sessão, encaminhar ao login WordPress com retorno local validado. Expiração do painel pede novo código, sem sugerir perda de conta/registros.
- U2 — Aceita: distinguir listagem vazia de captura indisponível, limitada ou suspensa por capacidade. Ausência de registros não comprova ausência de erros.

## Integrator / Arbiter

Revisor: /root/arbiter. Disposição final: **APPROVED**.

- S1–S3 aceitas e resolvidas no design.
- G1–G3 aceitas como condições obrigatórias de detalhamento no plano técnico.
- U1–U2 aceitas e incorporadas nos estados da interface.
- Nenhuma objeção rejeitada. Nenhum bloqueador de design remanescente.

Entendimento confirmado pelo usuário, quatro revisores reais invocados e decisões documentadas. Design aceitável para concluir o brainstorming e elaborar o plano técnico. As pendências técnicas devem ser resolvidas nas etapas correspondentes do plano. A aprovação não autoriza implementação nem certifica segurança.
