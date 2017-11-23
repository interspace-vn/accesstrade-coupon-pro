<?php
/*
Plugin Name: AccessTrade Coupon Pro
Plugin URI: http://github.com/nhymxu/accesstrade-coupon-pro
Description: Pro version for AccessTrade coupon
Author: Dũng Nguyễn (nhymxu)
Version: 0.2.0
Author URI: http://dungnt.net
*/

defined( 'ABSPATH' ) || die;
define('NHYMXU_AT_COUPON_PRO_VER', '0.2.0');

date_default_timezone_set('Asia/Ho_Chi_Minh');

class nhymxu_at_coupon_pro {

	private $ignore_campains = [
		'lazadacashback',
		'uber_rider',
		'ubernew',
		'agodamobile',
		'lazadaapp',
	];

	public function __construct() {
		add_action( 'nhymxu_at_coupon_sync_merchant_event', [$this,'do_this_daily'] );
		add_action( 'init', [$this, 'init_updater'] );
		add_action( 'wp_ajax_nhymxu_coupons_ajax_forceupdate_merchants', [$this, 'ajax_force_update_merchants'] );
	}

	public function do_this_daily() {
		global $wpdb;
		$current_time = time();

		$options = get_option('nhymxu_at_coupon', ['uid' => '', 'accesskey' => '','utmsource' => '']);

		if( $options['accesskey'] == '' ) {
			return false;
		} 

		$url = 'https://api.accesstrade.vn/v1/campaigns';

		$args = [
			'timeout'=>'60',
			'headers' => ['Authorization' => 'Token '. $options['accesskey'] ],
		];

		$result = wp_remote_get( $url, $args );		
		if ( is_wp_error( $result ) ) {
			$msg = [];
			$msg['previous_time'] = '';
			$msg['current_time'] = $current_time;
			$msg['error_msg'] = $result->get_error_message();
			$msg['action'] = 'get_merchant';

			$nhymxu_at_coupon->insert_log( $msg );
		} else {
			$input = json_decode( $result['body'], true );
			if( !empty($input) && isset( $input['data'] ) && is_array( $input['data'] ) ) {
				$prepare_data = [];
				foreach( $input['data'] as $campain ) {
					if( $campain['approval'] == 'successful' && $campain['scope'] == 'public' && !in_array( $campain['merchant'], $this->ignore_campains ) ) {
						$prepare_data[$campain['merchant']] = $campain['name'];
					}
				}
				update_option( 'nhymxu_at_coupon_merchants', $prepare_data );
			}
		}
	}

	/*
	 * Force update merchant list from server
	 */
	public function ajax_force_update_merchants() {
		$this->do_this_daily();
		echo 'running';
		wp_die();		
	}

	public function init_updater() {
		if( is_admin() ) {
			if( !class_exists('nhymxu_AT_AutoUpdate') ) {
				require_once('nhymxu-updater.php');
			}
			$plugin_remote_path = 'http://sv.isvn.space/wp-update/plugin-accesstrade-coupon-pro.json';
			$plugin_slug = plugin_basename( __FILE__ );
			$license_user = 'nhymxu';
			$license_key = 'AccessTrade';
			new nhymxu_AT_AutoUpdate( NHYMXU_AT_COUPON_VER, $plugin_remote_path, $plugin_slug, $license_user, $license_key );
		}
	}

	private function insert_coupon( $data ) {
		global $wpdb;
		
		$result = $wpdb->insert( 
			$wpdb->prefix . 'coupons',
			[
				'type'	=> $data['merchant'],
				'title' => trim($data['title']),
				'code'	=> ($data['coupon_code']) ? trim($data['coupon_code']) : '',
				'exp'	=> $data['date_end'],
				'note'	=> trim($data['coupon_desc']),
				'url'	=> ($data['link']) ? trim($data['link']) : '',
				'save'	=> ($data['coupon_save']) ? trim($data['coupon_save']) : ''
			],
			['%s','%s','%s','%s','%s','%s','%s']
		);
		
		if ( $result ) {
			$coupon_id = $wpdb->insert_id;
			if( isset( $data['categories'] ) && !empty( $data['categories'] ) ) {
				$cat_ids = $this->get_coupon_category_id( $data['categories'] );
				foreach( $cat_ids as $row ) {
					$wpdb->insert(
						$wpdb->prefix . 'coupon_category_rel',
						[
							'coupon_id' => $coupon_id,
							'category_id'	=> $row
						],
						['%d', '%d']
					);
				}
			}
	
			return 1;
		}

		$msg = [];
		$msg['previous_time'] = '';
		$msg['current_time'] = '';
		$msg['error_msg'] = json_encode( $data );
		$msg['action'] = 'insert_coupon';
			
		$nhymxu_at_coupon->insert_log( $msg );		

		return 0;
	}

	private function get_coupon_category_id( $input ) {
		global $wpdb;
	
		$cat_id = [];
	
		foreach( $input as $row ) {
			$slug = trim($row['slug']);
			$result = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}coupon_categories WHERE slug = '{$slug}'");
			
			if( $result ) {
				$cat_id[] = (int) $result->id;
			} else {
				$result = $wpdb->insert(
					$wpdb->prefix . 'coupon_categories',
					[
						'name'	=> trim($row['title']),
						'slug'	=> trim($row['slug'])
					],
					['%s', '%s']
				);
				$cat_id[] = (int) $wpdb->insert_id;				
			}
		}
	
		return $cat_id;
	}
}

$nhymxu_at_coupon_pro = new nhymxu_at_coupon_pro();

if( is_admin() ) {
	require_once __DIR__ . '/coupons_list.php';
	require_once __DIR__ . '/admin.php';
	new nhymxu_at_coupon_pro_admin();
}

require_once __DIR__ . '/install.php';
register_activation_hook( __FILE__, ['nhymxu_at_coupon_pro_install', 'plugin_install'] );
register_deactivation_hook( __FILE__, ['nhymxu_at_coupon_pro_install', 'plugin_deactive'] );
register_uninstall_hook( __FILE__, ['nhymxu_at_coupon_pro_install', 'plugin_uninstall'] );
