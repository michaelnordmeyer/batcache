<?php
// nananananananananananananananana BATCACHE!!!

if ( ! defined( 'WP_CONTENT_DIR' ) )
	return;

if ( is_readable( dirname( __FILE__ ) . '/batcache-stats.php' ) )
	require_once dirname( __FILE__ ) . '/batcache-stats.php';

if ( ! function_exists( 'batcache_stats' ) ) {
	function batcache_stats( $name, $value, $num = 1, $today = FALSE, $hour = FALSE ) { }
}

// Only cache HEAD and GET requests.
if ( isset( $_SERVER['REQUEST_METHOD'] ) && ! in_array( $_SERVER['REQUEST_METHOD'], array( 'GET', 'HEAD' ) ) ) {
	return;
}

// Never batcache interactive scripts or API endpoints.
if ( in_array(
		basename( $_SERVER['SCRIPT_FILENAME'] ),
		array(
			'wp-app.php',
			'xmlrpc.php',
			'wp-cron.php',
		) ) )
	return;

// Never batcache WP javascript generators
if ( strstr( $_SERVER['SCRIPT_FILENAME'], 'wp-includes/js' ) )
	return;

function batcache_cancel() {
	global $batcache;

	if ( is_object( $batcache ) )
		$batcache->cancel = true;
}

// Variants can be set by functions which use early-set globals like $_SERVER to run simple tests.
// Functions defined in WordPress, plugins, and themes are not available and MUST NOT be used.
// Example: vary_cache_on_function( 'return preg_match( "/feedburner/i", $_SERVER["HTTP_USER_AGENT"] );' );
//          This will cause batcache to cache a variant for requests from Feedburner.
// Tips for writing $function:
//  X_X  DO NOT use any functions from your theme or plugins. Those files have not been included. Fatal error.
//  X_X  DO NOT use any WordPress functions except is_admin() and is_multisite(). Fatal error.
//  X_X  DO NOT include or require files from anywhere without consulting expensive professionals first. Fatal error.
//  X_X  DO NOT use $wpdb, $blog_id, $current_user, etc. These have not been initialized.
//  ^_^  DO understand how create_function works. This is how your code is used: create_function('', $function);
//  ^_^  DO remember to return something. The return value determines the cache variant.
function vary_cache_on_function( $function ) {
	global $batcache;

	if ( preg_match( '/include|require|echo|(?<!s)print|dump|export|open|sock|unlink|`|eval/i', $function ) )
		die( 'Illegal word in variant determiner.' );

	if ( ! preg_match( '/\$_/', $function ) )
		die( 'Variant determiner should refer to at least one $_ variable.' );

	$batcache->add_variant( $function );
}

class batcache {
	// This is the base configuration. You can edit these variables or move them into your wp-config.php file.
	var $max_age =  300; // Expire batcache items aged this many seconds (zero to disable batcache)

	var $remote  =    0; // Zero disables sending buffers to remote datacenters (req/sec is never sent)

	var $times   =    2; // Only batcache a page after it is accessed this many times... (0 to ignore this and use batcache immediately)
	var $seconds =  120; // ...in this many seconds (zero to ignore this and use batcache immediately)

	var $group = 'batcache'; // Name of memcached group. You can simulate a cache flush by changing this.

	var $unique = array(); // If you conditionally serve different content, put the variable values here.

	var $vary = array(); // Array of functions for create_function. The return value is added to $unique above.

	var $headers = array(); // Add headers here as name=>value or name=>array(values). These will be sent with every response from the cache.

	var $status_header;
	var $status_code;

	var $cache_redirects = false; // Set true to enable redirect caching.
	var $redirect_status = false; // This is set to the response code during a redirect.
	var $redirect_location = false; // This is set to the redirect location.

	var $use_stale = true; // Is it ok to return stale cached response when updating the cache?
	var $uncached_headers = array( 'transfer-encoding' ); // These headers will never be cached. Apply strtolower.

	var $debug = true; // Set false to hide the batcache info <!-- comment -->

	var $cache_control = true; // Set false to disable Last-Modified and Cache-Control headers

	var $cancel = false; // Change this to cancel the output buffer. Use batcache_cancel();

	var $skip_cookies = array( 'wp', 'wordpress', 'comment_author' ); // Names of cookies (or prefixes) to skip
	var $noskip_cookies = array( 'wordpress_test_cookie' ); // Names of cookies - if they exist and the cache would normally be bypassed, don't bypass it

	var $query = '';
	var $generation_lock = false;
	var $cache_response = false; // We have to cache the page

