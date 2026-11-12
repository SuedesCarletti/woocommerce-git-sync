<?php
defined( 'ABSPATH' ) || exit;

class WCGS_Webhook {

    private static $instance  = null;
    private static $raw_input = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register() {
        // Captura php://input ANTES do WordPress processar — só pode ser lido uma vez
        if ( null === self::$raw_input ) {
            self::$raw_input = file_get_contents( 'php://input' );
        }
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route( 'wcgs/v1', '/webhook/(?P<project_id>[a-zA-Z0-9_\-]+)', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'project_id' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ) );
    }

    public function handle( $request ) {
        $project_id = $request->get_param( 'project_id' );
        $log        = WCGS_Logger::instance();

        $p = WCGS_Project::find( $project_id );
        if ( ! $p ) {
            $log->log( 'warning', "Webhook para projeto desconhecido: {$project_id}" );
            return new WP_REST_Response( array( 'error' => 'Projeto não encontrado.' ), 404 );
        }

        // Usa o raw input capturado antes do WordPress processar
        $body = self::$raw_input;

        // Fallbacks
        if ( empty( $body ) ) $body = $request->get_body();
        if ( empty( $body ) && isset( $_POST['payload'] ) ) $body = stripslashes( $_POST['payload'] );

        // ---- Verifica HMAC
        $signature = $request->get_header( 'X-Hub-Signature-256' );
        if ( $p->webhook_secret ) {
            if ( ! $signature ) {
                $log->log( 'warning', 'Webhook sem assinatura.', $p->id );
                return new WP_REST_Response( array( 'error' => 'Assinatura ausente.' ), 401 );
            }
            $expected = 'sha256=' . hash_hmac( 'sha256', $body, $p->webhook_secret );
            if ( ! hash_equals( $expected, $signature ) ) {
                $log->log( 'warning', 'Assinatura inválida.', $p->id );
                return new WP_REST_Response( array( 'error' => 'Assinatura inválida.' ), 401 );
            }
        }

        // ---- Parse JSON
        $event   = $request->get_header( 'X-GitHub-Event' );
        $payload = json_decode( $body, true );
        $raw_ref = isset( $payload['ref'] ) ? $payload['ref'] : '';

        $log->log( 'info', "Webhook recebido (evento: {$event}, ref: {$raw_ref})", $p->id );

        if ( $event !== 'push' ) {
            return new WP_REST_Response( array( 'message' => "Evento '{$event}' ignorado." ), 200 );
        }

        // ---- Verifica branch
        $branch      = $p->branch;
        $ref_matches = (
            $raw_ref === "refs/heads/{$branch}" ||
            $raw_ref === $branch ||
            empty( $raw_ref )
        );

        if ( ! $ref_matches ) {
            $log->log( 'info', "Push ignorado: ref '{$raw_ref}' não corresponde ao branch '{$branch}'.", $p->id );
            return new WP_REST_Response( array( 'message' => 'Branch ignorado.' ), 200 );
        }

        if ( ! $p->auto_pull ) {
            $log->log( 'info', 'Auto-pull desabilitado.', $p->id );
            return new WP_REST_Response( array( 'message' => 'Auto-pull desabilitado.' ), 200 );
        }

        // ---- Executa pull diretamente
        $commit_id  = isset( $payload['head_commit']['id'] )             ? substr( $payload['head_commit']['id'], 0, 7 ) : '?';
        $commit_msg = isset( $payload['head_commit']['message'] )        ? strtok( $payload['head_commit']['message'], "\n" ) : '';
        $author     = isset( $payload['head_commit']['author']['name'] ) ? $payload['head_commit']['author']['name'] : '?';

        $log->log( 'info', "Executando pull. Commit {$commit_id} por {$author}: {$commit_msg}", $p->id );

        $result = WCGS_Git::pull( $p );

        if ( $result['success'] ) {
            $log->log( 'success', "Pull via webhook concluído: " . $result['message'], $p->id );
        } else {
            $log->log( 'error', "Pull via webhook falhou: " . $result['message'], $p->id );
        }

        return new WP_REST_Response( array(
            'message' => $result['message'],
            'success' => $result['success'],
        ), 200 );
    }
}
