<?php
if (!defined('ABSPATH')) {
    exit;
}

class Emko_CalDAV_Client {
    private $email;
    private $password;
    private $auth;
    private $baseUrl = 'https://caldav.yandex.ru';

    public function __construct($email, $password) {
        $this->email = trim($email);
        $this->password = trim($password);
        $this->auth = base64_encode($this->email . ':' . $this->password);
    }

    private function request($path, $method = 'PROPFIND', $body = '', $headers = array()) {
        $url = strpos($path, 'http') === 0 ? $path : $this->baseUrl . $path;
        
        $defaultHeaders = array(
            'Authorization' => 'Basic ' . $this->auth,
            'Content-Type'  => 'application/xml; charset=utf-8'
        );
        $allHeaders = array_merge($defaultHeaders, $headers);

        $response = wp_remote_request($url, array(
            'method'  => $method,
            'headers' => $allHeaders,
            'body'    => $body,
            'timeout' => 20
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        return array(
            'success' => ($code >= 200 && $code < 300),
            'status'  => $code,
            'body'    => $body
        );
    }

    /**
     * Проверить подключение и авторизацию к Яндекс CalDAV
     */
    public function test_connection() {
        $homePath = '/calendars/' . rawurlencode($this->email) . '/';
        $xml = '<?xml version="1.0" encoding="utf-8" ?>
<D:propfind xmlns:D="DAV:">
  <D:prop>
    <D:displayname/>
  </D:prop>
</D:propfind>';
        $res = $this->request($homePath, 'PROPFIND', $xml, array('Depth' => '0'));
        if (!$res['success']) {
            if (isset($res['status']) && $res['status'] === 401) {
                return array('success' => false, 'error' => 'Ошибка 401 Unauthorized: неверный логин или пароль приложения.');
            }
            return array('success' => false, 'error' => !empty($res['error']) ? $res['error'] : ('Ошибка сервера CalDAV (HTTP ' . ($res['status'] ?? 'неизвестно') . ')'));
        }
        return array('success' => true);
    }

    /**
     * Получить список всех календарей в аккаунте
     */
    public function get_calendars() {
        $homePath = '/calendars/' . rawurlencode($this->email) . '/';
        $xml = '<?xml version="1.0" encoding="utf-8" ?>
<D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:prop>
    <D:displayname/>
    <D:resourcetype/>
    <C:supported-calendar-component-set/>
  </D:prop>
</D:propfind>';

        $res = $this->request($homePath, 'PROPFIND', $xml, array('Depth' => '1'));
        if (!$res['success']) {
            return array();
        }

        $calendars = array();
        if (preg_match_all('/<D:response>(.*?)<\/D:response>/is', $res['body'], $responses)) {
            foreach ($responses[1] as $respXml) {
                // Ищем только календари с VEVENT
                if (strpos($respXml, 'VEVENT') === false) {
                    continue;
                }
                preg_match('/<D:href>(.*?)<\/D:href>/is', $respXml, $hrefMatch);
                preg_match('/<D:displayname>(.*?)<\/D:displayname>/is', $respXml, $nameMatch);

                if (!empty($hrefMatch[1])) {
                    $href = trim($hrefMatch[1]);
                    $name = !empty($nameMatch[1]) ? trim($nameMatch[1]) : basename(rtrim($href, '/'));
                    $calendars[] = array(
                        'href' => $href,
                        'name' => html_entity_decode($name, ENT_QUOTES, 'UTF-8')
                    );
                }
            }
        }
        return $calendars;
    }

    /**
     * Получить занятые интервалы из календаря за конкретный период
     */
    public function get_busy_intervals($calHref, $startDate, $endDate) {
        $startIso = gmdate('Ymd\THis\Z', strtotime($startDate));
        $endIso = gmdate('Ymd\THis\Z', strtotime($endDate));

        $xml = '<?xml version="1.0" encoding="utf-8" ?>
<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:prop>
    <C:calendar-data/>
  </D:prop>
  <C:filter>
    <C:comp-filter name="VCALENDAR">
      <C:comp-filter name="VEVENT">
        <C:time-range start="' . $startIso . '" end="' . $endIso . '"/>
      </C:comp-filter>
    </C:comp-filter>
  </C:filter>
</C:calendar-query>';

        $res = $this->request($calHref, 'REPORT', $xml, array('Depth' => '1'));
        if (!$res['success']) {
            return array();
        }

        $intervals = array();
        if (preg_match_all('/BEGIN:VEVENT([\s\S]*?)END:VEVENT/i', $res['body'], $events)) {
            foreach ($events[1] as $ev) {
                // Извлекаем DTSTART и DTEND
                preg_match('/DTSTART[^:]*:(.*?)\r?\n/', $ev, $sMatch);
                preg_match('/DTEND[^:]*:(.*?)\r?\n/', $ev, $eMatch);

                if (!empty($sMatch[1])) {
                    $startTs = $this->parse_ics_date($sMatch[1]);
                    $endTs = !empty($eMatch[1]) ? $this->parse_ics_date($eMatch[1]) : ($startTs + 3600);
                    $intervals[] = array(
                        'start' => $startTs,
                        'end'   => $endTs
                    );
                }
            }
        }
        return $intervals;
    }

    private function parse_ics_date($dateStr) {
        $dateStr = trim($dateStr);
        // Если формат YYYYMMDDTHHMMSSZ (UTC)
        if (substr($dateStr, -1) === 'Z') {
            return strtotime(substr($dateStr, 0, 4) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 6, 2) . ' ' .
                             substr($dateStr, 9, 2) . ':' . substr($dateStr, 11, 2) . ':' . substr($dateStr, 13, 2) . ' UTC');
        }
        // Если формат локального времени
        return strtotime($dateStr);
    }

    /**
     * Создать консультацию в календаре со ссылкой на Телемост
     */
    public function create_booking($calHref, $startTs, $durationMinutes, $studentName, $studentEmail, $studentPhone, $note = '') {
        $endTs = $startTs + ($durationMinutes * 60);
        $uid = wp_generate_uuid4();
        
        // Генерация 14-значного ID комнаты Телемоста
        $telemostRoom = '25' . str_pad((string)mt_rand(100000000000, 999999999999), 12, '0', STR_PAD_LEFT);
        $telemostUrl = 'https://telemost.yandex.ru/j/' . $telemostRoom;

        $dtStart = gmdate('Ymd\THis\Z', $startTs);
        $dtEnd   = gmdate('Ymd\THis\Z', $endTs);
        $dtStamp = gmdate('Ymd\THis\Z');

        $summary = 'Консультация: ' . $studentName;
        $description = "Ссылка на видеовстречу: " . $telemostUrl . "\\n" .
                       "Ученик: " . $studentName . "\\n" .
                       "Email: " . $studentEmail . "\\n" .
                       "Телефон: " . $studentPhone . "\\n" .
                       (!empty($note) ? "Комментарий: " . $note . "\\n" : "");

        $ics = "BEGIN:VCALENDAR\r\n" .
               "VERSION:2.0\r\n" .
               "PRODID:-//Emko Consultations//RU\r\n" .
               "CALSCALE:GREGORIAN\r\n" .
               "BEGIN:VEVENT\r\n" .
               "UID:{$uid}\r\n" .
               "DTSTAMP:{$dtStamp}\r\n" .
               "DTSTART:{$dtStart}\r\n" .
               "DTEND:{$dtEnd}\r\n" .
               "SUMMARY:{$summary}\r\n" .
               "DESCRIPTION:{$description}\r\n" .
               "CONFERENCE;FEATURE=VIDEO;VALUE=URI:{$telemostUrl}\r\n" .
               "X-TELEMOST-CONFERENCE:{$telemostUrl}\r\n" .
               "ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE;CN={$studentName}:mailto:{$studentEmail}\r\n" .
               "STATUS:CONFIRMED\r\n" .
               "TRANSP:OPAQUE\r\n" .
               "END:VEVENT\r\n" .
               "END:VCALENDAR";

        $eventPath = rtrim($calHref, '/') . '/' . $uid . '.ics';
        $res = $this->request($eventPath, 'PUT', $ics, array(
            'Content-Type' => 'text/calendar; charset=utf-8'
        ));

        if ($res['success']) {
            return array(
                'success'      => true,
                'telemost_url' => $telemostUrl,
                'start_time'   => date('Y-m-d H:i:s', $startTs),
                'end_time'     => date('Y-m-d H:i:s', $endTs),
                'uid'          => $uid
            );
        }

        return array(
            'success' => false,
            'error'   => 'Не удалось создать событие в календаре: ' . ($res['error'] ?? 'Код ' . $res['status'])
        );
    }
}
