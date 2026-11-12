<?php
defined( 'ABSPATH' ) || exit;

class WCGS_Project {

    public $id             = '';
    public $name           = '';
    public $repo_path      = '';
    public $branch         = 'main';
    public $remote         = 'origin';
    public $github_token    = '';
    public $github_repo_url = '';
    public $webhook_secret  = '';
    public $auto_pull      = true;
    public $auto_push      = false;
    public $sync_interval  = 'disabled';
    public $notify_email   = '';
    public $created_at     = '';

    public function __construct() {
        $this->id         = self::generate_id();
        $this->created_at = current_time( 'mysql' );
    }

    public function save() {
        $all          = self::all_raw();
        $all[ $this->id ] = $this->to_array();
        update_option( WCGS_PROJECTS_KEY, $all );
    }

    public function delete() {
        $all = self::all_raw();
        unset( $all[ $this->id ] );
        update_option( WCGS_PROJECTS_KEY, $all );
    }

    /** @return WCGS_Project[] */
    public static function all() {
        $result = array();
        foreach ( self::all_raw() as $data ) {
            $result[] = self::from_array( $data );
        }
        return $result;
    }

    public static function find( $id ) {
        $all = self::all_raw();
        if ( isset( $all[ $id ] ) ) {
            return self::from_array( $all[ $id ] );
        }
        return null;
    }

    public function to_array() {
        return array(
            'id'             => $this->id,
            'name'           => $this->name,
            'repo_path'      => $this->repo_path,
            'branch'         => $this->branch,
            'remote'         => $this->remote,
            'github_token'    => $this->github_token,
            'github_repo_url' => $this->github_repo_url,
            'webhook_secret'  => $this->webhook_secret,
            'auto_pull'      => $this->auto_pull,
            'auto_push'      => $this->auto_push,
            'sync_interval'  => $this->sync_interval,
            'notify_email'   => $this->notify_email,
            'created_at'     => $this->created_at,
            'webhook_url'    => rest_url( 'wcgs/v1/webhook/' . $this->id ),
        );
    }

    public static function from_array( $d ) {
        $p                 = new self();
        $p->id             = isset( $d['id'] )             ? $d['id']             : $p->id;
        $p->name           = isset( $d['name'] )           ? $d['name']           : '';
        $p->repo_path      = isset( $d['repo_path'] )      ? $d['repo_path']      : '';
        $p->branch         = isset( $d['branch'] )         ? $d['branch']         : 'main';
        $p->remote         = isset( $d['remote'] )         ? $d['remote']         : 'origin';
        $p->github_token    = isset( $d['github_token'] )    ? $d['github_token']    : '';
        $p->github_repo_url = isset( $d['github_repo_url'] ) ? $d['github_repo_url'] : '';
        $p->webhook_secret  = isset( $d['webhook_secret'] )  ? $d['webhook_secret']  : '';
        $p->auto_pull      = isset( $d['auto_pull'] )      ? (bool) $d['auto_pull']  : true;
        $p->auto_push      = isset( $d['auto_push'] )      ? (bool) $d['auto_push']  : false;
        $p->sync_interval  = isset( $d['sync_interval'] )  ? $d['sync_interval']  : 'disabled';
        $p->notify_email   = isset( $d['notify_email'] )   ? $d['notify_email']   : '';
        $p->created_at     = isset( $d['created_at'] )     ? $d['created_at']     : '';
        return $p;
    }

    private static function all_raw() {
        return (array) get_option( WCGS_PROJECTS_KEY, array() );
    }

    private static function generate_id() {
        return 'proj_' . substr( md5( uniqid( '', true ) ), 0, 10 );
    }
}
