<?php
/*
Plugin Name: ACCESSTRADE Coupon Pro
Plugin URI: http://github.com/nhymxu/accesstrade-coupon-pro
Description: Pro version for ACCESSTRADE coupon
Author: Dũng Nguyễn (nhymxu)
Version: 0.2.0
Author URI: http://dungnt.net
*/

defined( 'ABSPATH' ) || die;
define('NHYMXU_AT_COUPON_PRO_VER', "0.2.0");

date_default_timezone_set('Asia/Ho_Chi_Minh');

include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
if( !is_plugin_active( 'nhymxu-at-coupon/nhymxu-at-coupon.php' ) ) {
	deactivate_plugins( plugin_basename( __FILE__ ) );
}

function nhymxu_at_coupon_pro_weekly_cron_schedule( $schedules ) {
	$schedules[ 'weekly' ] = array(
		'interval' => 60 * 60 * 24 * 7, # 604,800, seconds in a week
		'display' => __( 'Weekly' ) );
	return $schedules;
}
add_filter( 'cron_schedules', 'nhymxu_at_coupon_pro_weekly_cron_schedule' );

class nhymxu_at_coupon_pro {

	private $ignore_campains = [
		'lazadacashback',
		'uber_rider',
		'ubernew',
		'agodamobile',
		'lazadaapp',
	];

	private $endpoint_at_campaign = 'https://api.accesstrade.vn/v1/campaigns';
	private $endpoint_plugin_update = 'http://sv.isvn.space/wp-update/plugin-accesstrade-coupon-pro.json';
	private $endpoint_sv_category = 'http://sv.isvn.space/api/v1/mars/category';

	public function __construct() {
		add_action( 'nhymxu_at_coupon_sync_merchant_event', [$this,'do_this_daily'] );
		add_action( 'nhymxu_at_coupon_sync_category_event', [$this, 'do_this_weekly'] );
		add_action( 'init', [$this, 'init_updater'] );
		add_action( 'wp_ajax_nhymxu_coupons_ajax_forceupdate_merchants', [$this, 'ajax_force_update_merchants'] );
		add_action( 'wp_ajax_nhymxu_coupons_ajax_forceupdate_categories', [$this, 'ajax_force_update_categories'] );
	}

	public function do_this_daily() {
		global $wpdb, $nhymxu_at_coupon;
		$current_time = time();

		$options = get_option('nhymxu_at_coupon', ['uid' => '', 'accesskey' => '','utmsource' => '']);

		if( $options['accesskey'] == '' ) {
			return false;
		}

		$args = [
			'timeout'=>'120',
			'headers' => ['Authorization' => 'Token '. $options['accesskey'] ],
		];

		$result = wp_remote_get( $this->endpoint_at_campaign, $args );
		if ( is_wp_error( $result ) ) {
			$msg = [];
			$msg['previous_time'] = '';
			$msg['current_time'] = $current_time;
			$msg['error_msg'] = $result->get_error_message();
			$msg['action'] = 'get_merchant';

			$nhymxu_at_coupon->insert_log( $msg );

			return false;
		}

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

	public function do_this_weekly() {
		global $wpdb, $nhymxu_at_coupon;
		$current_time = time();

		$args = [ 'timeout'=>'120' ];
		$result = wp_remote_get( $this->endpoint_sv_category, $args );

		if ( is_wp_error( $result ) ) {
			$msg = [];
			$msg['previous_time'] = '';
			$msg['current_time'] = $current_time;
			$msg['error_msg'] = $result->get_error_message();
			$msg['action'] = 'get_category';

			$nhymxu_at_coupon->insert_log( $msg );

			return $result->get_error_message();
		}

		$input = json_decode( $result['body'], true );

		if( empty($input) ) {
			return 'empty_input';
		}

		$input_compare = array_map(function($elem){ return $elem['slug']; }, $input);
		$local = $wpdb->get_col("SELECT slug FROM {$wpdb->prefix}coupon_categories");
		$diff = array_diff($input_compare, $local);

		if( empty($diff) ) {
			update_option( 'nhymxu_at_coupon_sync_category_time', $current_time);
			return 'empty_diff';
		}

		$wpdb->query("START TRANSACTION;");
		try {
			foreach( $input as $remote_cat ) {
				if( !in_array( $remote_cat['slug'], $diff ) ) {
					continue;
				}

				$wpdb->insert(
					$wpdb->prefix . 'coupon_categories',
					[
						'name'	=> trim($remote_cat['title']),
						'slug'	=> trim($remote_cat['slug'])
					],
					['%s', '%s']
				);
			}
			update_option( 'nhymxu_at_coupon_sync_category_time', $current_time);
			$wpdb->query("COMMIT;");
		} catch ( Exception $e ) {
			$msg = [];
			$msg['previous_time'] = $previous_time;
			$msg['current_time'] = $current_time;
			$msg['error_msg'] = $e->getMessage();
			$msg['action'] = 'insert_category';

			$nhymxu_at_coupon->insert_log( $msg );

			$wpdb->query("ROLLBACK;");
		}

		return 'running';
	}

	/*
	 * Force update merchant list from server
	 */
	public function ajax_force_update_merchants() {
		$this->do_this_daily();
		echo 'running';
		wp_die();
	}

	/*
	 * Force update category list from server
	 */
	public function ajax_force_update_categories() {
		$result = $this->do_this_weekly();
		echo $result;
		wp_die();
	}

	public function init_updater() {
		if( is_admin() ) {
			if( !class_exists('nhymxu_AT_AutoUpdate') ) {
				require_once('nhymxu-updater.php');
			}
			$plugin_remote_path = $this->endpoint_plugin_update;
			$plugin_slug = plugin_basename( __FILE__ );
			$license_user = 'nhymxu';
			$license_key = 'AccessTrade';
			new nhymxu_AT_AutoUpdate( NHYMXU_AT_COUPON_PRO_VER, $plugin_remote_path, $plugin_slug, $license_user, $license_key );
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
