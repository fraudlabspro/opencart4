<?php
namespace Opencart\Admin\Controller\Extension\Fraudlabspro\Fraud;
class Fraudlabspro extends \Opencart\System\Engine\Controller {
	public function index(): void {
		$this->load->language('extension/fraudlabspro/fraud/fraudlabspro');

		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=fraud', true)
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/fraudlabspro/fraud/fraudlabspro', 'user_token=' . $this->session->data['user_token'], true)
		];

		$data['user_token'] = $this->session->data['user_token'];

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && (isset($this->request->post['purge']))) {
			if (!$this->user->hasPermission('modify', 'extension/fraudlabspro/fraud/fraudlabspro')) {
				$this->session->data['error_warning'] = $this->language->get('error_permission');
			} elseif (!isset($this->request->post['user_token']) || !hash_equals($this->session->data['user_token'], $this->request->post['user_token'])) {
				$this->session->data['error_warning'] = $this->language->get('error_token');
			} else {
				$this->db->query("TRUNCATE `" . DB_PREFIX . "fraudlabspro`");

				$this->session->data['success'] = $this->language->get('text_success_delete');
			}

			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=fraud', true));
		} elseif (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('fraud_fraudlabspro', $this->request->post);

			$this->session->data['success'] = $this->language->get('text_success');

			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=fraud', true));
		}

		$data['save'] = $this->url->link('extension/fraudlabspro/fraud/fraudlabspro|save', 'user_token=' . $this->session->data['user_token'], true);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=fraud', true);

		if (isset($this->request->post['fraud_fraudlabspro_key'])) {
			$data['fraud_fraudlabspro_key'] = $this->request->post['fraud_fraudlabspro_key'];
		} else {
			$data['fraud_fraudlabspro_key'] = $this->config->get('fraud_fraudlabspro_key');
		}

		$plan_name = $credit_available = $next_renewal_date = '-';
		$plan_upgrade = $credit_display = $credit_warning = '';

		if ($data['fraud_fraudlabspro_key'] != '') {
			$plan_request['key'] = $data['fraud_fraudlabspro_key'];
			$plan_request['format'] = 'json';

			$curl = curl_init();
			curl_setopt($curl, CURLOPT_URL, 'https://api.fraudlabspro.com/v2/plan/result?' . http_build_query($plan_request));
			curl_setopt($curl, CURLOPT_HEADER, 0);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
			curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);

			$plan_response = curl_exec($curl);

			curl_close($curl);

			if (is_null($plan_json = json_decode($plan_response)) === FALSE) {
				$plan_name = $plan_json->plan_name;
				$credit_available = $plan_json->query_limit - $plan_json->query_limit_used;
				$next_renewal_date = $plan_json->next_renewal_date;

				switch ($plan_name) {
					case "FraudLabs Pro Micro":
						$plan_upgrade = '8';
						break;

					case "FraudLabs Pro Mini":
						$plan_upgrade = '2';
						break;

					case "FraudLabs Pro Small":
						$plan_upgrade = '3';
						break;

					case "FraudLabs Pro Medium":
						$plan_upgrade = '4';
						break;

					case "FraudLabs Pro Large":
						$plan_upgrade = '5';
						break;
				}

				if (($plan_name == 'FraudLabs Pro Micro') && ($credit_available <= 100)){
					$credit_display = 'color:red;';
					$credit_warning = '[You are going to run out of credits, you should <a href="https://www.fraudlabspro.com/pricing" target="_blank">upgrade</a> now to avoid service disruptions.]';
				} elseif ($credit_available <= 100) {
					$credit_display = 'color:red;';
					$credit_warning = '';
				} else {
					$credit_display = $credit_warning = '';
				}
			}
		}

		$data['fraud_fraudlabspro_plan'] = $plan_name;
		$data['fraud_fraudlabspro_credit'] = number_format((int)$credit_available, false, false, ",");
		$data['fraud_fraudlabspro_credit_display'] = $credit_display;
		$data['fraud_fraudlabspro_credit_warning'] = $credit_warning;
		$data['fraud_fraudlabspro_renewal'] = $next_renewal_date;
		$data['fraud_fraudlabspro_upgrade'] = $plan_upgrade;

		if (isset($this->request->post['fraud_fraudlabspro_review_status_id'])) {
			$data['fraud_fraudlabspro_review_status_id'] = $this->request->post['fraud_fraudlabspro_review_status_id'];
		} else {
			$data['fraud_fraudlabspro_review_status_id'] = $this->config->get('fraud_fraudlabspro_review_status_id');
		}

		if (isset($this->request->post['fraud_fraudlabspro_approve_status_id'])) {
			$data['fraud_fraudlabspro_approve_status_id'] = $this->request->post['fraud_fraudlabspro_approve_status_id'];
		} else {
			$data['fraud_fraudlabspro_approve_status_id'] = $this->config->get('fraud_fraudlabspro_approve_status_id');
		}

		if (isset($this->request->post['fraud_fraudlabspro_reject_status_id'])) {
			$data['fraud_fraudlabspro_reject_status_id'] = $this->request->post['fraud_fraudlabspro_reject_status_id'];
		} else {
			$data['fraud_fraudlabspro_reject_status_id'] = $this->config->get('fraud_fraudlabspro_reject_status_id');
		}

		if (isset($this->request->post['fraud_fraudlabspro_simulate_ip'])) {
			$data['fraud_fraudlabspro_simulate_ip'] = $this->request->post['fraud_fraudlabspro_simulate_ip'];
		} else {
			$data['fraud_fraudlabspro_simulate_ip'] = $this->config->get('fraud_fraudlabspro_simulate_ip');
		}

		$this->load->model('localisation/order_status');

		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		if (isset($this->request->post['fraud_fraudlabspro_status'])) {
			$data['fraud_fraudlabspro_status'] = $this->request->post['fraud_fraudlabspro_status'];
		} else {
			$data['fraud_fraudlabspro_status'] = $this->config->get('fraud_fraudlabspro_status');
		}

		if (isset($this->request->post['fraud_fraudlabspro_zapier_approve'])) {
			$data['fraud_fraudlabspro_zapier_approve'] = $this->request->post['fraud_fraudlabspro_zapier_approve'];
		} else {
			$data['fraud_fraudlabspro_zapier_approve'] = $this->config->get('fraud_fraudlabspro_zapier_approve');
		}

		if (isset($this->request->post['fraud_fraudlabspro_zapier_review'])) {
			$data['fraud_fraudlabspro_zapier_review'] = $this->request->post['fraud_fraudlabspro_zapier_review'];
		} else {
			$data['fraud_fraudlabspro_zapier_review'] = $this->config->get('fraud_fraudlabspro_zapier_review');
		}

		if (isset($this->request->post['fraud_fraudlabspro_zapier_reject'])) {
			$data['fraud_fraudlabspro_zapier_reject'] = $this->request->post['fraud_fraudlabspro_zapier_reject'];
		} else {
			$data['fraud_fraudlabspro_zapier_reject'] = $this->config->get('fraud_fraudlabspro_zapier_reject');
		}

		if (isset($this->request->post['fraud_fraudlabspro_debug_status'])) {
			$data['fraud_fraudlabspro_debug_status'] = $this->request->post['fraud_fraudlabspro_debug_status'];
		} else {
			$data['fraud_fraudlabspro_debug_status'] = $this->config->get('fraud_fraudlabspro_debug_status');
		}

		if (isset($this->request->post['fraud_fraudlabspro_sync_status'])) {
			$data['fraud_fraudlabspro_sync_status'] = $this->request->post['fraud_fraudlabspro_sync_status'];
		} else {
			$data['fraud_fraudlabspro_sync_status'] = $this->config->get('fraud_fraudlabspro_sync_status');
		}

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/fraudlabspro/fraud/fraudlabspro', $data));
	}

	public function save(): void {
		$this->load->language('extension/fraudlabspro/fraud/fraudlabspro');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/fraudlabspro/fraud/fraudlabspro')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$this->request->post['fraud_fraudlabspro_key']) {
			$json['error'] = $this->language->get('error_key');
		}

		if (!$json) {
			$this->load->model('setting/setting');

			$this->model_setting_setting->editSetting('fraud_fraudlabspro', $this->request->post);

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function install(): void {
		$this->load->model('extension/fraudlabspro/fraud/fraudlabspro');
		$this->model_extension_fraudlabspro_fraud_fraudlabspro->install();

		$this->load->model('setting/event');
		$event = [
			'code' => 'flp_sync_order_change',
			'trigger' => 'catalog/model/checkout/order/addHistory/after',
			'action' => 'extension/fraudlabspro/fraud/fraudlabspro',
			'description' => 'Event to sync OpenCart order status with FraudLabs Pro status',
			'sort_order' => 1,
			'status' => true
		];
		$this->model_setting_event->addEvent($event);
	}

	public function uninstall(): void {
		$this->load->model('extension/fraudlabspro/fraud/fraudlabspro');
		$this->model_extension_fraudlabspro_fraud_fraudlabspro->uninstall();

		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('flp_sync_order_change');
	}

	public function order(): string {
		$this->load->language('extension/fraudlabspro/fraud/fraudlabspro');

		$this->load->model('extension/fraudlabspro/fraud/fraudlabspro');

		$data['user_token'] = $this->session->data['user_token'];

		// Action of the Approve/Reject/Blacklist button click
		if (isset($this->request->post['flp_id'])){
			if (!isset($this->request->post['user_token']) || !hash_equals($this->session->data['user_token'], $this->request->post['user_token'])) {
				$data['error_warning'] = 'Invalid CSRF Token!';
			} else {
				$flp_status = $this->request->post['new_status'];
				$feedback_note = $this->request->post['feedback_note'];
				$note = urlencode($feedback_note);

				// Feedback FLP status to server
				$fraud_fraudlabspro_key = $this->config->get('fraud_fraudlabspro_key');

				$request = [
					'key'			=> $fraud_fraudlabspro_key,
					'action'		=> $flp_status,
					'id'			=> $this->request->post['flp_id'],
					'note'			=> $note,
					'format'		=> 'json',
					'source'		=> 'opencart',
					'triggered_by'	=> 'manual'
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

				if (strtolower($flp_status) == 'reject_blacklist'){
					$flp_status = "REJECT";
				}
				$data['flp_status'] = $flp_status;

				// Update fraud status into table
				$this->db->query("UPDATE `" . DB_PREFIX . "fraudlabspro` SET fraudlabspro_status = '" . $this->db->escape((string)$flp_status) . "' WHERE order_id = " . (int)$this->request->get['order_id']);

				// Update history record
				if (strtolower($flp_status) == 'approve'){
					$data_temp = array(
						'order_status_id'=>$this->config->get('fraud_fraudlabspro_approve_status_id'),
						'notify'=>0,
						'comment'=>'Approved using FraudLabs Pro.'
					);

					$this->model_extension_fraudlabspro_fraud_fraudlabspro->addOrderHistory($this->request->get['order_id'], $data_temp);
				}
				else if (strtolower($flp_status) == "reject"){
					$data_temp = array(
						'order_status_id'=>$this->config->get('fraud_fraudlabspro_reject_status_id'),
						'notify'=>0,
						'comment'=>'Rejected using FraudLabs Pro.'
					);

					$this->model_extension_fraudlabspro_fraud_fraudlabspro->addOrderHistory($this->request->get['order_id'], $data_temp);
				}
			}
		}

		if (isset($this->request->get['order_id'])) {
			$order_id = $this->request->get['order_id'];
		} else {
			$order_id = 0;
		}

		$fraud_info = $this->model_extension_fraudlabspro_fraud_fraudlabspro->getOrder($order_id);

		if ($fraud_info) {
			$plan_request['key'] = $this->config->get('fraud_fraudlabspro_key');
			$plan_request['format'] = 'json';

			$curl = curl_init();
			curl_setopt($curl, CURLOPT_URL, 'https://api.fraudlabspro.com/v2/plan/result?' . http_build_query($plan_request));
			curl_setopt($curl, CURLOPT_HEADER, 0);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
			curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);

			$plan_response = curl_exec($curl);

			curl_close($curl);

			if (is_null($plan_json = json_decode($plan_response)) === FALSE) {
				$plan_name = $plan_json->plan_name;
			} else {
				$plan_name = '';
			}

			if ($fraud_info['ip_address']) {
				$data['flp_ip_address'] = $fraud_info['ip_address'];
			} else {
				$data['flp_ip_address'] = '';
			}

			if ($fraud_info['ip_usage_type']) {
				$data['flp_ip_usage_type'] = $fraud_info['ip_usage_type'];
			} else {
				$data['flp_ip_usage_type'] = '';
			}

			if ($fraud_info['ip_timezone']) {
				$data['flp_ip_time_zone'] = $fraud_info['ip_timezone'];
			} else {
				$data['flp_ip_time_zone'] = '';
			}

			if ($fraud_info['ip_country']) {
				$data['flp_ip_country'] = $fraud_info['ip_country'];
				$data['flp_ip_region'] = $fraud_info['ip_region'];
				$data['flp_ip_city'] = $fraud_info['ip_city'];
				$data['flp_ip_address'] = $fraud_info['ip_address'];
			} else {
				$data['flp_ip_country'] = '';
			}

			if ($fraud_info['distance_in_mile'] != '-') {
				$data['flp_ip_distance'] = $fraud_info['distance_in_mile'] . " miles";
			} else {
				$data['flp_ip_distance'] = '';
			}

			if ($fraud_info['ip_latitude']) {
				$data['flp_ip_latitude'] = $fraud_info['ip_latitude'];
			} else {
				$data['flp_ip_latitude'] = '';
			}

			if ($fraud_info['ip_longitude']) {
				$data['flp_ip_longitude'] = $fraud_info['ip_longitude'];
			} else {
				$data['flp_ip_longitude'] = '';
			}

			if ($fraud_info['is_high_risk_country']) {
				$data['flp_risk_country'] = $this->parse_fraud_result($fraud_info['is_high_risk_country']);
			} else {
				$data['flp_risk_country'] = '';
			}

			if ($fraud_info['is_free_email']) {
				$data['flp_free_email'] = $this->parse_fraud_result($fraud_info['is_free_email']);
			} else {
				$data['flp_free_email'] = '';
			}

			if ($fraud_info['is_address_ship_forward']) {
				$data['flp_ship_forward'] = $this->parse_fraud_result($fraud_info['is_address_ship_forward']);
			} else {
				$data['flp_ship_forward'] = '';
			}

			if ($fraud_info['is_proxy_ip_address']) {
				$data['flp_using_proxy'] = $this->parse_fraud_result($fraud_info['is_proxy_ip_address']);
			} else {
				$data['flp_using_proxy'] = '';
			}

			if ($fraud_info['is_ip_blacklist']) {
				$data['flp_ip_blacklist'] = $this->parse_fraud_result($fraud_info['is_ip_blacklist']);
			} else {
				$data['flp_ip_blacklist'] = '';
			}

			if ($fraud_info['is_email_blacklist']) {
				$data['flp_email_blacklist'] = $this->parse_fraud_result($fraud_info['is_email_blacklist']);
			} else {
				$data['flp_email_blacklist'] = '';
			}

			if ($fraud_info['is_phone_verified']) {
				$data['flp_phone_verify'] = $this->parse_fraud_result($fraud_info['is_phone_verified']);
			} else {
				$data['flp_phone_verify'] = '-';
			}

			if ($fraud_info['fraudlabspro_score']) {
				$data['flp_score'] = $fraud_info['fraudlabspro_score'];
			} else {
				$data['flp_score'] = '';
			}

			if ($fraud_info['fraudlabspro_status']) {
				$data['flp_status'] = $fraud_info['fraudlabspro_status'];
			} else {
				$data['flp_status'] = '';
			}

			if ($fraud_info['fraudlabspro_message']) {
				$data['flp_message'] = $fraud_info['fraudlabspro_error_code'] . ':' . $fraud_info['fraudlabspro_message'];
			} else {
				$data['flp_message'] = '-';
			}

			if ($fraud_info['fraudlabspro_id']) {
				$data['flp_id'] = $fraud_info['fraudlabspro_id'];
				$data['flp_link'] = $fraud_info['fraudlabspro_id'];
			} else {
				$data['flp_id'] = '';
				$data['flp_link'] = '';
			}

			if (strpos($plan_name, 'Micro')) {
				$data['flp_rules'] = '<span style="color:orange">Available for Mini plan onward. Please <a href="https://www.fraudlabspro.com/merchant/login" target="_blank">upgrade</a>.</span>';
			} elseif ($fraud_info['fraudlabspro_rules']) {
				$data['flp_rules'] = $fraud_info['fraudlabspro_rules'];
			} else {
				$data['flp_rules'] = '-';
			}

			if ($fraud_info['fraudlabspro_credits']) {
				$data['flp_credits'] = $fraud_info['fraudlabspro_credits'];
			} else {
				$data['flp_credits'] = '';
			}

			return $this->load->view('extension/fraudlabspro/fraud/fraudlabspro_info', $data);
		} else {
			return $this->load->view('extension/fraudlabspro/fraud/fraudlabspro_info_none', []);
		}
	}

	private function parse_fraud_result( $result ): string {
		if ( $result == 'Y' )
			return 'Yes';

		if ( $result == 'N' )
			return 'No';

		if ( $result == 'NA' )
			return '-';

		return $result;
	}
}