	function __construct( $settings ) {
        if ( is_array( $settings ) )
            foreach ( $settings as $k => $v )
                $this->$k = $v;
	}

	function is_ssl() {
		if ( isset( $_SERVER['HTTPS'] ) ) {
			if ( 'on' == strtolower($_SERVER['HTTPS']) )
				return true;
			if ( '1' == $_SERVER['HTTPS'] )
				return true;
		} elseif ( isset( $_SERVER['SERVER_PORT'] ) && ( '443' == $_SERVER['SERVER_PORT'] ) ) {
			return true;
		}
        
		return false;
	}

	function status_header( $status_header, $status_code ) {
		$this->status_header = $status_header;
		$this->status_code = $status_code;

		return $status_header;
	}

	function redirect_status( $status, $location ) {
		if ( $this->cache_redirects ) {
			$this->redirect_status = $status;
			$this->redirect_location = $location;
		}

		return $status;
	}

	function do_headers( $headers1, $headers2 = array() ) {
		// Merge the arrays of headers into one
		$headers = array();
		$keys = array_unique( array_merge( array_keys( $headers1 ), array_keys( $headers2 ) ) );
		foreach ( $keys as $k ) {
			$headers[$k] = array();
			if ( isset( $headers1[$k] ) && isset( $headers2[$k] ) )
				$headers[$k] = array_merge( (array) $headers2[$k], (array) $headers1[$k] );
			elseif ( isset( $headers2[$k] ) )
				$headers[$k] = (array) $headers2[$k];
			else
				$headers[$k] = (array) $headers1[$k];
			$headers[$k] = array_unique( $headers[$k] );
		}
		// These headers take precedence over any previously sent with the same names
		foreach ( $headers as $k => $values ) {
			$clobber = true;
			foreach ( $values as $v ) {
				header( "$k: $v", $clobber );
				$clobber = false;
			}
		}
	}

	function configure_groups() {
		// Configure the memcached client
		if ( ! $this->remote )
			if ( function_exists( 'wp_cache_add_no_remote_groups' ) )
				wp_cache_add_no_remote_groups( array( $this->group ) );
		if ( function_exists( 'wp_cache_add_global_groups' ) )
			wp_cache_add_global_groups( array( $this->group ) );
	}

	// Defined here because timer_stop() calls number_format_i18n()
	function timer_stop( $display = 0, $precision = 3 ) {
		global $timestart, $timeend;
		$mtime = microtime();
		$mtime = explode( ' ', $mtime );
		$mtime = $mtime[1] + $mtime[0];
		$timeend = $mtime;
		$timetotal = $timeend - $timestart;
		$r = number_format( $timetotal, $precision );
		if ( $display )
			echo $r;
		return $r;
	}

