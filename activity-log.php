<?php

/**
 * Plugin Name: Activity Log Rest API
 *
 * Description: Rest API to retrieve activity logs from the database
 *
 * Version: 1.0.0
 *
 * Author: BionicWP
 *
 * Author URI: https://www.bionicwp.com/
 */

add_action( 'rest_api_init', 'initActivityLog' );
add_action('admin_init', 'wpdocs_remove_activity_log');

function initActivityLog(): void {
	register_rest_route( '/activity-logs', '/logs', array( 'method' => 'GET', 'callback' => 'getActivityLog' ) );
	register_rest_route( '/activity-logs', '/logs/list-filters', array(
		'method'   => 'GET',
		'callback' => 'getFilters'
	) );
}

function wpdocs_remove_activity_log() {
	remove_menu_page('activity_log_page');
}

function getActivityLog( WP_REST_Request $request ) {
	global $wpdb;
//require_once( ABSPATH . 'wp-config.php' );
	if ( ! isAuthorisedUser( $request ) ) {
		return new WP_Error( 'Forbidden!', __( 'Plugin Authentication Failed!' ), array( 'status' => 403 ) );
	}

	//validations
	$page    = (int) $request->get_param( 'page' );
	$perPage = (int) $request->get_param( 'per_page' );
	if ( ! $perPage || ! ( $page || $page === 0 ) ) {
		return new WP_Error( 'Validation failed!', __( 'Validation failed for required field: [`page`, `per_page`]' ), array( 'status' => 400 ) );
	}

	$sqlQuery = "
							SELECT wp_aryo_activity_log.hist_time as created_at,
							wp_users.user_nicename as name,
							wp_users.user_email as user,
							wp_aryo_activity_log.hist_ip as ip,
							wp_aryo_activity_log.object_type as topic,
							wp_aryo_activity_log.object_subtype as context,
							wp_aryo_activity_log.object_name as meta,
							wp_aryo_activity_log.action, wp_aryo_activity_log.user_caps as role
							FROM wp_aryo_activity_log left join wp_users on wp_users.ID = wp_aryo_activity_log.user_id WHERE 1
						";

	$countSqlQuery    = "SELECT COUNT(wp_aryo_activity_log.histid) AS total 
							FROM wp_aryo_activity_log left join wp_users on wp_users.id = wp_aryo_activity_log.user_id
							WHERE 1";
	$filteredSqlQuery = '';


	$searchQuery = $request->get_param( 'search' );
	if ( $searchQuery ) {
		$filteredSqlQuery = $filteredSqlQuery . " AND (wp_aryo_activity_log.object_subtype LIKE '%$searchQuery%' OR wp_aryo_activity_log.object_name LIKE '%$searchQuery%')";
	}

	$user = (int) $request->get_param( 'user' );
	if ( $user ) {
		$filteredSqlQuery = $filteredSqlQuery . " AND wp_aryo_activity_log.user_id = $user";
	}

	$role = $request->get_param( 'role' );
	if ( $role ) {
		$filteredSqlQuery = $filteredSqlQuery . " AND wp_aryo_activity_log.user_caps LIKE '%$role%'";
	}

	$topic = $request->get_param( 'topic' );
	if ( $topic ) {
		$filteredSqlQuery = $filteredSqlQuery . " AND wp_aryo_activity_log.object_type LIKE '%$topic%'";
	}

	$action = $request->get_param( 'action' );
	if ( $action ) {
		$filteredSqlQuery = $filteredSqlQuery . " AND wp_aryo_activity_log.action LIKE '%$action%'";
	}

	$connection = mysqli_connect( DB_HOST, DB_USER, DB_PASSWORD );
	$logs       = $wpdb->get_results( $sqlQuery . $filteredSqlQuery . " ORDER BY wp_aryo_activity_log.histid DESC LIMIT $perPage OFFSET " . intval( $page * $perPage ) );
	$count      = $wpdb->get_results( "$countSqlQuery.$filteredSqlQuery" );
	mysqli_select_db( $connection, DB_NAME );

	$data = array();

	$data['status']  = 200;
	$data['message'] = 'Activity Logs!';
	$data['data']    = array( 'total' => $count[0] ? $count[0]->total : '0', 'results' => $logs );
	nocache_headers();
	return new WP_REST_Response($data , 200);

//	return $data;
}

