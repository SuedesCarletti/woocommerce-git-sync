<?php
/**
 * Plugin Name:       WC Git Sync
 * Plugin URI:        https://github.com/your-repo/wc-git-sync
 * Description:       Gerencia múltiplos projetos Git/GitHub com sync bidirecional diretamente do painel WordPress.
 * Version:           2.0.1
 * Author:            EMPRESAX Dev
 * License:           Todos os direitos reservados / All rights reserved
 * Text Domain:       wc-git-sync
 * Requires at least: 5.6
 * Requires PHP:      7.2
 */

defined( 'ABSPATH' ) || exit;

define( 'WCGS_VERSION',      '2.0.1' );
define( 'WCGS_PLUGIN_DIR',   plugin_dir_path( __FILE__ ) );
define( 'WCGS_PLUGIN_URL',   plugin_dir_url( __FILE__ ) );
define( 'WCGS_PLUGIN_FILE',  __FILE__ );
define( 'WCGS_PROJECTS_KEY', 'wcgs_projects' );

require_once WCGS_PLUGIN_DIR . 'includes/class-wcgs-logger.php';
require_once WCGS_PLUGIN_DIR . 'includes/class-wcgs-project.php';
require_once WCGS_PLUGIN_DIR . 'includes/class-wcgs-git.php';
require_once WCGS_PLUGIN_DIR . 'includes/class-wcgs-webhook.php';
require_once WCGS_PLUGIN_DIR . 'includes/class-wcgs-scheduler.php';
require_once WCGS_PLUGIN_DIR . 'admin/class-wcgs-admin.php';

register_activation_hook(   __FILE__, array( 'WCGS_Plugin', 'activate'   ) );
register_deactivation_hook( __FILE__, array( 'WCGS_Plugin', 'deactivate' ) );

class WCGS_Plugin {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init',                  array( $this, 'init' ) );
        add_action( 'admin_menu',            array( 'WCGS_Admin', 'register_menu'  ) );
        add_action( 'admin_enqueue_scripts', array( 'WCGS_Admin', 'enqueue_assets' ) );

        $ajax_actions = array(
            'wcgs_get_projects', 'wcgs_save_project', 'wcgs_delete_project',
            'wcgs_pull', 'wcgs_push', 'wcgs_get_status',
            'wcgs_get_log', 'wcgs_clear_log',
            'wcgs_get_global_settings', 'wcgs_save_global_settings',
            'wcgs_test_webhook', 'wcgs_clear_cache',
        );
        foreach ( $ajax_actions as $action ) {
            add_action( "wp_ajax_{$action}", array( $this, 'dispatch_ajax' ) );
        }