	function ob( $output ) {
		// PHP5 and objects disappearing before output buffers?
		wp_cache_init();

		// Remember, $wp_object_cache was clobbered in wp-settings.php so we have to repeat this.
		$this->configure_groups();

		if ( $this->cancel === true ) {
			wp_cache_delete( "{$this->url_key}_generation_lock", $this->group );
			return $output;
		}

		// Do not batcache blank pages unless they are HTTP redirects
		$output = trim( $output );
		if ( $output === '' && ( ! $this->redirect_status || ! $this->redirect_location ) ) {
			wp_cache_delete( "{$this->url_key}_generation_lock", $this->group );
			return;
		}

		// Do not cache 5xx responses
		if ( isset( $this->status_code ) && intval( $this->status_code / 100 ) == 5 ) {
			wp_cache_delete( "{$this->url_key}_generation_lock", $this->group );
			return $output;
		}

		$this->do_variants( $this->vary );
		$this->generate_keys();

		// Construct and save the batcache
		$this->cache = array(
			'output' => $output,
			'time' => isset( $_SERVER['REQUEST_TIME'] ) ? $_SERVER['REQUEST_TIME'] : time(),
			'duration' => $this->timer_stop( false, 3 ),
			'headers' => array(),
			'status_header' => $this->status_header,
			'redirect_status' => $this->redirect_status,
			'redirect_location' => $this->redirect_location,
			'version' => $this->url_version
		);

		foreach ( headers_list() as $header ) {
			list( $k, $v ) = array_map( 'trim', explode( ':', $header, 2 ) );
			$this->cache['headers'][$k][] = $v;
		}

		if ( ! empty( $this->cache['headers'] ) && ! empty( $this->uncached_headers ) ) {
			foreach ( $this->uncached_headers as $header )
				unset( $this->cache['headers'][$header] );
		}

		foreach ( $this->cache['headers'] as $header => $values ) {
			// Do not cache if cookies were set
			if ( strtolower( $header ) === 'set-cookie' ) {
				wp_cache_delete( "{$this->url_key}_generation_lock", $this->group );
				return $output;
			}

			foreach ( (array) $values as $value )
				if ( preg_match( '/^Cache-Control:.*max-?age=(\d+)/i', "$header: $value", $matches ) )
					$this->max_age = intval( $matches[1] );
		}

		$this->cache['max_age'] = $this->max_age;

		wp_cache_set( $this->key, $this->cache, $this->group, $this->max_age + $this->seconds + 30 );

		// Unlock generation
		wp_cache_delete( "{$this->url_key}_generation_lock", $this->group );

		if ( $this->cache_control ) {
			// Don't clobber Last-Modified header if already set, e.g. by WP::send_headers()
			if ( ! isset( $this->cache['headers']['Last-Modified'] ) )
				header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $this->cache['time'] ) . ' GMT', true );
			if ( ! isset( $this->cache['headers']['Cache-Control'] ) )
				header( "Cache-Control: max-age=$this->max_age, must-revalidate", false );
		}

		$this->do_headers( $this->headers );

		// Add some debug info just before <head
		if ( $this->debug ) {
			$this->add_debug_just_cached();
		}

		// Pass output to next ob handler
		batcache_stats( 'batcache', 'total_page_views' );

		return $this->cache['output'];
	}

	function add_variant( $function ) {
		$key = md5( $function );
		$this->vary[$key] = $function;
	}

	function do_variants( $dimensions = false ) {
		// This function is called without arguments early in the page load, then with arguments during the OB handler.
		if ( $dimensions === false )
			$dimensions = wp_cache_get( "{$this->url_key}_vary", $this->group );
		else
			wp_cache_set( "{$this->url_key}_vary", $dimensions, $this->group, $this->max_age + 10 );

		if ( is_array( $dimensions ) ) {
			ksort( $dimensions );
			foreach ( $dimensions as $key => $function ) {
				$fun = create_function( '', $function );
				$value = $fun();
				$this->keys[$key] = $value;
			}
		}
	}

	function generate_keys() {
		// ksort($this->keys); // uncomment this when traffic is slow
		$this->key = md5( serialize( $this->keys ) );
		$this->request_key = $this->key . '_requests';
	}

	function add_debug_just_cached() {
		$duration = $this->cache['duration'];
		$bytes = strlen( serialize( $this->cache ) );
		$html = <<<HTML
<!--
	Generated in $duration seconds.
	$bytes bytes batcached for {$this->max_age} seconds.
-->

HTML;
		$this->add_debug_html_to_output( $html );
	}

	function add_debug_from_cache() {
		$seconds_ago = time() - $this->cache['time'];
		$duration = $this->cache['duration'];
		$serving = $this->timer_stop( false, 3 );
		$expiration = $this->cache['max_age'] - time() + $this->cache['time'];
		$html = <<<HTML
<!--
	Generated $seconds_ago seconds ago in $duration seconds.
	Served from batcache in $serving seconds.
	Expires in $expiration seconds.
-->

HTML;
		$this->add_debug_html_to_output( $html );
	}

	function add_debug_html_to_output( $debug_html ) {
		// Casing on the Content-Type header is inconsistent
		foreach ( array( 'Content-Type', 'Content-type' ) as $key ) {
			if ( isset( $this->cache['headers'][ $key ][0] ) && 0 !== strpos( $this->cache['headers'][ $key ][0], 'text/html' ) )
				return;
		}

		$head_position = strpos( $this->cache['output'], '<head' );
		if ( false === $head_position ) {
			return;
		}
		$this->cache['output'] .= "\n$debug_html";
	}
    
    function get_server_protocol() {
		$protocol = $_SERVER["SERVER_PROTOCOL"];
        
    	if ( ! in_array( $protocol, array( 'HTTP/1.1', 'HTTP/2', 'HTTP/2.0' ) ) ) {
    		$protocol = 'HTTP/1.0';
    	}
        
        return $protocol;
    }
    
    function in_skip_cookies( $cookie ) {
        foreach ( $this->skip_cookies as $skip_cookie ) {
            if ( substr( $cookie, 0, strlen( $skip_cookie ) ) == $skip_cookie ) {
                return true;
            }
        }
    }
}

global $batcache;
// Pass in the global variable which may be an array of settings to override defaults.
$batcache = new batcache( $batcache );

