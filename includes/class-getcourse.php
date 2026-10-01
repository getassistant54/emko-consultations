<?php
if (!defined('ABSPATH')) {
    exit;
}

class Emko_GetCourse_Client {
    private $account;
    private $secretKey;

    public function __construct($account, $secretKey) {
        $this->account = trim(str_replace(array('https://', 'http://', '.getcourse.ru', '/'), '', $account));
        $this->secretKey = trim($secretKey);
    }

    /**
     * Обновить заказ в GetCourse и записать туда данные встречи
     */
    public function update_deal_booking($dealId, $email, $telemostUrl, $datetimeStr, $teacherName) {
        if (empty($this->account) || empty($this->secretKey)) {
            return array('success' => false, 'error' => 'GetCourse API не настроен');
        }

        $apiUrl = "https://{$this->account}.getcourse.ru/pl/api/deals";

        $dealData = array(
            'deal_fields' => array(
                'Дата и время консультации' => $datetimeStr,
                'Преподаватель'             => $teacherName,
                'Ссылка на Телемост'        => $telemostUrl,
                'Статус консультации'       => 'Записан'
            )
        );

        if (!empty($dealId)) {
            $dealData['deal_number'] = intval($dealId);
        }

        $params = array(
            'user' => array(
                'email' => $email
            ),
            'system' => array(
                'multiple_offers' => 1
            ),
            'deal' => $dealData
        );

        $body = array(
            'action' => 'add',
            'key'    => $this->secretKey,
            'params' => base64_encode(json_encode($params))
        );

        $response = wp_remote_post($apiUrl, array(
            'body'    => $body,
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $resBody = json_decode(wp_remote_retrieve_body($response), true);
        return array('success' => true, 'response' => $resBody);
    }

    /**
     * Проверить валидность ключа и связи с GetCourse API
     */
    public function test_connection() {
        if (empty($this->account) || empty($this->secretKey)) {
            return array('success' => false, 'error' => 'Не указан аккаунт или секретный ключ GetCourse.');
        }

        $apiUrl = "https://{$this->account}.getcourse.ru/pl/api/deals";
        $params = array(
            'user' => array('email' => 'test_api_check@domain.com'),
            'deal' => array('deal_number' => 999999999)
        );
        $body = array(
            'action' => 'add',
            'key'    => $this->secretKey,
            'params' => base64_encode(json_encode($params))
        );

        $response = wp_remote_post($apiUrl, array(
            'body'    => $body,
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $resBody = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && is_array($resBody)) {
            if (isset($resBody['success']) && $resBody['success'] === false && isset($resBody['error'])) {
                return array('success' => false, 'error' => $resBody['error']);
            }
            return array('success' => true);
        }

        return array('success' => false, 'error' => 'Некорректный ответ от GetCourse (HTTP ' . $code . ')');
    }
}
