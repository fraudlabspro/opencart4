<?php
namespace Opencart\Catalog\Model\Extension\Fraudlabspro\Fraud;
class Fraudlabspro extends \Opencart\System\Engine\Model {
	public function check(array $data): int {
		// Do not perform fraud check if FraudLabs Pro is disabled.
		if (!$this->config->get('fraud_fraudlabspro_status')) {
			return 0;
		}

		// Do not perform fraud check if API key is not provided.
		if (!$this->config->get('fraud_fraudlabspro_key')) {
			$this->write_debug_log('FraudLabs Pro validation will not be performed due to API key not provided.');
			return 0;
		}

		$this->db->query("DROP PROCEDURE IF EXISTS `Alter_FLP_Table`;");
		$this->db->query("CREATE PROCEDURE Alter_FLP_Table()
		BEGIN
			IF NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '" . DB_DATABASE . "' AND TABLE_NAME = '" . DB_PREFIX . "fraudlabspro' AND COLUMN_NAME = 'fraudlabspro_rules')
			THEN ALTER TABLE `" . DB_PREFIX . "fraudlabspro` ADD `fraudlabspro_rules` VARCHAR(200) NOT NULL;
			END IF;
		END;");
		$this->db->query("CALL Alter_FLP_Table();");
		$this->db->query("DROP PROCEDURE `Alter_FLP_Table`;");

		$this->db->query("DROP PROCEDURE IF EXISTS `Alter_FLP_Table`;");
		$this->db->query("CREATE PROCEDURE Alter_FLP_Table()
		BEGIN
			IF NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '" . DB_DATABASE . "' AND TABLE_NAME = '" . DB_PREFIX . "fraudlabspro' AND COLUMN_NAME = 'is_phone_verified')
			THEN ALTER TABLE `" . DB_PREFIX . "fraudlabspro` ADD `is_phone_verified` VARCHAR(50) NOT NULL;
			END IF;
		END;");
		$this->db->query("CALL Alter_FLP_Table();");
		$this->db->query("DROP PROCEDURE `Alter_FLP_Table`;");

		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "fraudlabspro` WHERE order_id = '" . (int)$data['order_id'] . "'");

		// Do not call FraudLabs Pro API if order is already screened.
		if ($query->num_rows) {
			return 0;
		}

		$this->write_debug_log('FraudLabs Pro validation started for Order ' . (int)$data['order_id'] . '.');

		$ip = $data['ip'];

		// Get the data of all ips
		$ip_sucuri = $ip_incap = $ip_cf = $ip_forwarded = '::1';
		$ip_remoteadd = $_SERVER['REMOTE_ADDR'];

		// Get real client IP is they are behind Sucuri firewall.
		if(isset($_SERVER['HTTP_X_SUCURI_CLIENTIP']) && filter_var($_SERVER['HTTP_X_SUCURI_CLIENTIP'], FILTER_VALIDATE_IP)){
			$ip_sucuri = $ip = $_SERVER['HTTP_X_SUCURI_CLIENTIP'];
		}

		// Get real client IP is they are behind Incapsula firewall.
		if(isset($_SERVER['HTTP_INCAP_CLIENT_IP']) && filter_var($_SERVER['HTTP_INCAP_CLIENT_IP'], FILTER_VALIDATE_IP)){
			$ip_incap = $ip = $_SERVER['HTTP_INCAP_CLIENT_IP'];
		}

