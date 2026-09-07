<?php
namespace Opencart\Catalog\Controller\Extension\Fraudlabspro\Event;

class Fraudlabspro extends \Opencart\System\Engine\Controller {
    public function beforeLogin(string &$route, array &$args, mixed &$output = null): void {
        if (!$this->config->get('fraud_fraudlabspro_status') || !$this->config->get('fraud_fraudlabspro_enable_ato') || !$this->config->get('fraud_fraudlabspro_key')) {
            return;
        }

        $email = $this->request->post['email'] ?? '';

        if (!$email) {
            return;
        }

        if (filter_var($this->config->get('fraud_fraudlabspro_simulate_ip'), FILTER_VALIDATE_IP)) {
            $ip = $this->config->get('fraud_fraudlabspro_simulate_ip');
        } else {
            $ip = oc_get_ip();
        }

        $this->load->model('account/customer');
        $customer_info = $this->model_account_customer->getCustomerByEmail($email);

        $flp_payload = [
            'key'        => $this->config->get('fraud_fraudlabspro_key'),
            'email'      => $email,
            'ip'         => $ip,
            'first_name' => $customer_info['firstname'] ?? '',
            'last_name'  => $customer_info['lastname'] ?? '',
            'phone'      => $customer_info['telephone'] ?? '',
        ];
		
		// FLP Agent Javascript
		if (isset($_COOKIE['flp_checksum'])) {
			$flp_checksum = htmlspecialchars($_COOKIE['flp_checksum'], ENT_COMPAT, 'UTF-8');
			$flp_payload['flp_checksum'] = $flp_checksum;
		}
		
		$response = $this->screen_user($flp_payload);

		if (is_null($json = json_decode($response)) === FALSE) {
			if (isset($json->user_transaction_status) && $json->user_transaction_status === 'REJECT') {
				$rejection = [
					'error' => [
						'warning' => $this->language->get('error_login_flp_reject')
					]
				];

				$this->response->addHeader('Content-Type: application/json');
				$this->response->setOutput(json_encode($rejection));
				$this->response->output();
				exit;
			}
		} else {
			$this->write_debug_log('Transaction for ' . $email . ' data contains invalid value.');
		}
    }
	
	
    public function beforeRegister(string &$route, array &$args, mixed &$output = null): void {
        if (!$this->config->get('fraud_fraudlabspro_status') || !$this->config->get('fraud_fraudlabspro_key')) {
            return;
        }

        $email = $this->request->post['email'] ?? '';
        $firstname = $this->request->post['firstname'] ?? '';
        $lastname = $this->request->post['lastname'] ?? '';
        $telephone = $this->request->post['telephone'] ?? '';

        if (!$email) {
            return;
        }

        if (filter_var($this->config->get('fraud_fraudlabspro_simulate_ip'), FILTER_VALIDATE_IP)) {
            $ip = $this->config->get('fraud_fraudlabspro_simulate_ip');
        } else {
            $ip = oc_get_ip();
        }

        $flp_payload = [
            'key'        => $this->config->get('fraud_fraudlabspro_key'),
            'email'      => $email,
            'ip'         => $ip,
            'first_name' => $firstname ?? '',
            'last_name'  => $lastname ?? '',
            'phone'      => $telephone ?? '',
        ];
		
		// FLP Agent Javascript
		if (isset($_COOKIE['flp_checksum'])) {
			$flp_checksum = htmlspecialchars($_COOKIE['flp_checksum'], ENT_COMPAT, 'UTF-8');
			$flp_payload['flp_checksum'] = $flp_checksum;
		}
		
		$response = $this->screen_user($flp_payload);

		if (is_null($json = json_decode($response)) === FALSE) {
			if (isset($json->user_transaction_status) && $json->user_transaction_status === 'REJECT') {
				$rejection = [
					'error' => [
						'warning' => $this->language->get('error_register_flp_reject')
						// 'warning' => "Warning!" // or your own custom message
					]
				];

				$this->response->addHeader('Content-Type: application/json');
				$this->response->setOutput(json_encode($rejection));
				$this->response->output();
				exit;
			}
		} else {
			$this->write_debug_log('Transaction for ' . $email . ' data contains invalid value.');
		}
    }
	
    public function beforeUpdatePassword(string &$route, array &$args, mixed &$output = null): void {
        if (!$this->config->get('fraud_fraudlabspro_status') || !$this->config->get('fraud_fraudlabspro_key')) {
            return;
        }
		$email = $this->customer->getEmail();

        if (filter_var($this->config->get('fraud_fraudlabspro_simulate_ip'), FILTER_VALIDATE_IP)) {
            $ip = $this->config->get('fraud_fraudlabspro_simulate_ip');
        } else {
            $ip = oc_get_ip();
        }
		
		$this->load->model('account/customer');
        $customer_info = $this->model_account_customer->getCustomerByEmail($email);

        $flp_payload = [
            'key'        => $this->config->get('fraud_fraudlabspro_key'),
            'email'      => $email,
            'ip'         => $ip,
            'first_name' => $customer_info['firstname'] ?? '',
            'last_name'  => $customer_info['lastname'] ?? '',
            'phone'      => $customer_info['telephone'] ?? '',
        ];
		
		// FLP Agent Javascript
		if (isset($_COOKIE['flp_checksum'])) {
			$flp_checksum = htmlspecialchars($_COOKIE['flp_checksum'], ENT_COMPAT, 'UTF-8');
			$flp_payload['flp_checksum'] = $flp_checksum;
		}
		
		$response = $this->screen_user($flp_payload);

		if (is_null($json = json_decode($response)) === FALSE) {
			if (isset($json->user_transaction_status) && $json->user_transaction_status === 'REJECT') {
				$this->load->language('extension/fraudlabspro/event/fraudlabspro');
				$rejection = [
					'error' => [
						'warning' => $this->language->get('error_change_password_flp_reject')
						// 'warning' => "Warning!" // or your own custom message
					]
				];

				$this->response->addHeader('Content-Type: application/json');
				$this->response->setOutput(json_encode($rejection));
				$this->response->output();
				exit;
			}
		} else {
			$this->write_debug_log('Transaction for ' . $email . ' data contains invalid value.');
		}
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
	
	private function screen_user(array $payloads = []): array|string {
		
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, 'https://api.fraudlabspro.com/v2/user/screen');
		curl_setopt($ch, CURLOPT_FAILONERROR, false);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
		curl_setopt($ch, CURLOPT_HTTP_VERSION, '1.1');
		curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, (is_array($payloads)) ? http_build_query($payloads) : $payloads);

		$response = curl_exec($ch);

		curl_close($ch);

		if (!curl_errno($ch)) {
			return $response;
		}

		return false;
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