<?php
/*
Plugin Name: BLMD Clicktrack
Plugin URI: http://github.com/blmd/blmd-clicktrack
Description: Click tracking plugin
Author: blmd
Author URI: http://github.com/blmd
Version: 0.1

Depends: IP Delivery
*/

!defined( 'ABSPATH' ) && die;
define( 'BLMD_CLICKTRACK_VERSION', '0.1' );
define( 'BLMD_CLICKTRACK_URL', plugin_dir_url( __FILE__ ) );
define( 'BLMD_CLICKTRACK_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLMD_CLICKTRACK_BASENAME', plugin_basename( __FILE__ ) );

/*
	Options created
	--
	blmd_clicktrack_slug
	blmd_clicktrack_base
	blmd_clicktrack_x_robots

	Options used
	--
	blmd_api_url
*/

class BLMD_CLicktrack {

	const DEFAULT_SLUG       = 'click';
	const OREFERER_COOKIE_NAME = 'r';

	public static function factory() {
		static $instance = null;
		if ( ! ( $instance instanceof self ) ) {
			$instance = new self;
			$instance->setup_actions();
		}
		return $instance;
	}

	protected function setup_actions() {
		register_activation_hook( __FILE__, array( $this, 'activation_hook' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivation_hook' ) );
		add_action( 'init', array( $this, 'init' ) );
		add_filter( 'robots_txt', array( $this, 'robots_txt' ), 10, 2 );
		add_filter( 'template_redirect', array( $this, 'template_redirect' ) );
		add_filter( 'update_option_blmd_clicktrack_slug', array( $this, 'update_option_blmd_clicktrack_slug' ), 10, 2 );
	}

	public function set_oreferer_cookie() {
		if ( !class_exists( 'IP_Delivery' ) ) { return; }
		if ( !empty( IP_Delivery()->warning ) ) { return; }
		if ( empty( $_SERVER['HTTP_REFERER'] ) ) { return; }
		if ( IP_Delivery()->type == 'CRAWLER' ) { return; }
		if ( IP_Delivery()->agenttype == 'webpreview' ) { return; }
		if ( IP_Delivery()->iscrawleragent==1 || IP_Delivery()->isbrowseragent==0 ) { return; }
		if ( !empty( $_COOKIE[self::OREFERER_COOKIE_NAME] ) ) { return; }

		$page_ref_host = @parse_url( stripslashes( $_SERVER['HTTP_REFERER'] ), PHP_URL_HOST );
		$site_host     = @parse_url( home_url(), PHP_URL_HOST );
		if ( strtolower( $page_ref_host ) == strtolower( $site_host ) ) { return; }

		$secure = ( 'https' === parse_url( home_url(), PHP_URL_SCHEME ) );
		if ( ! headers_sent() ) {
			$rand_key = substr(str_shuffle(str_repeat("123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ", 4)), 0, 4);
			$val = $rand_key . self::xor_encode( stripslashes( $_SERVER['HTTP_REFERER'] ), $rand_key );
			setcookie( self::OREFERER_COOKIE_NAME, $val, 0, COOKIEPATH, COOKIE_DOMAIN, $secure );
		}
	}

	public function add_rewrite_rules() {
		$slug = get_option( 'blmd_clicktrack_slug' );
		add_rewrite_tag( '%click_id%', '([^/]+)' );
		add_rewrite_rule( '('.$slug.')/([^/]+)', 'index.php?click_id=$matches[2]', 'top' );
	}

	public function activation_hook() {
		if ( get_option( 'blmd_clicktrack_slug' ) === false ) {
			update_option( 'blmd_clicktrack_slug', self::DEFAULT_SLUG );
		}
		if ( get_option( 'blmd_clicktrack_x_robots' ) === false ) {
			update_option( 'blmd_clicktrack_x_robots', 1 );
		}
		$this->add_rewrite_rules();
		flush_rewrite_rules();
	}

	public function deactivation_hook() {
		flush_rewrite_rules();
	}

	public function init() {
		$this->set_oreferer_cookie();
		$this->add_rewrite_rules();
	}

	public function robots_txt( $out, $pub ) {
		$click_slug     = get_option( 'blmd_clicktrack_slug' );
		$click_base     = get_option( 'blmd_clicktrack_base' );
		if ( $click_slug && !$click_base ) {
			$slug_base   = trailingslashit( str_replace( home_url(), '', site_url( "/$click_slug/" ) ) );
			$slug_base2  = trailingslashit( "/$click_slug/" );
			$out .= "Disallow: $slug_base\n";
			if ( $slug_base != $slug_base2 ) {
				$out .= "Disallow: $slug_base2\n";
			}
			return $out;
		}
		return $out;
	}

	public function template_redirect() {
		global $wp_query;
		$click_id = get_query_var( 'click_id' );
		if ( empty( $click_id ) ) return;

		$wp_query->is_404 = false;
		if ( !class_exists( 'IP_Delivery' ) ) {
			wp_die( "503 Service Unavailable", "503 Service Unavailable", array( 'response'=>503, 'back_link'=>true ) );
		}
		
		$deny = false;
		// $ipd2 =  ipd_get();
		if ( IP_Delivery()->type != 'ADMIN' ) {
			if ( !empty( IP_Delivery()->warning ) ) { $deny = true; }
			if ( empty( $_SERVER['HTTP_REFERER'] ) ) { $deny = true; }
			if ( IP_Delivery()->type == 'CRAWLER' ) { $deny = true; }
			if ( IP_Delivery()->agenttype == 'webpreview' ) { $deny = true; }
			if ( IP_Delivery()->iscrawleragent==1 || IP_Delivery()->isbrowseragent==0 ) { $deny = true; }
			// $page_ref_host = @parse_url($this->http_referer, PHP_URL_HOST);
			// if (strtolower($page_ref_host) != $this->site->hostname) { $deny = true; }
		}
		if ( $deny ) {
			wp_die( "403 Forbidden", "403 Forbidden", array( 'response'=>403, 'back_link'=>true ) );
		}

		// is local click, or remote click?
		$site_host     = @parse_url( home_url(), PHP_URL_HOST );
		$page_ref_host = !empty( $_SERVER['HTTP_REFERER'] ) ? parse_url( stripslashes( $_SERVER['HTTP_REFERER'] ), PHP_URL_HOST ) : '';
		$oreferer = !empty( $_COOKIE[self::OREFERER_COOKIE_NAME] ) ? stripslashes( $_COOKIE[self::OREFERER_COOKIE_NAME] ) : '';
		if ( !empty( $oreferer ) ) {
			$xkey     = substr( $oreferer, 0, 4 );
			$str      = substr( $oreferer, 4 );
			$oreferer = self::xor_decode( $str, $xkey );
			if ( stripos( $oreferer, 'http' ) !== 0 ) { $oreferer = ''; }
		}
		$params = array();
		$params['X_FORWARDED_IS_REMOTE']       = (int)( strtolower( $page_ref_host ) != strtolower( $site_host ) );
		$params['X_FORWARDED_REMOTE_HOST']     = $page_ref_host;
		$params['X_FORWARDED_HTTP_HOST']       = $site_host ?: '';
		$params['X_FORWARDED_REMOTE_ADDR']     = isset( $_SERVER['REMOTE_ADDR'] ) ? stripslashes( $_SERVER['REMOTE_ADDR'] ) : '';
		$params['X_FORWARDED_REQUEST_URI']     = isset( $_SERVER['REQUEST_URI'] ) ? stripslashes( $_SERVER['REQUEST_URI'] ) : '';
		$params['X_FORWARDED_HTTP_REFERER']    = isset( $_SERVER['HTTP_REFERER'] ) ? stripslashes( $_SERVER['HTTP_REFERER'] ) : '';
		$params['X_FORWARDED_HTTP_OREFERER']   = $oreferer;
		$params['X_FORWARDED_HTTP_USER_AGENT'] = isset( $_SERVER['HTTP_USER_AGENT'] ) ? stripslashes( $_SERVER['HTTP_USER_AGENT'] ) : '';
		$post_str = http_build_query( $params, '', '&' );

		$ch = curl_init();
		$url = get_option( 'blmd_api_url' )."/v1/click/{$click_id}";
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 5 );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 5 );
		curl_setopt( $ch, CURLOPT_ENCODING, '' );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER,   1 );
		curl_setopt( $ch, CURLOPT_USERAGENT, 'PHP' );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $ch, CURLOPT_HEADER, false );
		if ( !empty( $post_str ) ) {
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $post_str );
		}
		$loc = curl_exec( $ch );
		curl_close( $ch );
		if ( stripos( $loc, 'http' ) !== 0 ) {
			// bad api return
			wp_die( "403 Forbidden", "403 Forbidden", array( 'response'=>403, 'back_link'=>true ) );
		}

		status_header( 200 );
		if ( get_option( 'blmd_clicktrack_x_robots' ) ) {
			header( "X-Robots-Tag: noindex,nofollow,noarchive" );
		}
		if ( isset( $_COOKIE[self::OREFERER_COOKIE_NAME] ) && !headers_sent() ) {
			$secure = ( 'https' === parse_url( home_url(), PHP_URL_SCHEME ) );
			setcookie( self::OREFERER_COOKIE_NAME, '', time() - 86400, COOKIEPATH, COOKIE_DOMAIN, $secure );
		}
		// // no referrer
		// $loc_base = str_replace( '"', '\\"', preg_replace( '%^https?://%i', '', $loc ) );
