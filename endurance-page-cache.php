<?php
/*
Plugin Name: Endurance Page Cache
Description: Static file caching.
Version: 0.1
Author: Mike Hansen
Author URI: https://www.mikehansen.me/
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/
if ( ! class_exists( 'Endurance_Page_Cache' ) ) {
	class Endurance_Page_Cache {
		function __construct() {
			$this->hooks();
			$this->cache_dir = WP_CONTENT_DIR . '/endurance-page-cache';
			$this->cache_exempt = apply_filters( 'epc_exempt_uri_contains', array( 'wp-admin', '.php', 'checkout', 'cart' ) );
			if ( ! wp_next_scheduled( 'epc_purge' ) ) {
				wp_schedule_event( strtotime( 'today midnight' ), 'daily', 'epc_purge' );
			}
		}

		function hooks() {
			add_action( 'init', array( $this, 'start' ) );
			add_action( 'shutdown', array( $this, 'finish' ) );

			add_filter( 'style_loader_src', array( $this, 'remove_wp_ver_css_js' ), 9999 );
			add_filter( 'script_loader_src', array( $this, 'remove_wp_ver_css_js' ), 9999 );

			add_filter( 'mod_rewrite_rules', array( $this, 'htaccess_contents' ) );

			add_action( 'save_post', array( $this, 'save_post' ) );
			add_action( 'edit_terms', array( $this, 'edit_terms' ), 10, 2 );

			add_action( 'comment_post', 'comment', 10, 2 );

			add_action( 'activated_plugin', array( $this, 'purge_all' ) );
			add_action( 'deactivated_plugin', array( $this, 'purge_all' ) );
			add_action( 'switch_theme', array( $this, 'purge_all' ) );

			add_action( 'update_option_mm_coming_soon', array( $this, 'purge_all' ) );

			add_action( 'epc_purge', array( $this, 'purge_all' ) );
		}

		function comment( $comment_id, $comment_approved ) {
			$comment = get_comment( $comment_id );
			if ( property_exists( $comment, 'comment_post_ID' ) ) {
				$post_url = get_permalink( $comment->comment_post_ID );
				$this->purge_single( $post_url );
			}
		}

		function save_post( $post_id ) {
			$url = get_permalink( $post_id );
			$this->purge_single( $url );

			$taxonomies = get_post_taxonomies( $post_id );
			foreach ( $taxonomies as $taxonomy ) {
				$terms = get_the_terms( $post_id, $taxonomy );
				if ( is_array( $terms ) ) {
					foreach ( $terms as $term ) {
						$term_link = get_term_link( $term );
						$this->purge_single( $term_link );
					}
				}
			}

			if ( $post_type_archive = get_post_type_archive_link( get_post_type( $post_id ) ) ) {
				$this->purge_single( $post_type_archive );
			}

			$post_date = (array) json_decode( get_the_date( '{"\y":"Y","\m":"m","\d":"d"}', $post_id ) );
			if ( ! empty( $post_date ) ) {
				$this->purge_all( $this->uri_to_cache( get_year_link( $post_date['y'] ) ) );
			}
		}

		function edit_terms( $term_id, $taxonomy ) {
			$url = get_term_link( $term_id );
			$this->purge_single( $url );
		}

		function write( $page ) {
			$base = parse_url( trailingslashit( get_option( 'home' ) ), PHP_URL_PATH );

			if ( $this->is_cachable() && false === strpos( $page, 'nonce' ) && ! empty( $page ) ) {
				$this->path = WP_CONTENT_DIR . '/endurance-page-cache' . str_replace( get_option( 'home' ), '', $_SERVER['REQUEST_URI'] );
				$this->path = str_replace( '/endurance-page-cache' . $base, '/endurance-page-cache/', $this->path );
				$this->path = str_replace( '//', '/', $this->path );
				if ( ! is_dir( $this->path ) ) {
					mkdir( $this->path, 0755, true );
				}
				file_put_contents( $this->path . '_index.html', $page . '<!--Generated by Endurance Page Cache-->', LOCK_EX );
			}
			return $page;
		}

		function purge_all( $dir = null ) {
			if ( is_null( $dir ) || 'true' == $dir ) {
				$dir = WP_CONTENT_DIR . '/endurance-page-cache';
			}
			$dir = str_replace( '_index.html', '', $dir );
			if ( is_dir( $dir ) ) {
				$files = scandir( $dir );
				if ( is_array( $files ) ) {
					$files = array_diff( $files, array( '.', '..' ) );
				}

				if ( is_array( $files ) && 2 < count( $files ) ) {
					foreach ( $files as $file ) {
						if ( is_dir( $dir . '/' . $file ) ) {
							$this->purge_all( $dir . '/' . $file );
						} else {
							unlink( $dir . '/' . $file );
						}
					}
					rmdir( $dir );
				}
			}
		}

		function purge_single( $uri ) {
			$cache_file = $this->uri_to_cache( $uri );
			if ( file_exists( $cache_file ) ) {
				unlink( $cache_file );

			}
			if ( file_exists( $this->cache_dir . '/_index.html' ) ) {
				unlink( $this->cache_dir . '/_index.html' );
			}
		}

		function uri_to_cache( $uri ) {
			$path = str_replace( get_site_url(), '', $uri );
			return $this->cache_dir . $path . '_index.html';
		}

		function is_cachable() {

			$return = true;
			if ( is_admin() ) {
				$return = false;
			}

			if ( ! get_option( 'permalink_structure' ) ) {
				$return = false;
			}

			if ( is_user_logged_in() ) {
				$return = false;
			}

			if ( isset( $_GET ) && ! empty( $_GET ) ) {
				$return = false;
			}

			if ( isset( $_POST ) && ! empty( $_POST ) ) {
				$return = false;
			}

			if ( is_feed() ) {
				$return = false;
			}

			if ( empty( $_SERVER['REQUEST_URI'] ) ) {
				$return = false;
			} else {
				foreach ( $this->cache_exempt as $exclude ) {
					if ( strpos( $_SERVER['REQUEST_URI'], $exclude ) ) {
						$return = false;
					}
				}
			}

			return apply_filters( 'epc_is_cachable', $return );
		}

		function start() {
			if ( $this->is_cachable() ) {
				ob_start( array( $this, 'write' ) );
			}
		}

		function finish() {
			if ( $this->is_cachable() ) {
				if ( ob_get_contents() ) {
					ob_end_clean();
				}
			}
		}

		function remove_wp_ver_css_js( $src ) {
			if ( strpos( $src, 'ver=' ) ) {
				$src = remove_query_arg( 'ver', $src );
			}
			return $src;
		}

		function htaccess_contents( $rules ) {
			$base = parse_url( trailingslashit( get_option( 'home' ) ), PHP_URL_PATH );
			$cache_url = $base . str_replace( get_option( 'home' ), '', WP_CONTENT_URL . '/endurance-page-cache' );
			$cache_url = str_replace( '//', '/', $cache_url );
			$additions = '<IfModule mod_rewrite.c>
	RewriteEngine On
	RewriteBase ' . $base . '
	RewriteRule ^' . $cache_url . '/ - [L]

	RewriteCond %{REQUEST_METHOD} !POST
	RewriteCond %{QUERY_STRING} !.*=.*
	RewriteCond %{HTTP_COOKIE} !(wordpress_test_cookie|comment_author|wp\-postpass|wordpress_logged_in|wptouch_switch_toggle) [NC]
	RewriteCond %{DOCUMENT_ROOT}' . $cache_url . '/$1/_index.html -f
	RewriteRule ^(.*)$ ' . $cache_url . '/$1/_index.html [L]

</IfModule>
<IfModule mod_expires.c>
	ExpiresActive On
	ExpiresByType image/jpg "access plus 1 year"
	ExpiresByType image/jpeg "access plus 1 year"
	ExpiresByType image/gif "access plus 1 year"
	ExpiresByType image/png "access plus 1 year"
	ExpiresByType text/css "access plus 1 month"
	ExpiresByType application/pdf "access plus 1 month"
	ExpiresByType text/x-javascript "access plus 1 month"
	ExpiresByType image/x-icon "access plus 1 year"
	ExpiresDefault "access plus 1 weeks"
</IfModule>

';
			return $additions . $rules;
		}
	}
	$epc = new Endurance_Page_Cache;
}