		// Get real client IP is they are behind CloudFlare protection.
		if(isset($_SERVER['HTTP_CF_CONNECTING_IP']) && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)){
			$ip_cf = $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
		}

		// Get real client IP is they are behind proxy server.
		if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$xip = trim(current(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])));
			
			if (filter_var($xip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
				$ip_forwarded = $ip = $xip;
			}
		}

		// Overwrite client IP if simulate IP is provided.
		if (filter_var($this->config->get('fraud_fraudlabspro_simulate_ip'), FILTER_VALIDATE_IP)) {
			$ip = $this->config->get('fraud_fraudlabspro_simulate_ip');
		}

		$payment_mode = (isset($data['payment_code']) ? $data['payment_code'] : $data['payment_method']['code']);
		if (strpos($payment_mode, '.') !== false) {
			$payment_mode = substr($payment_mode, (strpos($payment_mode, '.')+1));
		}
		$data['payment_code'] = $payment_mode;
		if ($payment_mode === 'ccsave') {
			$paymentMode = 'creditcard';
		} elseif ($payment_mode === 'cashondelivery') {
			$paymentMode = 'cod';
		} elseif ($payment_mode === 'paypal_standard' || $payment_mode === 'paypal_express') {
			$paymentMode = 'paypal';
		} else {
			$paymentMode = $payment_mode;
		}

		// Get products SKU
		$item_sku = '';
		$qty = 0;
		$order_products = $this->db->query("SELECT p.`sku`, o.`quantity`, p.`shipping`, t.`title` FROM `" . DB_PREFIX . "order_product` o JOIN `" . DB_PREFIX . "product` p ON o.`product_id` = p.`product_id` JOIN `" . DB_PREFIX . "tax_class` t ON p.`tax_class_id` = t.`tax_class_id` WHERE o.`order_id` = '" . (int)$data['order_id'] . "'");
		foreach ($order_products->rows as $order_product) {
			$item_quantity = (int)$order_product['quantity'];
			if ($order_product['sku'] != '') {
				$item_type = ($order_product['shipping']) ? 'physical' : ((strpos(strtolower($order_product['title']), 'downloadable')!== false) ? 'downloadable' :'virtual');
				$item_sku .= $order_product['sku'] . ':' . $item_quantity . ':' . $item_type . ',';
			}
			$qty += $item_quantity;
		}
		$item_sku = rtrim($item_sku, ',');

		if (preg_match('/^\d+(\.\d)*$/', $qty)) {
			$qty = ceil($qty);
		}

		// Get coupon information
		$coupon_code = '';
		$coupon_amt = '';
		$coupon_type = '';
		$order_coupon = $this->db->query("SELECT c.`code`, o.`value`, c.`type` FROM `" . DB_PREFIX . "order_total` o JOIN `" . DB_PREFIX . "coupon` c WHERE o.`order_id` = '" . (int)$data['order_id'] . "' AND o.`code` = 'coupon' AND o.`title` LIKE CONCAT('%', c.`code`, '%')");
		if ($order_coupon->num_rows > 0) {
			$coupon_code = $order_coupon->row['code'];
			$coupon_amt = -($order_coupon->row['value']);
			$coupon_type = (($order_coupon->row['type'] == 'p') ? 'percentage' : 'fixed_amount');
		}

		$request['key'] = $this->config->get('fraud_fraudlabspro_key');
		$request['ip'] = $ip;
		$request['ip_remoteadd'] = $ip_remoteadd;
		$request['ip_sucuri'] = $ip_sucuri;
		$request['ip_incap'] = $ip_incap;
		$request['ip_cf'] = $ip_cf;
		$request['ip_forwarded'] = $ip_forwarded;
		$request['first_name'] = $data['firstname'];
		$request['last_name'] = $data['lastname'];
		$request['bill_addr'] = $data['payment_address_1'] ? $data['payment_address_1'] : $data['shipping_address_1'];
		$request['bill_city'] = $data['payment_city'] ? $data['payment_city'] : $data['shipping_city'];
		$request['bill_state'] = $data['payment_zone'] ? $data['payment_zone'] : $data['shipping_zone'];
		$request['bill_country'] = $data['payment_iso_code_2'] ? $data['payment_iso_code_2'] : $data['shipping_iso_code_2'];
		$request['bill_zip_code'] = $data['payment_postcode'] ? $data['payment_postcode'] : $data['shipping_postcode'];
		$request['email_domain'] = substr($data['email'], strpos($data['email'], '@' ) + 1);
		$request['user_phone'] = $data['telephone'];

		if ($data['shipping_method']) {
			$request['ship_first_name'] = $data['shipping_firstname'];
			$request['ship_last_name'] = $data['shipping_lastname'];
			$request['ship_addr'] = $data['shipping_address_1'];
			$request['ship_city'] = $data['shipping_city'];
			$request['ship_state'] = $data['shipping_zone'];
			$request['ship_zip_code'] = $data['shipping_postcode'];
			$request['ship_country'] = $data['shipping_iso_code_2'];
		}

		$request['email'] = $data['email'];
		$request['email_hash'] = $this->hashIt($data['email']);
		$request['amount'] = $this->currency->format($data['total'], $data['currency_code'], $data['currency_value'], false);
		$request['quantity'] = $qty;
		$request['currency'] = $data['currency_code'];
		$request['payment_gateway'] = $data['payment_code'];
		$request['payment_mode'] = $paymentMode;
		$request['user_order_id'] = $data['order_id'];
		$request['device_fingerprint'] = ($_COOKIE['flp_device']) ?? '';
		$request['flp_checksum'] = ($_COOKIE['flp_checksum']) ?? '';
		$request['bin_no'] = ($_SESSION['flp_cc_bin']) ?? '';
		$request['card_hash'] = ($_SESSION['flp_cc_hash']) ?? '';
		$request['items'] = $item_sku;
		$request['coupon_code'] = $coupon_code;
		$request['coupon_amount'] = $coupon_amt;
		$request['coupon_type'] = $coupon_type;
		$request['format'] = 'json';
		$request['source'] = 'opencart';
		$request['source_version'] = '4.0.6.0';

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, 'https://api.fraudlabspro.com/v2/order/screen');
		curl_setopt($ch, CURLOPT_FAILONERROR, false);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
		curl_setopt($ch, CURLOPT_HTTP_VERSION, '1.1');
		curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, (is_array($request)) ? http_build_query($request) : $request);

		$response = curl_exec($ch);

		curl_close($ch);

		if (is_null($json = json_decode($response)) === FALSE) {
			$json->fraudlabspro_rules = isset($json->fraudlabspro_rules) ? implode(', ', $json->fraudlabspro_rules) : '';
			$this->db->query("REPLACE INTO `" . DB_PREFIX . "fraudlabspro` SET order_id = '" . (int)$data['order_id'] . "',
				is_country_match = '" . $this->db->escape(($json->billing_address->is_ip_country_match) ? 'Y' : 'N') . "',
				is_high_risk_country = '',
				distance_in_km = '" . $this->db->escape($json->billing_address->ip_distance_in_km) . "',
				distance_in_mile = '" . $this->db->escape($json->billing_address->ip_distance_in_mile) . "',
				ip_country = '" . $this->db->escape($json->ip_geolocation->country_code) . "',
				ip_region = '" . $this->db->escape($json->ip_geolocation->region) . "',
				ip_city = '" . $this->db->escape($json->ip_geolocation->city) . "',
				ip_continent = '" . $this->db->escape($json->ip_geolocation->continent) . "',
				ip_latitude = '" . $this->db->escape($json->ip_geolocation->latitude) . "',
				ip_longitude = '" . $this->db->escape($json->ip_geolocation->longitude) . "',
				ip_timezone = '" . $this->db->escape($json->ip_geolocation->timezone) . "',
				ip_elevation = '" . $this->db->escape($json->ip_geolocation->elevation) . "',
				ip_domain = '" . $this->db->escape($json->ip_geolocation->domain) . "',
				ip_mobile_mnc = '" . $this->db->escape(($json->ip_geolocation->mobile_mnc) ?? '') . "',
				ip_mobile_mcc = '" . $this->db->escape(($json->ip_geolocation->mobile_mcc) ?? '') . "',
				ip_mobile_brand = '" . $this->db->escape(($json->ip_geolocation->mobile_brand) ?? '') . "',
				ip_netspeed = '" . $this->db->escape(($json->ip_geolocation->netspeed) ?? '') . "',
				ip_isp_name = '" . $this->db->escape(($json->ip_geolocation->isp_name) ?? '') . "',
				ip_usage_type = '" . $this->db->escape(implode(', ', $json->ip_geolocation->usage_type)) . "',
				is_free_email = '" . $this->db->escape(($json->email_address->is_free) ? 'Y' : 'N') . "',
				is_new_domain_name = '" . $this->db->escape(($json->email_address->is_new_domain_name) ? 'Y' : 'N') . "',
				is_proxy_ip_address = '" . $this->db->escape(($json->ip_geolocation->is_proxy) ? 'Y' : 'N') . "',
				is_bin_found = '" . $this->db->escape(($json->credit_card->is_bin_exist) ? 'Y' : 'N') . "',
				is_bin_country_match = '" . $this->db->escape(($json->credit_card->is_bin_country_match) ? 'Y' : 'N') . "',
				is_bin_name_match = '',
				is_bin_phone_match = '',
				is_bin_prepaid = '" . $this->db->escape(($json->credit_card->is_prepaid) ? 'Y' : 'N') . "',
				is_address_ship_forward = '" . $this->db->escape(($json->shipping_address->is_address_ship_forward) ? 'Y' : 'N') . "',
				is_bill_ship_city_match = '" . $this->db->escape(($json->shipping_address->is_bill_city_match) ? 'Y' : 'N') . "',
				is_bill_ship_state_match = '" . $this->db->escape(($json->shipping_address->is_bill_state_match) ? 'Y' : 'N') . "',
				is_bill_ship_country_match = '" . $this->db->escape(($json->shipping_address->is_bill_country_match) ? 'Y' : 'N') . "',
				is_bill_ship_postal_match = '" . $this->db->escape(($json->shipping_address->is_bill_postcode_match) ? 'Y' : 'N') . "',
				is_ip_blacklist = '" . $this->db->escape(($json->ip_geolocation->is_in_blacklist) ? 'Y' : 'N') . "',
				is_email_blacklist = '" . $this->db->escape(($json->email_address->is_in_blacklist) ? 'Y' : 'N') . "',
				is_credit_card_blacklist = '" . $this->db->escape(($json->credit_card->is_in_blacklist) ? 'Y' : 'N') . "',
				is_device_blacklist = '" . $this->db->escape(($json->device->is_in_blacklist) ? 'Y' : 'N') . "',
				is_user_blacklist = '" . $this->db->escape(($json->username->is_in_blacklist) ? 'Y' : 'N') . "',
				fraudlabspro_rules = '" . $this->db->escape(is_array($json->fraudlabspro_rules) ? implode(', ', $json->fraudlabspro_rules) : $json->fraudlabspro_rules) . "',
				fraudlabspro_score = '" . $this->db->escape($json->fraudlabspro_score) . "',
				fraudlabspro_distribution = '',
				fraudlabspro_status = '" . $this->db->escape($json->fraudlabspro_status) . "',
				fraudlabspro_id = '" . $this->db->escape($json->fraudlabspro_id) . "',
				fraudlabspro_error = '" . $this->db->escape(($json->error->error_code) ?? '') . "',
				fraudlabspro_message = '" . $this->db->escape(($json->error->error_message) ?? '') . "',
				fraudlabspro_credits = '" .  $this->db->escape($json->remaining_credits) . "',
				api_key = '" .  $this->config->get('fraud_fraudlabspro_key') . "',
				ip_address = '" .  $ip . "'"
			);
		} else {
			$this->write_debug_log('Order ' . (int)$data['order_id'] . ' data contains invalid value.');
		}

		if (isset($_SESSION['flp_cc_bin'])) {
			unset($_SESSION['flp_cc_bin']);
		}

		if (isset($_SESSION['flp_cc_hash'])) {
			unset($_SESSION['flp_cc_hash']);
		}

		// Do not perform any action if error found
		$flpErr = ($json->fraudlabspro_error_code ?? '');
		if ($flpErr) {
			$this->write_debug_log('Error code:' . $flpErr . ' found in Order ' . (int)$data['order_id'] . '.');
			return 0;
		}

		if (($this->config->get('fraud_fraudlabspro_zapier_approve') && $json->fraudlabspro_status == 'APPROVE') || ($this->config->get('fraud_fraudlabspro_zapier_review') && $json->fraudlabspro_status == 'REVIEW') || ( $this->config->get('fraud_fraudlabspro_zapier_reject') && $json->fraudlabspro_status == 'REJECT')) {
			// Use zaptrigger API to get zap information
			$zap_request['key'] = $this->config->get('fraud_fraudlabspro_key');
			$zap_request['format'] = 'json';

			$curl = curl_init();
			curl_setopt($curl, CURLOPT_URL, 'https://api.fraudlabspro.com/v2/zaptrigger?' . http_build_query($zap_request));
			curl_setopt($curl, CURLOPT_HEADER, 0);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
			curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);

			$zap_response = curl_exec($curl);

			curl_close($curl);

			if (is_null($zap_json = json_decode($zap_response)) === FALSE) {
				$target_url = $zap_json->target_url;
			} else {
				$target_url = '';
			}

			if (!empty($target_url)) {
				$zapresponse = $this->http($target_url, [
					'id'			=> $json->fraudlabspro_id,
					'date_created'	=> gmdate('Y-m-d H:i:s'),
					'flp_status'	=> $json->fraudlabspro_status,
					'full_name'		=> $data['firstname'] . ' ' . $data['lastname'],
					'email'			=> $data['email'],
					'order_id'		=> $data['order_id'],
				]);
				$zapdata = json_decode($zapresponse);
				if (is_object($zapdata)) {
					if ($zapdata->status == 'success') {
						$this->write_debug_log('Hooks sent to Zapier successful.');
					} else {
						$this->write_debug_log('Hooks sent to Zapier failed.');
					}
				} else {
					$this->write_debug_log('Failed in sending hook to Zapier.');
				}
			} else {
				$this->write_debug_log('Zapier target_url not found.');
			}
		}

		$this->write_debug_log('FraudLabs Pro validation completed with status [' . $json->fraudlabspro_status . ']. Transaction ID = ' . $json->fraudlabspro_id . '.');

		if ($json->fraudlabspro_status == 'REVIEW') {
			return $this->config->get('fraud_fraudlabspro_review_status_id');
		}

		if ($json->fraudlabspro_status == 'APPROVE') {
			return $this->config->get('fraud_fraudlabspro_approve_status_id');
		}

		if ($json->fraudlabspro_status == 'REJECT') {
			return $this->config->get('fraud_fraudlabspro_reject_status_id');
		}
	}

	private function hashIt(string $s): string {
		$hash = 'fraudlabspro_' . $s;

		for ($i = 0; $i < 65536; $i++)
			$hash = sha1('fraudlabspro_' . $hash);

		return $hash;
	}

	// Write to debug log to record details of process.
	private function write_debug_log(string $message): int {
		if (!$this->config->get('fraud_fraudlabspro_debug_status')) {
			return 0;
		}

		$log = new \Opencart\System\Library\Log('FLP_debug.log');
		$log->write($message);
		return 0;
	}

	private function http(string $url, array $fields = []): array|string {
		$ch = curl_init();

		if ($fields) {
			$data_string = json_encode($fields);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
		}

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FAILONERROR, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_HTTP_VERSION, '1.1');
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'Content-Length: ' . strlen($data_string))
		);

		$response = curl_exec($ch);

		if (!curl_errno($ch)) {
			return $response;
		}

		return false;
	}
}