// Disabled
if ( $batcache->max_age == 0 )
	return;

// Never batcache when cookies indicate a cache-exempt visitor.
if ( is_array( $_COOKIE ) && ! empty( $_COOKIE ) ) {
	foreach ( array_keys( $_COOKIE ) as $batcache->cookie ) {
		if ( ! in_array( $batcache->cookie, $batcache->noskip_cookies ) && ! $batcache->in_skip_cookies( $batcache->cookie ) ) {
			batcache_stats( 'batcache', 'cookie_skip' );
			return;
		}
	}
}

if ( ! include_once( WP_CONTENT_DIR . '/object-cache.php' ) )
	return;

wp_cache_init(); // Note: wp-settings.php calls wp_cache_init() which clobbers the object made here.

if ( ! is_object( $wp_object_cache ) )
	return;

// Now that the defaults are set, you might want to use different settings under certain conditions.

/* Example: if your documents have a mobile variant (a different document served by the same URL) you must tell batcache about the variance. Otherwise you might accidentally cache the mobile version and serve it to desktop users, or vice versa.
$batcache->unique['mobile'] = is_mobile_user_agent();
*/

/* Example: never batcache for this host
if ( $_SERVER['HTTP_HOST'] == 'do-not-batcache-me.com' )
	return;
*/

/* Example: batcache everything on this host regardless of traffic level
if ( $_SERVER['HTTP_HOST'] == 'always-batcache-me.com' )
	return;
*/

/* Example: If you sometimes serve variants dynamically (e.g. referrer search term highlighting) you probably don't want to batcache those variants. Remember this code is run very early in wp-settings.php so plugins are not yet loaded. You will get a fatal error if you try to call an undefined function. Either include your plugin now or define a test function in this file.
if ( include_once( 'plugins/searchterm-highlighter.php' ) && referrer_has_search_terms() )
	return;
*/

// Make sure we can increment. If not, turn off the traffic sensor.
if ( ! method_exists( $GLOBALS['wp_object_cache'], 'incr' ) )
	$batcache->times = 0;

// Necessary to prevent clients using cached version after login cookies set. If this is a problem, comment it out and remove all Last-Modified headers.
header( 'Vary: Cookie', false );

// Things that define a unique page.
if ( isset( $_SERVER['QUERY_STRING'] ) )
	parse_str( $_SERVER['QUERY_STRING'], $batcache->query );

$batcache->keys = array(
	'method' => $_SERVER['REQUEST_METHOD'],
	'host' => $_SERVER['HTTP_HOST'],
	'path' => ( $batcache->pos = strpos( $_SERVER['REQUEST_URI'], '?' ) ) ? substr( $_SERVER['REQUEST_URI'], 0, $batcache->pos ) : $_SERVER['REQUEST_URI'],
	'query' => $batcache->query,
	'extra' => $batcache->unique,
    'ssl' => $batcache->is_ssl()
);

// Recreate the permalink from the URL
$batcache->permalink = ( $batcache->keys['ssl'] ? 'https://' : 'http://' ) . $batcache->keys['host'] . $batcache->keys['path'] . ( isset($batcache->keys['query']['p']) ? "?p=" . $batcache->keys['query']['p'] : '' );
$batcache->url_key = md5( $batcache->permalink );
$batcache->configure_groups();
$batcache->url_version = (int) wp_cache_get( "{$batcache->url_key}_version", $batcache->group );
$batcache->do_variants();
$batcache->generate_keys();

// Get the batcache
$batcache->cache = wp_cache_get( $batcache->key, $batcache->group );

// Refresh the cache
if (
    // If page is not cached
    ! is_array( $batcache->cache ) ||
    // or cached page has expired
    time() >= $batcache->cache['time'] + $batcache->max_age - $batcache->seconds ||
    // or a newer version is available
    ( isset( $batcache->cache['version'] ) && $batcache->cache['version'] != $batcache->url_version ) ) {
        
    if ( $batcache->seconds < 1 || $batcache->times < 2 ) {
    	// Settings mean always cache.
    	$batcache->cache_response = true;
    } else {
        // Implicitly adds key only if the key hasn't been cached before
        wp_cache_add( $batcache->request_key, 0, $batcache->group );
    	$batcache->requests = wp_cache_incr( $batcache->request_key, 1, $batcache->group );

    	if ( $batcache->requests >= $batcache->times ) {
            // If access threshold is met or exceeded and cached page has expired
    		wp_cache_delete( $batcache->request_key, $batcache->group );
    		$batcache->cache_response = true;
    	}
    }
}