// 		$_html = <<<EOT
// 		<!doctype html><html><head></head><body><script>
// 		var ie = /*@cc_on!@*/0;
// 		var url = "$loc_base";
// 		if (ie) { window.open("http"+"://"+url, "_self", false, true); }
// 		else { window.location.replace(\'data:text/html,<html><head><meta http-equiv="refresh" content="0; url=\'+ \'http\'+\'://\'+url  \'"></head><body></body></html>\'); }
// 		</script></body></html>
// EOT;
		// echo $_html;
		$oc = !empty( $oreferer) ? $oreferer : 'nocookie';
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			wp_die( "wp_redirect(): $loc<br>\noreferer: $oc", "", array( 'response'=>404, 'back_link'=>true ) );
		}
		wp_die( "wp_redirect(): $loc<br>\noreferer: $oc", "", array( 'response'=>404, 'back_link'=>true ) );
		// wp_redirect( $loc, 302 );
		exit;
	}

	function update_option_blmd_clicktrack_slug( $old, $new ) {
		if ( $old !== $new ) {
			flush_rewrite_rules();
		}
	}

	public static function xor_encode( $enc, $key ) {
		$xkey = strtolower( sha1( $key ) );
		$xkey = str_repeat( $xkey, ( ceil( strlen( $enc ) / strlen( $xkey ) )*1 ) );
		for ( $i=0, $j=strlen( $enc ), $k=strlen( $xkey ); $i<$j; $i++ ) {
			$enc[$i] = chr( ord( $enc[$i] ) ^ ord( $xkey[( $i % $k )] ) );
		}
		return rtrim( strtr( base64_encode( $enc ), '+/', '-_' ), '=' );
	}

	public static function xor_decode( $enc, $key ) {
		$enc = base64_decode( str_pad( strtr( $enc, '-_', '+/' ), strlen( $enc ) % 4, '=', STR_PAD_RIGHT ) );
		$xkey = strtolower( sha1( $key ) );
		$xkey = str_repeat( $xkey, ceil( strlen( $enc ) / strlen( $xkey ) )*2 );
		for ( $i=0, $j=strlen( $enc ), $k=strlen( $xkey ); $i<$j; $i++ ) {
			$enc[$i] = chr( ord( $enc[$i] ) ^ ord( $xkey[( $i % $k )] ) );
		}
		return $enc;
	}

	public function __construct() { }

	public function __clone() {
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'core-plugin' ), '0.1' );
	}

	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'core-plugin' ), '0.1' );
	}

};

function BLMD_CLicktrack() {
	return BLMD_CLicktrack::factory();
}

BLMD_CLicktrack();
