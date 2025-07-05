<?php
/**
 * Plugin Name: MiniGolf Room Manager
 * Description: Ermöglicht das Anlegen virtueller Minigolf-Räume, Spieler und Scores.
 * Version: 0.1.1
 * Author: Codex Agent
 * License: GPLv2 or later
 * Text Domain: minigolf-room-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class MGRoomManager {

    private static $instance = null;
    private $db_version = '0.1';
    private $table_rooms;
    private $table_players;
    private $table_scores;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_rooms   = $wpdb->prefix . 'mg_rooms';
        $this->table_players = $wpdb->prefix . 'mg_players';
        $this->table_scores  = $wpdb->prefix . 'mg_scores';

        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_shortcode( 'minigolf_room', array( $this, 'scoreboard_shortcode' ) );
    }

    public function activate() {
        $this->create_tables();
        add_option( 'mg_room_manager_db_version', $this->db_version );
    }

    private function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $sql_rooms = "CREATE TABLE {$this->table_rooms} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            code varchar(10) NOT NULL,
            num_holes tinyint(2) NOT NULL,
            created datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code)
        ) $charset_collate;";

        $sql_players = "CREATE TABLE {$this->table_players} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            room_id mediumint(9) NOT NULL,
            name varchar(100) NOT NULL,
            email varchar(100) DEFAULT '' NOT NULL,
            PRIMARY KEY  (id),
            KEY room_id (room_id)
        ) $charset_collate;";

        $sql_scores = "CREATE TABLE {$this->table_scores} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            player_id mediumint(9) NOT NULL,
            hole_no tinyint(2) NOT NULL,
            strokes tinyint(2) NOT NULL,
            PRIMARY KEY  (id),
            KEY player_id (player_id)
        ) $charset_collate;";

        dbDelta( $sql_rooms );
        dbDelta( $sql_players );
        dbDelta( $sql_scores );
    }

    public function register_routes() {
        register_rest_route( 'minigolf/v1', '/rooms', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'create_room' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'minigolf/v1', '/rooms/(?P<code>[A-Za-z0-9]+)/scores', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'update_scores' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'minigolf/v1', '/rooms/(?P<code>[A-Za-z0-9]+)', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'get_room' ),
            'permission_callback' => '__return_true',
        ) );
    }

    private function generate_code( $length = 6 ) {
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code  = '';
        for ( $i = 0; $i < $length; $i++ ) {
            $code .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
        }
        return $code;
    }

    public function create_room( WP_REST_Request $request ) {
        global $wpdb;
        $num_holes = intval( $request->get_param( 'num_holes' ) );
        if ( $num_holes < 1 || $num_holes > 15 ) {
            $num_holes = 9;
        }
        $players = $request->get_param( 'players' );
        if ( ! is_array( $players ) ) {
            return new WP_Error( 'invalid_players', 'Spielerliste fehlt', array( 'status' => 400 ) );
        }

        $code = $this->generate_code();
        $wpdb->insert( $this->table_rooms, array(
            'code'      => $code,
            'num_holes' => $num_holes,
        ) );
        $room_id = $wpdb->insert_id;

        foreach ( $players as $name ) {
            $wpdb->insert( $this->table_players, array(
                'room_id' => $room_id,
                'name'    => sanitize_text_field( $name ),
            ) );
        }

        return array( 'code' => $code );
    }

    public function get_room( WP_REST_Request $request ) {
        global $wpdb;
        $code = sanitize_text_field( $request->get_param( 'code' ) );
        $room = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_rooms} WHERE code = %s", $code ) );
        if ( ! $room ) {
            return new WP_Error( 'not_found', 'Raum nicht gefunden', array( 'status' => 404 ) );
        }
        $players = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table_players} WHERE room_id = %d", $room->id ) );
        $scores  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table_scores} WHERE player_id IN (SELECT id FROM {$this->table_players} WHERE room_id = %d)", $room->id ) );
        return array(
            'room'    => $room,
            'players' => $players,
            'scores'  => $scores,
        );
    }

    public function update_scores( WP_REST_Request $request ) {
        global $wpdb;
        $code   = sanitize_text_field( $request->get_param( 'code' ) );
        $scores = $request->get_param( 'scores' );
        if ( ! is_array( $scores ) ) {
            return new WP_Error( 'invalid_scores', 'Scores fehlen', array( 'status' => 400 ) );
        }
        $room = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_rooms} WHERE code = %s", $code ) );
        if ( ! $room ) {
            return new WP_Error( 'not_found', 'Raum nicht gefunden', array( 'status' => 404 ) );
        }
        foreach ( $scores as $player_id => $holes ) {
            foreach ( $holes as $hole_no => $strokes ) {
                $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table_scores} WHERE player_id = %d AND hole_no = %d", $player_id, $hole_no ) );
                if ( $existing ) {
                    $wpdb->update( $this->table_scores, array( 'strokes' => intval( $strokes ) ), array( 'id' => $existing ) );
                } else {
                    $wpdb->insert( $this->table_scores, array(
                        'player_id' => $player_id,
                        'hole_no'   => intval( $hole_no ),
                        'strokes'   => intval( $strokes ),
                    ) );
                }
            }
        }
        return array( 'success' => true );
    }

    public function enqueue_assets() {
        wp_register_style(
            'mg-room-manager',
            plugin_dir_url( __FILE__ ) . 'assets/css/minigolf-room-manager.css',
            array(),
            '0.1.1'
        );
    }

    public function scoreboard_shortcode( $atts ) {
        if ( empty( $atts['code'] ) ) {
            return '<p>Kein Raumcode angegeben.</p>';
        }
        $code = sanitize_text_field( $atts['code'] );
        $response = rest_do_request( new WP_REST_Request( 'GET', '/minigolf/v1/rooms/' . $code ) );
        if ( $response->is_error() ) {
            return '<p>Raum nicht gefunden.</p>';
        }
        $data = $response->get_data();
        $room = $data['room'];
        $players = $data['players'];
        $scores = $data['scores'];
        $score_map = array();
        foreach ( $scores as $score ) {
            $score_map[ $score->player_id ][ $score->hole_no ] = $score->strokes;
        }
        wp_enqueue_style( 'mg-room-manager' );
        ob_start();
        ?>
        <div class="mg-scoreboard-wrapper">
        <table class="mg-scoreboard">
            <thead>
                <tr>
                    <th>Spieler</th>
        <?php for ( $i = 1; $i <= $room->num_holes; $i++ ) : ?>
                    <th><?php echo esc_html( $i ); ?></th>
        <?php endfor; ?>
                    <th>Summe</th>
                </tr>
            </thead>
            <tbody>
        <?php foreach ( $players as $player ) :
            $sum = 0; ?>
                <tr>
                    <td><?php echo esc_html( $player->name ); ?></td>
            <?php for ( $i = 1; $i <= $room->num_holes; $i++ ) :
                $val = isset( $score_map[ $player->id ][ $i ] ) ? intval( $score_map[ $player->id ][ $i ] ) : '';
                $sum += intval( $val ); ?>
                    <td><?php echo esc_html( $val ); ?></td>
            <?php endfor; ?>
                    <td><?php echo esc_html( $sum ); ?></td>
                </tr>
        <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
        return ob_get_clean();
    }
}

MGRoomManager::instance();