function isAuthorisedUser( WP_REST_Request $request ): bool {
	$authToken = $request->get_header( 'Authorization' );
	$authToken = str_replace( 'Bearer ', '', $authToken ?: '' );
	global $wpdb;
//	require_once( ABSPATH . 'wp-config.php' );
	$connection = mysqli_connect( DB_HOST, DB_USER, DB_PASSWORD );
	mysqli_select_db( $connection, DB_NAME );
	$storedAuthKey = $wpdb->get_results( "SELECT * FROM wp_options WHERE option_name = 'bwp_activity_logs_plugin_key' LIMIT 1" );
	if ( count( $storedAuthKey ) > 0 ) {
		// check if TOKEN from req and DB are same
		if ( $storedAuthKey[0]->option_value == $authToken ) {
			return true;
		} else {
			return false;
		}
	} else {
		// if TOKEN from req and AUTH TOKEN from DB are not same, hit ENGINE API for validation
		$curl   = curl_init();
		$origin = $request->get_header( 'X-Origin' );
		curl_setopt_array( $curl, array(
			CURLOPT_URL            => "https://bionic-prod-engine.bionicwp.com/activity-logs/validate-token",
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => "",
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => "POST",
			CURLOPT_POSTFIELDS     => "{\"token\":\"$authToken\", \"origin\":\"$origin\"}",
			CURLOPT_HTTPHEADER     => array(
				"Content-Type: application/json",
				"cache-control: no-cache",
			),
		) );
		$response = curl_exec( $curl );
		$err      = curl_error( $curl );
		$code     = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		curl_close( $curl );
		if ( $err || $code >= 400 ) {
			return false;
		} else {
			$response = json_decode( $response );
			if ( $response->statusCode === 200 ) {
				$wpdb->insert( 'wp_options', array(
					'option_name'  => 'bwp_activity_logs_plugin_key',
					'option_value' => $authToken
				) );

				return true;
			} else {
				return false;
			}
		}
	}
}


function getFilters( WP_REST_Request $request ) {
	global $wpdb;
//	require_once( ABSPATH . 'wp-config.php' );
	if ( ! isAuthorisedUser( $request ) ) {
		return new WP_Error( 'Forbidden!', __( 'Plugin Authentication Failed!' ), array( 'status' => 403 ) );
	}

	if ( $wpdb->get_results( "SELECT ! FROM wp_aryo_activity_log" ) === false ) {
		return new WP_Error( 'Plugin not found!', __( 'Required plugin `Activity Log` not found/activated!' ), array( 'status' => 404 ) );
	}

	$data['status']  = 200;
	$data['message'] = 'Activity Log Filters!';
	$data['data']    = array(
		'users'   => getUsers(),
		'roles'   => getRoles(),
		'topics'  => getTopics(),
		'actions' => getActions()
	);
	nocache_headers();
	return $data;
}


function getRoles() {
	return array(
		array( 'name' => 'All Roles', 'slug' => '' ),
		array( 'name' => 'Administrator', 'slug' => 'administrator' ),
		array( 'name' => 'Editor', 'slug' => 'editor' ),
		array( 'name' => 'Author', 'slug' => 'author' ),
		array( 'name' => 'Contributor', 'slug' => 'contributor' ),
		array( 'name' => 'Subscriber', 'slug' => 'subscriber' ),
		array( 'name' => 'Guest', 'slug' => 'guest' ),
	);
}

function getUsers() {

	global $wpdb;

	//require_once( ABSPATH . 'wp-config.php' );

	return $wpdb->get_results( "SELECT ID as id, user_nicename as name, user_email as email from wp_users;" );
}

function getTopics() {

	global $wpdb;
	//require_once( ABSPATH . 'wp-config.php' );

	$topics   = array( array( 'name' => 'All Topics', 'slug' => '' ) );
	$topicsDB = $wpdb->get_results( "SELECT object_type from wp_aryo_activity_log group by object_type;" );
	if ( $topicsDB && sizeof( $topicsDB ) ) {
		foreach ( $topicsDB as $topic ) {
			$topics[] = array( 'name' => $topic->object_type, 'slug' => $topic->object_type );
		}
	}

	return $topics;
}

function getActions() {

	global $wpdb;
	//require_once( ABSPATH . 'wp-config.php' );

	$actions   = array( array( 'name' => 'All Actions', 'slug' => '' ) );
	$actionsDB = $wpdb->get_results( "SELECT action from wp_aryo_activity_log group by action;" );
	if ( $actionsDB && sizeof( $actionsDB ) ) {
		foreach ( $actionsDB as $action ) {
			$actions[] = array( 'name' => ucfirst( $action->action ), 'slug' => $action->action );
		}
	}

	return $actions;
}