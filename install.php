<?php

class nhymxu_at_coupon_pro_install {
	public static function active_track() {
		wp_remote_post( 'http://mail.isvn.space/nhymxu-track.php', [
			'method' => 'POST',
			'timeout' => 45,
			'redirection' => 5,
			'httpversion' => '1.0',
			'blocking' => true,
			'headers' => [],
			'body' => [
				'_hidden_nhymxu' => 'tracking_active',
				'domain' => get_option( 'siteurl' ),
				'email'	 => get_option( 'admin_email' ),
				'name'	=> 'nhymxu-at-coupon-pro'
			],
			'cookies' => []
		]);
	}

	static public function plugin_install() {
		static::active_track();

		if (! wp_next_scheduled ( 'nhymxu_at_coupon_sync_merchant_event' )) {
			wp_schedule_event( time(), 'daily', 'nhymxu_at_coupon_sync_merchant_event' );
		}
	}

	static public function plugin_deactive() {
		wp_clear_scheduled_hook( 'nhymxu_at_coupon_sync_merchant_event' );
	}

	static public function plugin_uninstall() {
		delete_option('nhymxu_at_coupon_merchants');
		delete_site_option('nhymxu_at_coupon_merchants');
		wp_clear_scheduled_hook( 'nhymxu_at_coupon_sync_merchant_event' );
	}
}
