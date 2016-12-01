<?php

/**
 * Plugin Name: WikiPal Payment for EDD
 * Version: 1.0
 * Description: این افزونه درگاه پرداخت <a href="http://wikipal.ir">ویکی پال</a> را به افزونه‌ی EDD اضافه می‌کند.
 * Plugin URI: http://wikipal.ir/
 * Author: Milad Maldar
 * Author: http://ltiny.ir/
 */

if (!function_exists('edd_rial')) {

    function edd_rial($formatted, $currency, $price) {
        return $price . ' ریال';
    }

}
add_filter('edd_rial_currency_filter_after', 'edd_rial', 10, 3);
@session_start();

function wpw_edd_rial($formatted, $currency, $price) {
    return $price . 'ریال';
}

function add_wikipal_gateway($gateways) {
    $gateways['wikipal'] = array(
        'admin_label' => 'ویکی پال',
        'checkout_label' => 'درگاه ویکی پال'
    );

    return $gateways;
}

add_filter('edd_payment_gateways', 'add_wikipal_gateway');

function wikipal_cc_form() {
    return;
}

add_action('edd_wikipal_cc_form', 'wikipal_cc_form');

function wpal_process($purchase_data) {
    global $edd_options;

    $payment_data = array(
        'price' 		=> $purchase_data['price'],
        'date' 			=> $purchase_data['date'],
        'user_email'	=> $purchase_data['post_data']['edd_email'],
        'purchase_key' 	=> $purchase_data['purchase_key'],
        'currency' 		=> $edd_options['currency'],
        'downloads' 	=> $purchase_data['downloads'],
        'cart_details' 	=> $purchase_data['cart_details'],
        'user_info' 	=> $purchase_data['user_info'],
        'status' 		=> 'pending'
    );
    $payment = edd_insert_payment($payment_data);

    if ($payment) {
        delete_transient('edd_wikipal_record');
        set_transient('edd_wikipal_record', $payment);

		$MerchantID 			= $edd_options['wpal_merchant'];
		$Price 					= intval($payment_data['price']) / 10;
		$Description 			= 'پرداخت صورت حساب ' . $purchase_data['purchase_key'];
		$InvoiceNumber 			= $payment;
		$CallbackURL 			= add_query_arg('verify', 'wikipal', get_permalink($edd_options['success_page']));
		$accesspage 			= 'http://gatepay.co/webservice/startPayment.php?au=%s';

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, 'http://gatepay.co/webservice/paymentRequest.php');
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type' => 'application/json'));
		curl_setopt($curl, CURLOPT_POSTFIELDS, "MerchantID=$MerchantID&Price=$Price&Description=$Description&InvoiceNumber=$InvoiceNumber&CallbackURL=". urlencode($CallbackURL));
		curl_setopt($curl, CURLOPT_TIMEOUT, 400);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		$result = json_decode(curl_exec($curl));
		curl_close($curl);
		
		edd_insert_payment_note($payment, 'کد پاسخ ویکی پال : ' . $result->Status . ' و کد پرداخت: ' . $result->Authority);
		if ($result->Status == 100){
			wp_redirect(sprintf($accesspage, $result->Authority));
		} else {
			wp_die('خطای ' . $result->Status . ': در اتصال به درگاه پرداخت مشکلی پیش آمد');
			exit;
		}
    } else {
        edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
    }
}

add_action('edd_gateway_wikipal', 'wpal_process');

function wpal_verify() {
    global $edd_options;
    if (isset($_GET['verify']) && $_GET['verify'] == 'wikipal') {

		$MerchantID 		= $edd_options['wpal_merchant'];
		$Authority 			= esc_attr($_POST['authority']);
		$payment_id 		= $_POST['InvoiceNumber'];
		$Price 				= intval(edd_get_payment_amount($payment_id)) / 10;

		if ($_POST['status'] == 1) {
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_URL, 'http://gatepay.co/webservice/paymentVerify.php');
			curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type' => 'application/json'));
			curl_setopt($curl, CURLOPT_POSTFIELDS, "MerchantID=$MerchantID&Price=$Price&Authority=$Authority");
			curl_setopt($curl, CURLOPT_TIMEOUT, 400);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			$result = json_decode(curl_exec($curl));
			curl_close($curl);
			if ($result->Status == 100) {
                edd_insert_payment_note($payment_id, 'نتیجه بازگشت : وضعیت : ' . $result->Status . ' و کد پرداخت : ' . $result->RefCode);
                edd_update_payment_status($payment_id, 'publish');
                edd_send_to_success_page();
			} else {
                edd_update_payment_status($payment_id, 'failed');
                wp_redirect(get_permalink($edd_options['failure_page']));
			}
		} else {
            edd_update_payment_status($payment_id, 'revoked');
            wp_redirect(get_permalink($edd_options['failure_page']));
            exit;
		}
    }
}

add_action('init', 'wpal_verify');

function wpal_settings($settings) {
    $wikipal_options = array(
        array(
            'id' => 'wikipal_settings',
            'type' => 'header',
            'name' => 'پیکربندی ویکی پال - <a href="http://wikipal.ir/">Easy Digital Downloads</a> &ndash; <a href="mailto:info@wikipal.co">گزارش خرابی</a>'
        ),
        array(
            'id' => 'wpal_merchant',
            'type' => 'text',
            'name' => 'مرچنت‌کد',
            'desc' => 'کد درگاه که از سایت ویکی پال دریافت کرده‌اید را وارد کنید'
        )
    );

    return array_merge($settings, $wikipal_options);
}

add_filter('edd_settings_gateways', 'wpal_settings');
