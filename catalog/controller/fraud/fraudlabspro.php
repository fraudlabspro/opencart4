<?php
namespace Opencart\Catalog\Controller\Extension\Fraudlabspro\Fraud;
class Fraudlabspro extends \Opencart\System\Engine\Controller {
	/**
	 * @param string $route
	 * @param array  $args
	 * @param mixed  $output
	 *
	 * @return void
	 */
	public function index(string &$route, array &$args, mixed &$output): void {
		if ($this->config->get('fraud_fraudlabspro_status') && $this->config->get('fraud_fraudlabspro_sync_status')) {
			if (isset($args[0])) {
				$order_id = $args[0];
			} else {
				$order_id = 0;
			}

			if (isset($args[1])) {
				$order_status_id = $args[1];
			} else {
				$order_status_id = 0;
			}

			if (in_array($order_status_id, [5,7])) {
				$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "fraudlabspro` WHERE order_id = '" . (int)$order_id . "'");
				$fraud_info = $query->row;

				if ($fraud_info) {
					$fraud_fraudlabspro_key = $this->config->get('fraud_fraudlabspro_key');
					$flp_status = ($order_status_id == 5) ? 'APPROVE' : 'REJECT';
					$flp_trigger = ($order_status_id == 5) ? 'order_status_complete' : 'order_status_canceled';

					$request = [
						'key'			=> $fraud_fraudlabspro_key,
						'action'		=> $flp_status,
						'id'			=> $fraud_info['fraudlabspro_id'],
						'format'		=> 'json',
						'source'		=> 'opencart',
						'triggered_by'	=> $flp_trigger
					];

					$ch = curl_init();
					curl_setopt($ch, CURLOPT_URL, 'https://api.fraudlabspro.com/v2/order/feedback');
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

					$this->db->query("UPDATE `" . DB_PREFIX . "fraudlabspro` SET fraudlabspro_status = '" . $this->db->escape((string)$flp_status) . "' WHERE order_id = " . (int)$order_id);
				}
			}
		}
	}
}