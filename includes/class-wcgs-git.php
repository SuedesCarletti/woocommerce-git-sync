<?php
defined( 'ABSPATH' ) || exit;

/**
 * Operações Git via API REST do GitHub.
 * Sem exec(), shell_exec() ou proc_open().
 *
 * Estratégia de push:
 *  - Mantém cache local de SHAs em .git/wcgs_sha_cache.json
 *  - No push, varre apenas arquivos locais e compara SHA com cache
 *  - Envia somente os arquivos que mudaram via PUT /contents/{path}
 *  - Atualiza cache após cada push/pull bem-sucedido
 *
 * Isso evita buscar a árvore inteira do GitHub a cada operação.
 */
class WCGS_Git {

    /* ── helpers de API ─────────────────────────────────────────── */

    private static function api_get( $p, $endpoint ) {
        $response = wp_remote_get( 'https://api.github.com/' . ltrim( $endpoint, '/' ), array(
            'timeout' => 15,
            'headers' => self::headers( $p ),
        ) );
        return self::parse( $response );
    }

    private static function api_post( $p, $endpoint, $body ) {
        $response = wp_remote_post( 'https://api.github.com/' . ltrim( $endpoint, '/' ), array(
            'timeout' => 15,
            'headers' => self::headers( $p ),
            'body'    => json_encode( $body ),
        ) );
        return self::parse( $response );
    }

    private static function api_put( $p, $endpoint, $body ) {
        $response = wp_remote_request( 'https://api.github.com/' . ltrim( $endpoint, '/' ), array(
            'method'  => 'PUT',
            'timeout' => 15,
            'headers' => self::headers( $p ),
            'body'    => json_encode( $body ),
        ) );
        return self::parse( $response );
    }

    private static function headers( $p ) {
        return array(
            'Authorization' => 'Bearer ' . $p->github_token,
            'Accept'        => 'application/vnd.github+json',
            'Content-Type'  => 'application/json',
            'User-Agent'    => 'WC-Git-Sync-WordPress-Plugin',
        );
    }

