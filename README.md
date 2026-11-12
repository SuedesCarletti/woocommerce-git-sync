# WC Git Sync v2 – Multi-Projeto

Plugin WordPress para gerenciar **múltiplos repositórios Git/GitHub** com sincronização bidirecional, tudo a partir de um painel unificado.

---

## Novidades v2 vs v1

| Recurso | v1 | v2 |
|---|---|---|
| Projetos simultâneos | 1 fixo | **Ilimitado** |
| Webhook por projeto | URL única | **URL única por projeto** |
| WP-Cron por projeto | Global | **Independente por projeto** |
| Log filtrado por projeto | | |
| Interface | Formulário simples | **SPA com sidebar** |

---

## Como instalar

1. Faça upload da pasta `wc-git-sync/` em `wp-content/plugins/`
2. Ative em **WordPress → Plugins**
3. Acesse **Git Sync** no menu lateral

---

## Adicionando um projeto

1. Clique em **Novo Projeto** na sidebar ou no dashboard
2. Preencha:
   - **Nome** – identificação no painel (ex: `Plugin Fretes EMPRESAX`)
   - **Caminho no Servidor** – caminho absoluto do repositório (ex: `/var/www/html/wp-content/plugins/meu-plugin`)
   - **Branch** – ex: `main`
   - **Token GitHub (PAT)** – Personal Access Token com escopo `repo`
   - **Webhook Secret** – gerado automaticamente; copie para o GitHub
   - **Sync Agendado** – intervalo do WP-Cron (ou "Desabilitado" para usar só webhook)
   - **Auto Pull** – ativa pull automático ao receber webhook
3. Salve → o webhook URL do projeto é exibido automaticamente

---

## Configurando o Webhook no GitHub

Para cada projeto, no repositório GitHub:

1. **Settings → Webhooks → Add webhook**
2. **Payload URL**: copie do painel do projeto
3. **Content type**: `application/json`
4. **Secret**: mesmo valor exibido no painel
5. **Events**: `Just the push event`

O endpoint segue o padrão:
```
POST /wp-json/wcgs/v1/webhook/{project_id}
```

---

## Fluxo de Sincronização

```
GitHub push
    │
    └──► POST /wp-json/wcgs/v1/webhook/{project_id}
              │
              ├── Valida HMAC (X-Hub-Signature-256)
              ├── Verifica branch monitorado
              └── Agenda wcgs_project_sync via WP-Cron
                        │
                        └── git fetch + git pull --rebase

Painel WP (manual por projeto)
    ├── Pull  ──► git fetch + pull --rebase
    └── Push  ──► git add -A + commit + push

WP-Cron (intervalo por projeto)
    └── git pull --rebase (se auto_pull ativo)
```

---

## Requisitos

- PHP 7.4+, WordPress 5.6+
- `git` instalado e acessível pelo usuário do PHP
- Repositório git já inicializado no caminho configurado
- `exec()` habilitado no PHP
- Usuário do PHP (`www-data`) com permissão de leitura/escrita no repositório

---

## Segurança

- Token injetado via `GIT_ASKPASS` — nunca aparece em argumentos CLI
- Cada webhook validado com `hash_hmac` + `hash_equals` (timing-safe)
- Ações AJAX protegidas por nonce + `manage_options`
- Secrets armazenados apenas em `wp_options` (banco local)

---

## Licença

Todos os direitos reservados. Código publicado apenas para consulta e avaliação; veja o arquivo [LICENSE](LICENSE).
