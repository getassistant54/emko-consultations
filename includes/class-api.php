<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Emko_Booking_API')) {
class Emko_Booking_API {
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        register_rest_route('emko-booking/v1', '/teachers', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_teachers'),
            'permission_callback' => '__return_true'
        ));

        register_rest_route('emko-booking/v1', '/slots', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'get_slots'),
            'permission_callback' => '__return_true'
        ));

        register_rest_route('emko-booking/v1', '/book', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'create_booking'),
            'permission_callback' => '__return_true'
        ));
    }

    public function get_teachers($request) {
        $teachers = get_option('emko_teachers', array());
        $sanitized = array();

        foreach ($teachers as $id => $t) {
            if (!empty($t['active'])) {
                $sanitized[] = array(
                    'id'          => $id,
                    'name'        => esc_html($t['name']),
                    'role'        => esc_html($t['role'] ?? ''),
                    'duration'    => intval($t['duration'] ?? 45),
                    'description' => esc_html($t['description'] ?? '')
                );
            }
        }
        return rest_ensure_response($sanitized);
    }

    public function get_slots($request) {
        $teacherId = sanitize_text_field($request->get_param('teacher_id'));
        $dateStr   = sanitize_text_field($request->get_param('date')); // YYYY-MM-DD

        if (empty($teacherId) || empty($dateStr) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return new WP_Error('bad_request', 'Некорректные параметры запроса', array('status' => 400));
        }

        $teachers = get_option('emko_teachers', array());
        if (!isset($teachers[$teacherId])) {
            return new WP_Error('not_found', 'Преподаватель не найден', array('status' => 404));
        }

        $teacher = $teachers[$teacherId];
        $calHref = $teacher['calendar_href'] ?? '';
        $duration = intval($teacher['duration'] ?? 45); // минуты
        $buffer   = intval($teacher['buffer'] ?? 10);   // минуты
        $schedule = $teacher['schedule'] ?? array();

        // День недели (1 = Пн, 7 = Вс)
        $dayOfWeek = date('N', strtotime($dateStr));
        $dayConfig = $schedule[$dayOfWeek] ?? null;

        if (!$dayConfig || empty($dayConfig['active'])) {
            return rest_ensure_response(array('slots' => array(), 'message' => 'В этот день нет приёма'));
        }

        // Получаем занятые интервалы из Яндекса
        $yandexEmail = get_option('emko_yandex_email');
        $yandexPass  = get_option('emko_yandex_app_password');

        $busyIntervals = array();
        if (!empty($yandexEmail) && !empty($yandexPass) && !empty($calHref)) {
            $calClient = new Emko_CalDAV_Client($yandexEmail, $yandexPass);
            $dayStartIso = $dateStr . ' 00:00:00';
            $dayEndIso   = $dateStr . ' 23:59:59';
            $busyIntervals = $calClient->get_busy_intervals($calHref, $dayStartIso, $dayEndIso);
        }

        // Поддержка перерыва/исключения внутри дня (например, с 12:00 до 15:00)
        if (!empty($dayConfig['break_start']) && !empty($dayConfig['break_end'])) {
            $busyIntervals[] = array(
                'start' => strtotime($dateStr . ' ' . $dayConfig['break_start']),
                'end'   => strtotime($dateStr . ' ' . $dayConfig['break_end'])
            );
        }

        // Собираем рабочие периоды дня (Период 1, Период 2 или массив periods)
        $workIntervals = array();
        if (!empty($dayConfig['periods']) && is_array($dayConfig['periods'])) {
            $workIntervals = $dayConfig['periods'];
        } else {
            $wStart1 = $dayConfig['start'] ?? '10:00';
            $wEnd1   = $dayConfig['end'] ?? '18:00';
            if (!empty($wStart1) && !empty($wEnd1)) {
                $workIntervals[] = array('start' => $wStart1, 'end' => $wEnd1);
            }
            if (!empty($dayConfig['start2']) && !empty($dayConfig['end2'])) {
                $workIntervals[] = array('start' => $dayConfig['start2'], 'end' => $dayConfig['end2']);
            }
        }

        // Генерируем слоты в таймзоне сайта
        $tzOffset    = get_option('gmt_offset', 3) * 3600; // по умолчанию МСК (UTC+3)
        $stepSeconds = ($duration + $buffer) * 60;
        $nowTs       = time() + $tzOffset;

        $slots = array();
        $seenSlots = array();

        foreach ($workIntervals as $interval) {
            $wStartTs = strtotime($dateStr . ' ' . $interval['start']);
            $wEndTs   = strtotime($dateStr . ' ' . $interval['end']);

            for ($slotStart = $wStartTs; $slotStart + ($duration * 60) <= $wEndTs; $slotStart += $stepSeconds) {
                $slotEnd = $slotStart + ($duration * 60);

                // Фильтр: не показываем прошедшие слоты (плюс запас 1 час)
                if ($slotStart <= ($nowTs + 3600)) {
                    continue;
                }

                // Проверка на пересечение с событиями в Яндекс Календаре или перерывом
                $isBusy = false;
                foreach ($busyIntervals as $busy) {
                    if ($slotStart < $busy['end'] && $slotEnd > $busy['start']) {
                        $isBusy = true;
                        break;
                    }
                }

                $slotTime = date('H:i', $slotStart);
                if (!$isBusy && !isset($seenSlots[$slotTime])) {
                    $seenSlots[$slotTime] = true;
                    $slots[] = array(
                        'time'      => $slotTime,
                        'timestamp' => $slotStart,
                        'duration'  => $duration
                    );
                }
            }
        }

        return rest_ensure_response(array('slots' => $slots));
    }

    public function create_booking($request) {
        $params = $request->get_json_params();
        if (empty($params)) {
            $params = $request->get_params();
        }

        $teacherId    = sanitize_text_field($params['teacher_id'] ?? '');
        $slotTs       = intval($params['timestamp'] ?? 0);
        $studentName  = sanitize_text_field($params['name'] ?? '');
        $studentEmail = sanitize_email($params['email'] ?? '');
        $studentPhone = sanitize_text_field($params['phone'] ?? '');
        $note         = sanitize_textarea_field($params['note'] ?? '');

        if (empty($teacherId) || empty($slotTs) || empty($studentEmail) || empty($studentName)) {
            return new WP_Error('missing_fields', 'Заполните все обязательные поля', array('status' => 400));
        }

        $teachers = get_option('emko_teachers', array());
        if (!isset($teachers[$teacherId])) {
            return new WP_Error('not_found', 'Преподаватель не найден', array('status' => 404));
        }

        $teacher = $teachers[$teacherId];
        $calHref = $teacher['calendar_href'] ?? '';
        $duration = intval($teacher['duration'] ?? 45);

        $yandexEmail = get_option('emko_yandex_email');
        $yandexPass  = get_option('emko_yandex_app_password');

        if (empty($yandexEmail) || empty($yandexPass) || empty($calHref)) {
            return new WP_Error('config_error', 'Настройки календаря не заданы на сервере', array('status' => 500));
        }

        // 1. Создаем событие в Яндекс Календаре с Телемостом
        $calClient = new Emko_CalDAV_Client($yandexEmail, $yandexPass);
        $bookingResult = $calClient->create_booking(
            $calHref,
            $slotTs,
            $duration,
            $studentName,
            $studentEmail,
            $studentPhone,
            $note
        );

        if (!$bookingResult['success']) {
            return new WP_Error('caldav_error', $bookingResult['error'], array('status' => 500));
        }

        $dealId       = sanitize_text_field($params['deal_id'] ?? '');
        $telemostUrl = $bookingResult['telemost_url'];
        $datetimeStr = date('d.m.Y H:i', $slotTs);

        // 2. Отправляем в GetCourse (обновляем сделку)
        $gcAccount = get_option('emko_getcourse_account');
        $gcKey     = get_option('emko_getcourse_key');
        if (!empty($gcAccount) && !empty($gcKey)) {
            $gcClient = new Emko_GetCourse_Client($gcAccount, $gcKey);
            $gcClient->update_deal_booking($dealId, $studentEmail, $telemostUrl, $datetimeStr, $teacher['name']);

            // Планируем отправку напоминания за 1 час через wp_schedule_single_event
            $remindTs = $slotTs - 3600;
            if ($remindTs > time()) {
                wp_schedule_single_event($remindTs, 'emko_send_1h_reminder', array($studentEmail, $dealId));
            }
        }

        // Сохраняем в локальный журнал бронирований WordPress
        $logs = get_option('emko_booking_logs', array());
        $logs[] = array(
            'id'           => $bookingResult['uid'],
            'deal_id'      => $dealId,
            'datetime'     => $datetimeStr,
            'teacher'      => $teacher['name'],
            'student_name' => $studentName,
            'email'        => $studentEmail,
            'phone'        => $studentPhone,
            'telemost_url' => $telemostUrl,
            'created_at'   => current_time('mysql')
        );
        update_option('emko_booking_logs', array_slice($logs, -500)); // храним последние 500 записей

        // 3. Отправляем email подтверждения ученику и админу
        $subject = 'Подтверждение записи на консультацию: ' . $teacher['name'];
        $message = "Здравствуйте, {$studentName}!\n\n" .
                   "Вы успешно записаны на консультацию к преподавателю: {$teacher['name']}.\n" .
                   "Дата и время: {$datetimeStr} (по МСК)\n" .
                   "Ссылка на видеовстречу (Яндекс Телемост): {$telemostUrl}\n\n" .
                   "До встречи на консультации!";
        wp_mail($studentEmail, $subject, $message);

        return rest_ensure_response(array(
            'success'      => true,
            'message'      => 'Вы успешно записаны!',
            'telemost_url' => $telemostUrl,
            'datetime'     => $datetimeStr,
            'teacher_name' => $teacher['name']
        ));
    }
}
}