    private static function parse( $response ) {
        if ( is_wp_error( $response ) ) {
            return array( 'ok' => false, 'code' => 0, 'body' => array(), 'error' => $response->get_error_message() );
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return array( 'ok' => $code >= 200 && $code < 300, 'code' => $code, 'body' => $body, 'error' => '' );
    }

    /* ── cache de SHAs ───────────────────────────────────────────── */

    private static function cache_path( $p ) {
        return rtrim( $p->repo_path, '/' ) . '/.git/wcgs_sha_cache.json';
    }

    private static function load_cache( $p ) {
        $path = self::cache_path( $p );
        if ( ! file_exists( $path ) ) return array();
        $data = json_decode( file_get_contents( $path ), true );
        return is_array( $data ) ? $data : array();
    }

    private static function save_cache( $p, $cache ) {
        file_put_contents( self::cache_path( $p ), json_encode( $cache ) );
    }

    /* ── SHA compatível com git ──────────────────────────────────── */

    private static function git_blob_sha( $content ) {
        return sha1( 'blob ' . strlen( $content ) . "\0" . $content );
    }

    /* ── detecta owner/repo ──────────────────────────────────────── */

    public static function get_owner_repo( $p ) {
        // Prioridade 1: lê .git/config
        $git_config = rtrim( $p->repo_path, '/' ) . '/.git/config';
        if ( file_exists( $git_config ) ) {
            $cfg     = file_get_contents( $git_config );
            $remote  = $p->remote ? $p->remote : 'origin';
            $pattern = '/\[remote\s+"' . preg_quote( $remote, '/' ) . '"[^\[]*url\s*=\s*([^\r\n]+)/s';
            if ( preg_match( $pattern, $cfg, $m ) ) {
                $url = trim( $m[1] );
                if ( preg_match( '/github\.com[:\\/]([^\\/]+)\\/([^\\/\s\.]+?)(\.git)?$/i', $url, $r ) ) {
                    return array( $r[1], $r[2] );
                }
            }
        }

        // Prioridade 2: github_repo_url do formulário
        $url = isset( $p->github_repo_url ) ? trim( $p->github_repo_url ) : '';
        if ( $url && preg_match( '/github\.com[:\\/]([^\\/]+)\\/([^\\/\s\.]+?)(\.git)?$/i', $url, $r ) ) {
            return array( $r[1], $r[2] );
        }

        return array( '', '' );
    }

    /* ── cria .git/config automaticamente ───────────────────────── */

    public static function maybe_create_git_config( $p ) {
        if ( empty( $p->repo_path ) ) {
            return array( 'created' => false, 'message' => 'Caminho do repositório não configurado.' );
        }

        $git_dir    = rtrim( $p->repo_path, '/' ) . '/.git';
        $git_config = $git_dir . '/config';

        if ( file_exists( $git_config ) ) {
            return array( 'created' => false, 'message' => '.git/config já existe.' );
        }

        $repo_url = isset( $p->github_repo_url ) ? trim( $p->github_repo_url ) : '';
        if ( ! $repo_url ) {
            return array( 'created' => false, 'message' => 'URL do repositório GitHub não informado.' );
        }

        if ( substr( $repo_url, -4 ) !== '.git' ) {
            $repo_url .= '.git';
        }

        $branch = $p->branch ? $p->branch : 'main';
        $remote = $p->remote ? $p->remote : 'origin';

        if ( ! is_dir( $git_dir ) && ! wp_mkdir_p( $git_dir ) ) {
            return array( 'created' => false, 'message' => 'Não foi possível criar a pasta .git.' );
        }

        $cfg = "[core]\n\trepositoryformatversion = 0\n\tfilemode = false\n\tbare = false\n\n"
             . "[remote \"{$remote}\"]\n\turl = {$repo_url}\n\tfetch = +refs/heads/*:refs/remotes/{$remote}/*\n\n"
             . "[branch \"{$branch}\"]\n\tremote = {$remote}\n\tmerge = refs/heads/{$branch}\n";

        if ( file_put_contents( $git_config, $cfg ) === false ) {
            return array( 'created' => false, 'message' => 'Não foi possível escrever .git/config.' );
        }

        return array( 'created' => true, 'message' => ".git/config criado em {$git_dir}" );
    }

    /* ── PULL ────────────────────────────────────────────────────── */

    public static function pull( $p ) {
        $log = WCGS_Logger::instance();
        $log->log( 'info', "Pull iniciado ({$p->branch})", $p->id );

        list( $owner, $repo ) = self::get_owner_repo( $p );
        if ( ! $owner || ! $repo ) {
            $msg = 'owner/repo não detectado. Verifique o caminho e o .git/config.';
            $log->log( 'error', $msg, $p->id );
            return array( 'success' => false, 'message' => $msg );
        }

        $tree = self::api_get( $p, "repos/{$owner}/{$repo}/git/trees/{$p->branch}?recursive=1" );
        if ( ! $tree['ok'] ) {
            $msg = 'Erro ao buscar árvore: ' . ( isset( $tree['body']['message'] ) ? $tree['body']['message'] : "HTTP {$tree['code']}" );
            $log->log( 'error', $msg, $p->id );
            return array( 'success' => false, 'message' => $msg );
        }

        $files        = isset( $tree['body']['tree'] ) ? $tree['body']['tree'] : array();
        $cache        = self::load_cache( $p );
        $base         = rtrim( $p->repo_path, '/' );
        $updated      = 0;
        $deleted      = 0;
        $errors       = array();

        // Monta mapa de arquivos remotos para detectar deleções
        $remote_paths = array();
        foreach ( $files as $file ) {
            if ( $file['type'] === 'blob' ) {
                $remote_paths[ $file['path'] ] = $file['sha'];
            }
        }

        // Atualiza/cria arquivos que mudaram no GitHub
        foreach ( $files as $file ) {
            if ( $file['type'] !== 'blob' ) continue;

            if ( isset( $cache[ $file['path'] ] ) && $cache[ $file['path'] ] === $file['sha'] ) continue;

            $blob = self::api_get( $p, "repos/{$owner}/{$repo}/git/blobs/{$file['sha']}" );
            if ( ! $blob['ok'] ) { $errors[] = $file['path']; continue; }

            $content    = isset( $blob['body']['content'] ) ? base64_decode( $blob['body']['content'] ) : '';
            $local_path = $base . '/' . $file['path'];
            $dir        = dirname( $local_path );

            if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );

            if ( file_put_contents( $local_path, $content ) !== false ) {
                $cache[ $file['path'] ] = $file['sha'];
                $updated++;
            } else {
                $errors[] = $file['path'] . ' (sem permissão)';
            }
        }

        // Deleta localmente arquivos que foram removidos no GitHub
        foreach ( $cache as $cached_path => $cached_sha ) {
            if ( ! isset( $remote_paths[ $cached_path ] ) ) {
                $local_path = $base . '/' . $cached_path;
                if ( file_exists( $local_path ) ) {
                    unlink( $local_path );
                    $log->log( 'info', "Arquivo deletado localmente: {$cached_path}", $p->id );
                    $deleted++;
                }
                unset( $cache[ $cached_path ] );
            }
        }

        self::save_cache( $p, $cache );

        if ( ! empty( $errors ) ) {
            $msg = "Pull com erros. Atualizados: {$updated}, deletados: {$deleted}. Falhas: " . implode( ', ', $errors );
            $log->log( 'warning', $msg, $p->id );
            return array( 'success' => false, 'message' => $msg );
        }

        $parts = array();
        if ( $updated > 0 ) $parts[] = "{$updated} atualizado(s)";
        if ( $deleted > 0 ) $parts[] = "{$deleted} deletado(s)";
        $msg = ! empty( $parts ) ? "Pull concluído. " . implode( ', ', $parts ) . "." : 'Tudo já estava atualizado.';
        $log->log( 'success', $msg, $p->id );
        do_action( 'wcgs_after_pull', $p, $msg );
        return array( 'success' => true, 'message' => $msg, 'output' => $msg );
    }