// Obtain cache generation lock
if ( $batcache->cache_response )
	$batcache->generation_lock = wp_cache_add( "{$batcache->url_key}_generation_lock", 1, $batcache->group, 10) ;

if ( $batcache->generation_lock ) {
    // We have obtained cache generation lock and will generate the cache for this response
    
    // WordPress 4.7 changes how filters are hooked. Since WordPress 4.6 add_filter can be used in advanced-cache.php. Previous behaviour is kept for backwards compatability with WP < 4.6
    if ( function_exists( 'add_filter' ) ) {
    	add_filter( 'status_header', array( &$batcache, 'status_header' ), 10, 2 );
    	add_filter( 'wp_redirect_status', array( &$batcache, 'redirect_status' ), 10, 2 );
    } else {
    	$wp_filter['status_header'][10]['batcache'] = array( 'function' => array( &$batcache, 'status_header' ), 'accepted_args' => 2 );
    	$wp_filter['wp_redirect_status'][10]['batcache'] = array( 'function' => array( &$batcache, 'redirect_status' ), 'accepted_args' => 2 );
    }

    ob_start( array( &$batcache, 'ob' ) );
} else {
    // Cache is being regenerated in another request and we can't use stale cache
    if ( $batcache->cache_response && ! $batcache->use_stale ) {
        // Response will be generated but not cached
        return;
    }
    
	// Issue redirect if enabled and cached
	if ( $batcache->cache_redirects && $batcache->cache['redirect_status'] && $batcache->cache['redirect_location'] ) {
		$status = $batcache->cache['redirect_status'];
		$location = $batcache->cache['redirect_location'];
		// From vars.php
		$is_IIS = ( strpos( $_SERVER['SERVER_SOFTWARE'], 'Microsoft-IIS' ) !== false || strpos( $_SERVER['SERVER_SOFTWARE'], 'ExpressionDevServer') !== false );

		$batcache->do_headers( $batcache->headers );
		if ( $is_IIS ) {
			header( "Refresh: 0;url=$location" );
		} else {
			if ( php_sapi_name() != 'cgi-fcgi' ) {
				$texts = array(
					300 => 'Multiple Choices',
					301 => 'Moved Permanently',
					302 => 'Found',
					303 => 'See Other',
					304 => 'Not Modified',
					305 => 'Use Proxy',
					306 => 'Reserved',
					307 => 'Temporary Redirect',
				);
				if ( isset( $texts[$status] ) )
					header( $batcache->get_server_protocol() . " $status " . $texts[$status] );
				else
					header( $batcache->get_server_protocol() . " 302 Found" );
			}
			header( "Location: $location" );
		}
		die;
	}

	// Respect ETags served with feeds.
	$three04 = false;
	if ( isset( $SERVER['HTTP_IF_NONE_MATCH'] ) && isset( $batcache->cache['headers']['ETag'][0] ) && $_SERVER['HTTP_IF_NONE_MATCH'] == $batcache->cache['headers']['ETag'][0] )
		$three04 = true;

	// Respect If-Modified-Since.
	elseif ( $batcache->cache_control && isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
		$client_time = strtotime( $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
		if ( isset( $batcache->cache['headers']['Last-Modified'][0] ) )
			$cache_time = strtotime( $batcache->cache['headers']['Last-Modified'][0] );
		else
			$cache_time = $batcache->cache['time'];

		if ( $client_time >= $cache_time )
			$three04 = true;
	}

	// Use the batcache save time for Last-Modified so we can issue "304 Not Modified" but don't clobber a cached Last-Modified header.
	if ( $batcache->cache_control && !isset( $batcache->cache['headers']['Last-Modified'][0] ) ) {
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $batcache->cache['time'] ) . ' GMT', true );
		header( 'Cache-Control: max-age=' . ( $batcache->cache['max_age'] - time() + $batcache->cache['time'] ) . ', must-revalidate', true );
	}

	// Add some debug info just before </head>
	if ( $batcache->debug ) {
		$batcache->add_debug_from_cache();
	}

	$batcache->do_headers( $batcache->headers, $batcache->cache['headers'] );

	if ( $three04 ) {
		header( $batcache->get_server_protocol() . " 304 Not Modified", true, 304 );
		die;
	}

	if ( ! empty( $batcache->cache['status_header'] ) )
		header( $batcache->cache['status_header'], true );

	batcache_stats( 'batcache', 'total_cached_views' );

	// Have you ever heard a death rattle before?
	die( $batcache->cache['output'] );
}