        add_action( 'wcgs_project_sync', array( $this, 'run_project_sync' ), 10, 1 );
    }

    public function init() {
        WCGS_Webhook::instance()->register();
        WCGS_Scheduler::instance()->setup();
    }

    /* ---- AJAX dispatcher ---- */

    public function dispatch_ajax() {
        $raw    = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : '';
        $action = str_replace( 'wcgs_', '', sanitize_text_field( $raw ) );
        $method = 'ajax_' . $action;
        if ( method_exists( $this, $method ) ) {
            $this->$method();
        } else {
            wp_send_json_error( array( 'message' => 'Ação desconhecida.' ), 400 );
        }
    }

    private function auth() {
        check_ajax_referer( 'wcgs_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permissão negada.' ), 403 );
        }
    }

    private function get_project_or_fail() {
        $id = sanitize_text_field( isset( $_POST['project_id'] ) ? $_POST['project_id'] : ( isset( $_GET['project_id'] ) ? $_GET['project_id'] : '' ) );
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => 'project_id ausente.' ), 400 );
        }
        $p = WCGS_Project::find( $id );
        if ( ! $p ) {
            wp_send_json_error( array( 'message' => "Projeto '{$id}' não encontrado." ), 404 );
        }
        return $p;
    }

    /* ---- project CRUD ---- */

    public function ajax_get_projects() {
        $this->auth();
        $all      = WCGS_Project::all();
        $projects = array();
        foreach ( $all as $p ) {
            $projects[] = $p->to_array();
        }
        wp_send_json_success( array( 'projects' => $projects ) );
    }

    public function ajax_save_project() {
        $this->auth();
        $id = sanitize_text_field( isset( $_POST['project_id'] ) ? $_POST['project_id'] : '' );
        $p  = ( $id ) ? WCGS_Project::find( $id ) : null;
        if ( ! $p ) {
            $p = new WCGS_Project();
        }

        $p->name           = sanitize_text_field( isset( $_POST['name'] )           ? $_POST['name']           : '' );
        $p->repo_path      = sanitize_text_field( isset( $_POST['repo_path'] )      ? $_POST['repo_path']      : '' );
        $p->branch         = sanitize_text_field( isset( $_POST['branch'] )         ? $_POST['branch']         : 'main' );
        $p->remote         = sanitize_text_field( isset( $_POST['remote'] )         ? $_POST['remote']         : 'origin' );
        $p->github_token   = sanitize_text_field( isset( $_POST['github_token'] )   ? $_POST['github_token']   : '' );
        $p->github_repo_url = sanitize_text_field( isset( $_POST['github_repo_url'] ) ? $_POST['github_repo_url'] : '' );
        $p->webhook_secret = sanitize_text_field( isset( $_POST['webhook_secret'] ) ? $_POST['webhook_secret'] : wp_generate_password( 32, false ) );
        $p->auto_pull      = ( isset( $_POST['auto_pull'] )  ? $_POST['auto_pull']  : '0' ) === '1';
        $p->auto_push      = ( isset( $_POST['auto_push'] )  ? $_POST['auto_push']  : '0' ) === '1';
        $p->sync_interval  = sanitize_text_field( isset( $_POST['sync_interval'] )  ? $_POST['sync_interval']  : 'disabled' );
        $p->notify_email   = sanitize_email( isset( $_POST['notify_email'] )        ? $_POST['notify_email']   : '' );
        $p->save();

        // Cria .git/config automaticamente se não existir
        $git_setup = WCGS_Git::maybe_create_git_config( $p );
        if ( $git_setup['created'] ) {
            WCGS_Logger::instance()->log( 'info', '.git/config criado automaticamente.', $p->id );
        }

        WCGS_Scheduler::instance()->reschedule( $p );

        wp_send_json_success( array( 'project' => $p->to_array(), 'message' => 'Projeto salvo.' ) );
    }

    public function ajax_delete_project() {
        $this->auth();
        $p = $this->get_project_or_fail();
        WCGS_Scheduler::instance()->unschedule( $p );
        $p->delete();
        wp_send_json_success( array( 'message' => 'Projeto removido.' ) );
    }

    /* ---- git operations ---- */

    public function ajax_pull() {
        $this->auth();
        $p      = $this->get_project_or_fail();
        $result = WCGS_Git::pull( $p );
        wp_send_json( $result );
    }

    public function ajax_push() {
        $this->auth();
        $p       = $this->get_project_or_fail();
        $message = sanitize_text_field( isset( $_POST['message'] ) ? $_POST['message'] : 'WP auto-commit' );
        $result  = WCGS_Git::push( $p, $message );
        wp_send_json( $result );
    }

    public function ajax_get_status() {
        $this->auth();
        $p = $this->get_project_or_fail();
        wp_send_json_success( WCGS_Git::status( $p ) );
    }

    /* ---- log ---- */

    public function ajax_get_log() {
        $this->auth();
        $project_id = sanitize_text_field( isset( $_POST['project_id'] ) ? $_POST['project_id'] : '' );
        $entries    = WCGS_Logger::instance()->get_entries( 200, $project_id !== '' ? $project_id : null );
        wp_send_json_success( array( 'entries' => $entries ) );
    }

    public function ajax_clear_log() {
        $this->auth();
        $project_id = sanitize_text_field( isset( $_POST['project_id'] ) ? $_POST['project_id'] : '' );
        WCGS_Logger::instance()->clear( $project_id !== '' ? $project_id : null );
        wp_send_json_success();
    }

    /* ---- clear sha cache ---- */

    public function ajax_clear_cache() {
        $this->auth();
        $p          = $this->get_project_or_fail();
        $cache_path = rtrim( $p->repo_path, '/' ) . '/.git/wcgs_sha_cache.json';
        if ( file_exists( $cache_path ) ) {
            unlink( $cache_path );
        }
        WCGS_Logger::instance()->log( 'info', 'Cache de SHAs limpo. Próximo push enviará todos os arquivos.', $p->id );
        wp_send_json_success( array( 'message' => 'Cache limpo. O próximo push comparará todos os arquivos com o GitHub.' ) );
    }

    /* ---- github connection test ---- */

    public function ajax_test_webhook() {
        $this->auth();
        $p      = $this->get_project_or_fail();
        $result = WCGS_Git::test_connection( $p );

        WCGS_Logger::instance()->log(
            $result['success'] ? 'success' : 'warning',
            'Teste de conexão: ' . $result['message'],
            $p->id
        );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /* ---- global settings ---- */

    public function ajax_get_global_settings() {
        $this->auth();
        wp_send_json_success( array(
            'notify_email' => get_option( 'wcgs_global_notify_email', get_option( 'admin_email' ) ),
        ) );
    }

    public function ajax_save_global_settings() {
        $this->auth();
        update_option( 'wcgs_global_notify_email', sanitize_email( isset( $_POST['notify_email'] ) ? $_POST['notify_email'] : '' ) );
        wp_send_json_success( array( 'message' => 'Configurações globais salvas.' ) );
    }

    /* ---- cron callback ---- */

    public function run_project_sync( $project_id ) {
        $p = WCGS_Project::find( $project_id );
        if ( ! $p ) return;
        if ( $p->auto_pull ) WCGS_Git::pull( $p );
    }

    /* ---- activation / deactivation ---- */

    public static function activate() {
        if ( ! get_option( WCGS_PROJECTS_KEY ) ) {
            add_option( WCGS_PROJECTS_KEY, array() );
        }
        WCGS_Logger::instance()->log( 'info', 'Plugin ativado (v2.0.1).', '' );
    }

    public static function deactivate() {
        foreach ( WCGS_Project::all() as $p ) {
            WCGS_Scheduler::instance()->unschedule( $p );
        }
        WCGS_Logger::instance()->log( 'info', 'Plugin desativado.', '' );
    }
}

WCGS_Plugin::instance();