    /* ── PUSH ────────────────────────────────────────────────────── */

    public static function push( $p, $commit_message = 'WP auto-commit' ) {
        $log = WCGS_Logger::instance();
        $log->log( 'info', "Push iniciado ({$p->branch})", $p->id );

        list( $owner, $repo ) = self::get_owner_repo( $p );
        if ( ! $owner || ! $repo ) {
            $msg = 'owner/repo não detectado.';
            $log->log( 'error', $msg, $p->id );
            return array( 'success' => false, 'message' => $msg );
        }

        $cache   = self::load_cache( $p );
        $base    = rtrim( $p->repo_path, '/' );
        $changed = array(); // arquivos criados/modificados
        $deleted = array(); // arquivos removidos localmente
        $local_files = array(); // mapa de arquivos existentes localmente

        // Varre arquivos locais, detecta criações e modificações
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $base, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) continue;

            $abs      = $file->getPathname();
            $relative = ltrim( str_replace( $base, '', $abs ), '/' );
            $relative = str_replace( '\\', '/', $relative );

            if ( strpos( $relative, '.git/' ) === 0 ) continue;

            $content   = file_get_contents( $abs );
            $local_sha = self::git_blob_sha( $content );
            $local_files[ $relative ] = true;

            if ( isset( $cache[ $relative ] ) && $cache[ $relative ] === $local_sha ) continue;

            $changed[] = array( 'path' => $relative, 'content' => $content, 'sha' => $local_sha );
        }

        // Detecta deleções: arquivos que estão no cache mas não existem mais localmente
        foreach ( $cache as $cached_path => $cached_sha ) {
            if ( $cached_sha === '__deleted__' ) continue;
            if ( ! isset( $local_files[ $cached_path ] ) ) {
                $deleted[] = $cached_path;
            }
        }

        if ( empty( $changed ) && empty( $deleted ) ) {
            $log->log( 'info', 'Push ignorado: sem mudanças locais.', $p->id );
            return array( 'success' => true, 'message' => 'Nenhuma mudança para enviar.' );
        }

        $total = count( $changed ) + count( $deleted );
        $log->log( 'info', count( $changed ) . ' modificado(s), ' . count( $deleted ) . ' deletado(s) detectado(s).', $p->id );

        $sent   = 0;
        $errors = array();

        // Envia arquivos criados/modificados
        foreach ( $changed as $f ) {
            $existing     = self::api_get( $p, "repos/{$owner}/{$repo}/contents/{$f['path']}?ref={$p->branch}" );
            $existing_sha = ( $existing['ok'] && isset( $existing['body']['sha'] ) ) ? $existing['body']['sha'] : null;

            $body = array(
                'message' => $commit_message,
                'content' => base64_encode( $f['content'] ),
                'branch'  => $p->branch,
            );
            if ( $existing_sha ) {
                $body['sha'] = $existing_sha;
            }

            $result = self::api_put( $p, "repos/{$owner}/{$repo}/contents/{$f['path']}", $body );

            if ( $result['ok'] ) {
                $cache[ $f['path'] ] = $f['sha'];
                $sent++;
            } else {
                $detail   = isset( $result['body']['message'] ) ? $result['body']['message'] : "HTTP {$result['code']}";
                $errors[] = $f['path'] . " ({$detail})";
                $log->log( 'warning', "Erro ao enviar {$f['path']}: {$detail}", $p->id );
            }
        }

        // Deleta arquivos removidos no GitHub via DELETE /contents/{path}
        foreach ( $deleted as $del_path ) {
            $existing     = self::api_get( $p, "repos/{$owner}/{$repo}/contents/{$del_path}?ref={$p->branch}" );
            $existing_sha = ( $existing['ok'] && isset( $existing['body']['sha'] ) ) ? $existing['body']['sha'] : null;

            if ( ! $existing_sha ) {
                // Arquivo já não existe no GitHub, só remove do cache
                unset( $cache[ $del_path ] );
                $sent++;
                continue;
            }

            $del_body = array(
                'message' => $commit_message,
                'sha'     => $existing_sha,
                'branch'  => $p->branch,
            );

            $del_response = wp_remote_request(
                'https://api.github.com/repos/' . $owner . '/' . $repo . '/contents/' . rawurlencode( $del_path ),
                array(
                    'method'  => 'DELETE',
                    'timeout' => 15,
                    'headers' => self::headers( $p ),
                    'body'    => json_encode( $del_body ),
                )
            );

            $del_result = self::parse( $del_response );

            if ( $del_result['ok'] ) {
                unset( $cache[ $del_path ] );
                $sent++;
                $log->log( 'info', "Arquivo deletado no GitHub: {$del_path}", $p->id );
            } else {
                $detail   = isset( $del_result['body']['message'] ) ? $del_result['body']['message'] : "HTTP {$del_result['code']}";
                $errors[] = "{$del_path} (delete falhou: {$detail})";
                $log->log( 'warning', "Erro ao deletar {$del_path}: {$detail}", $p->id );
            }
        }

        self::save_cache( $p, $cache );

        if ( ! empty( $errors ) ) {
            $msg = "Push parcial. Processados: {$sent}/{$total}. Falhas: " . implode( ', ', $errors );
            $log->log( 'warning', $msg, $p->id );
            return array( 'success' => false, 'message' => $msg, 'output' => $msg );
        }

        $msg = "Push concluído. {$sent} arquivo(s) processado(s).";
        $log->log( 'success', $msg, $p->id );
        do_action( 'wcgs_after_push', $p, $msg );
        return array( 'success' => true, 'message' => $msg, 'output' => $msg );
    }

    /* ── STATUS ──────────────────────────────────────────────────── */

    public static function status( $p ) {
        list( $owner, $repo ) = self::get_owner_repo( $p );

        if ( ! $owner || ! $repo ) {
            return array(
                'is_valid_repo'     => file_exists( rtrim( $p->repo_path, '/' ) . '/.git/config' ),
                'current_branch'    => $p->branch,
                'recent_commits'    => array(),
                'has_local_changes' => false,
                'local_changes'     => '',
                'remotes'           => '',
                'note'              => 'Configure o URL do repositório para ver o status completo.',
            );
        }

        // Commits recentes
        $commits_resp = self::api_get( $p, "repos/{$owner}/{$repo}/commits?sha={$p->branch}&per_page=5" );
        $recent = array();
        if ( $commits_resp['ok'] && is_array( $commits_resp['body'] ) ) {
            foreach ( $commits_resp['body'] as $c ) {
                $sha     = substr( isset( $c['sha'] ) ? $c['sha'] : '', 0, 7 );
                $message = isset( $c['commit']['message'] ) ? strtok( $c['commit']['message'], "\n" ) : '';
                $recent[] = $sha . ' ' . $message;
            }
        }

        // Detecta mudanças comparando cache com arquivos locais
        $cache   = self::load_cache( $p );
        $base    = rtrim( $p->repo_path, '/' );
        $changed = array();

        if ( is_dir( $base ) ) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $base, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ( $iterator as $file ) {
                if ( ! $file->isFile() ) continue;
                $abs      = $file->getPathname();
                $relative = ltrim( str_replace( $base, '', $abs ), '/' );
                if ( strpos( $relative, '.git/' ) === 0 ) continue;
                $local_sha = self::git_blob_sha( file_get_contents( $abs ) );
                if ( ! isset( $cache[ $relative ] ) || $cache[ $relative ] !== $local_sha ) {
                    $changed[] = 'M ' . $relative;
                }
            }
        }

        return array(
            'is_valid_repo'     => true,
            'current_branch'    => $p->branch,
            'recent_commits'    => $recent,
            'has_local_changes' => ! empty( $changed ),
            'local_changes'     => implode( "\n", $changed ),
            'remotes'           => "{$owner}/{$repo}",
        );
    }

    /* ── TEST CONNECTION ─────────────────────────────────────────── */

    public static function test_connection( $p ) {
        $results = array();

        if ( empty( $p->github_token ) ) {
            return array( 'success' => false, 'steps' => array(), 'message' => 'Token GitHub não configurado.' );
        }
        $results[] = array( 'label' => 'Token configurado', 'ok' => true, 'detail' => substr( $p->github_token, 0, 4 ) . '****' );

        list( $owner, $repo ) = self::get_owner_repo( $p );

        if ( ! $owner || ! $repo ) {
            $auth = self::api_get( $p, 'user' );
            if ( ! $auth['ok'] ) {
                $detail    = isset( $auth['body']['message'] ) ? $auth['body']['message'] : ( $auth['error'] ? $auth['error'] : "HTTP {$auth['code']}" );
                $results[] = array( 'label' => 'Autenticação GitHub', 'ok' => false, 'detail' => $detail );
                return array( 'success' => false, 'steps' => $results, 'message' => 'Token inválido ou sem acesso ao GitHub.' );
            }
            $results[] = array( 'label' => 'Autenticação GitHub', 'ok' => true, 'detail' => 'Usuário: ' . ( isset( $auth['body']['login'] ) ? $auth['body']['login'] : '?' ) );
            $results[] = array( 'label' => 'Repositório', 'ok' => null, 'detail' => 'Não detectado. Preencha o campo "URL do Repositório GitHub" e salve o projeto.' );
            return array( 'success' => false, 'steps' => $results, 'message' => 'Repositório não detectado.' );
        }

        // Uma chamada: testa token + repo ao mesmo tempo
        $repo_resp = self::api_get( $p, "repos/{$owner}/{$repo}" );

        if ( $repo_resp['code'] === 401 || $repo_resp['code'] === 403 ) {
            $results[] = array( 'label' => 'Autenticação GitHub', 'ok' => false, 'detail' => isset( $repo_resp['body']['message'] ) ? $repo_resp['body']['message'] : "HTTP {$repo_resp['code']}" );
            return array( 'success' => false, 'steps' => $results, 'message' => 'Token inválido ou sem permissão.' );
        }

        if ( $repo_resp['code'] === 0 ) {
            $results[] = array( 'label' => 'Conexão com GitHub', 'ok' => false, 'detail' => $repo_resp['error'] ? $repo_resp['error'] : 'Timeout' );
            return array( 'success' => false, 'steps' => $results, 'message' => 'Servidor não acessa api.github.com.' );
        }

        if ( ! $repo_resp['ok'] ) {
            $results[] = array( 'label' => 'Autenticação GitHub', 'ok' => true, 'detail' => 'Token válido' );
            $results[] = array( 'label' => "Repositório {$owner}/{$repo}", 'ok' => false, 'detail' => isset( $repo_resp['body']['message'] ) ? $repo_resp['body']['message'] : "HTTP {$repo_resp['code']}" );
            return array( 'success' => false, 'steps' => $results, 'message' => 'Repositório não encontrado ou sem permissão.' );
        }

        $is_private = isset( $repo_resp['body']['private'] ) && $repo_resp['body']['private'];
        $def_branch = isset( $repo_resp['body']['default_branch'] ) ? $repo_resp['body']['default_branch'] : '';
        $results[]  = array( 'label' => 'Autenticação GitHub', 'ok' => true, 'detail' => 'Token válido' );
        $results[]  = array( 'label' => 'Repositório', 'ok' => true, 'detail' => "{$owner}/{$repo} — " . ( $is_private ? 'privado' : 'público' ) );

        $branch_ok = ( $def_branch === $p->branch );
        if ( ! $branch_ok ) {
            $br        = self::api_get( $p, "repos/{$owner}/{$repo}/branches/{$p->branch}" );
            $branch_ok = $br['ok'];
        }
        $results[] = array( 'label' => "Branch '{$p->branch}'", 'ok' => $branch_ok, 'detail' => $branch_ok ? 'Encontrado' : 'Não encontrado' );

        if ( empty( $p->webhook_secret ) ) {
            $results[] = array( 'label' => 'Webhook Secret', 'ok' => false, 'detail' => 'Não configurado' );
        } else {
            $results[] = array( 'label' => 'Webhook Secret', 'ok' => true, 'detail' => strlen( $p->webhook_secret ) . ' caracteres' );
        }

        $success = $branch_ok;
        return array(
            'success' => $success,
            'steps'   => $results,
            'message' => $success ? 'Conexão com GitHub OK.' : 'Verifique os itens marcados.',
        );
    }
}
