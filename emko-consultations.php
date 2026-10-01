<?php
/**
 * Plugin Name: Emko Consultations & Telemost Booking
 * Plugin URI:  https://emko.ru
 * Description: Запись на консультации с автоматической интеграцией в Яндекс Календарь, Яндекс Телемост и GetCourse.
 * Version:     1.1.0
 * Author:      ЁМКО
 * Text Domain: emko-consultations
 */

if (!defined('ABSPATH')) {
    exit;
}

define('EMKO_BOOKING_VERSION', '1.1.0');
define('EMKO_BOOKING_DIR', plugin_dir_path(__FILE__));
define('EMKO_BOOKING_URL', plugin_dir_url(__FILE__));

// Подключаем модули (с поддержкой как вложенной, так и плоской структуры)
$emko_inc = file_exists(EMKO_BOOKING_DIR . 'includes/class-caldav.php') ? (EMKO_BOOKING_DIR . 'includes/') : EMKO_BOOKING_DIR;
require_once $emko_inc . 'class-caldav.php';
require_once $emko_inc . 'class-getcourse.php';
require_once $emko_inc . 'class-api.php';
require_once $emko_inc . 'class-admin.php';

if (!class_exists('Emko_Consultations_Plugin')) {
class Emko_Consultations_Plugin {
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        new Emko_Admin_Settings();
        new Emko_Booking_API();

        add_shortcode('emko_booking', array($this, 'render_booking_shortcode'));
        register_activation_hook(__FILE__, array($this, 'on_activate'));
        add_action('emko_send_1h_reminder', array($this, 'handle_1h_reminder'), 10, 2);
    }

    public function handle_1h_reminder($email, $dealId) {
        $gcAccount = get_option('emko_getcourse_account');
        $gcKey     = get_option('emko_getcourse_key');
        if (!empty($gcAccount) && !empty($gcKey)) {
            $apiUrl = "https://" . trim(str_replace(array('https://', 'http://', '.getcourse.ru', '/'), '', $gcAccount)) . ".getcourse.ru/pl/api/deals";
            $params = array(
                'user' => array('email' => $email),
                'system' => array('multiple_offers' => 1),
                'deal' => array(
                    'deal_number' => intval($dealId),
                    'deal_fields' => array(
                        'Статус консультации' => 'Напомнить'
                    )
                )
            );
            $body = array(
                'action' => 'add',
                'key'    => $gcKey,
                'params' => base64_encode(json_encode($params))
            );
            wp_remote_post($apiUrl, array('body' => $body, 'timeout' => 15));
        }
    }

    public function on_activate() {
        // Устанавливаем базовые параметры по умолчанию при активации
        if (!get_option('emko_yandex_email')) {
            update_option('emko_yandex_email', 'eminxx@ya.ru');
        }
        if (!get_option('emko_yandex_app_password')) {
            update_option('emko_yandex_app_password', 'yovntuopdvswhvcb');
        }
        if (!get_option('emko_cached_calendars')) {
            update_option('emko_cached_calendars', array(
                array('href' => '/calendars/eminxx%40ya.ru/events-381685793656155/', 'name' => 'календарь 1'),
                array('href' => '/calendars/eminxx%40ya.ru/events-381685965832239/', 'name' => 'Каелндарь 2'),
                array('href' => '/calendars/eminxx%40ya.ru/events-1469795/', 'name' => 'Мои события')
            ));
        }
        if (!get_option('emko_getcourse_account')) {
            update_option('emko_getcourse_account', 'directinganimationru');
        }
        if (!get_option('emko_getcourse_key')) {
            update_option('emko_getcourse_key', 'qblQwd7zZ1la7NybyYEs0ZBa9P9wkWaIPf0im0Sr9eRuHE4OP2Eli99MetIgrDYne8E824rwgR1lUeQccTCSKDyBmWXlfUbl21UndFk5nVIWLh14QMM6v0q44xupGRar');
        }
        if (!get_option('emko_teachers')) {
            update_option('emko_teachers', array(
                'teacher_1' => array(
                    'id'            => 'teacher_1',
                    'name'          => 'Михаил (Режиссура)',
                    'role'          => 'Режиссер анимации, супервайзер',
                    'calendar_href' => '/calendars/eminxx%40ya.ru/events-381685793656155/',
                    'duration'      => 45,
                    'buffer'        => 10,
                    'active'        => 1,
                    'schedule'      => array(
                        1 => array('active' => 1, 'start' => '11:00', 'end' => '19:00'),
                        2 => array('active' => 1, 'start' => '11:00', 'end' => '19:00'),
                        3 => array('active' => 1, 'start' => '11:00', 'end' => '19:00'),
                        4 => array('active' => 1, 'start' => '11:00', 'end' => '19:00'),
                        5 => array('active' => 1, 'start' => '11:00', 'end' => '19:00'),
                        6 => array('active' => 0, 'start' => '12:00', 'end' => '17:00'),
                        7 => array('active' => 0, 'start' => '12:00', 'end' => '17:00'),
                    )
                ),
                'teacher_2' => array(
                    'id'            => 'teacher_2',
                    'name'          => 'Анна (Арт-дирекшн)',
                    'role'          => 'Концепт-художник, арт-директор',
                    'calendar_href' => '/calendars/eminxx%40ya.ru/events-381685965832239/',
                    'duration'      => 45,
                    'buffer'        => 10,
                    'active'        => 1,
                    'schedule'      => array(
                        1 => array('active' => 1, 'start' => '12:00', 'end' => '18:00'),
                        2 => array('active' => 1, 'start' => '12:00', 'end' => '18:00'),
                        3 => array('active' => 1, 'start' => '12:00', 'end' => '18:00'),
                        4 => array('active' => 1, 'start' => '12:00', 'end' => '18:00'),
                        5 => array('active' => 1, 'start' => '12:00', 'end' => '18:00'),
                        6 => array('active' => 0, 'start' => '12:00', 'end' => '17:00'),
                        7 => array('active' => 0, 'start' => '12:00', 'end' => '17:00'),
                    )
                )
            ));
        }
    }

    public function render_booking_shortcode($atts) {
        $assets_url = file_exists(EMKO_BOOKING_DIR . 'assets/booking-widget.css') ? (EMKO_BOOKING_URL . 'assets/') : EMKO_BOOKING_URL;

        wp_enqueue_style(
            'emko-booking-css',
            $assets_url . 'booking-widget.css',
            array(),
            EMKO_BOOKING_VERSION
        );

        wp_enqueue_script(
            'emko-booking-js',
            $assets_url . 'booking-widget.js',
            array(),
            EMKO_BOOKING_VERSION,
            true
        );

        wp_localize_script('emko-booking-js', 'emkoBookingConfig', array(
            'apiUrl' => esc_url_raw(rest_url('emko-booking/v1'))
        ));

        ob_start();
        ?>
        <div class="emko-booking-widget">
            <!-- Step 0: Gate: Access Only via Paid Order from Email -->
            <div id="emko-step-gate" class="emko-step">
                <div class="emko-gate-card">
                    <div class="emko-gate-icon">🔒</div>
                    <div class="emko-header">
                        <h3>Запись доступна после оплаты консультации</h3>
                        <p>Для записи требуется подтвержденный заказ</p>
                    </div>
                    <p class="emko-gate-desc">
                        Чтобы выбрать дату и время консультации, пожалуйста, <strong>перейдите по персональной ссылке из письма с подтверждением оплаты</strong>. Мы отправили его на вашу электронную почту сразу после оформления заказа на сайте.
                    </p>
                    <div class="emko-gate-notice">
                        💡 <strong>Уже оплатили, но не нашли письмо?</strong><br>
                        Проверьте папку «Спам» или «Промоакции» в вашей почте. Если письмо не пришло, свяжитесь со службой заботы Академии ЁМКО, и мы сразу отправим вам прямую ссылку.
                    </div>
                    <div style="margin-top:24px;">
                        <a href="https://emko.academy/consultations/" class="emko-btn-primary" style="display:inline-block;text-decoration:none;padding:12px 28px;">
                            Перейти в каталог консультаций
                        </a>
                    </div>
                </div>
            </div>

            <!-- Step 1: Select Teacher -->
            <div id="emko-step-teacher" class="emko-step">
                <div class="emko-header">
                    <h3>Запись на консультацию</h3>
                    <p>Выберите преподавателя, к которому хотите записаться</p>
                </div>
                <div class="emko-teacher-list">
                    <p class="emko-empty-slots">Загрузка преподавателей...</p>
                </div>
            </div>

            <!-- Step 2: Date & Slot -->
            <div id="emko-step-datetime" class="emko-step">
                <button type="button" class="emko-btn-back" id="emko-back-to-teacher">← Выбрать другого преподавателя</button>
                <div class="emko-header">
                    <h3 id="emko-selected-teacher-name">Преподаватель</h3>
                    <p id="emko-selected-teacher-meta">Длительность: 45 минут</p>
                </div>
                
                <div class="emko-date-strip"></div>
                
                <!-- Timezone Bar -->
                <div class="emko-tz-bar" id="emko-tz-bar"></div>

                <div class="emko-slots-title">Доступное время:</div>
                <div class="emko-slots-grid"></div>
            </div>

            <!-- Step 3: Contact Form & Confirmation -->
            <div id="emko-step-form" class="emko-step">
                <button type="button" class="emko-btn-back" id="emko-back-to-slots">← Выбрать другое время</button>
                <div class="emko-header">
                    <h3>Подтверждение записи</h3>
                    <p id="emko-form-details">Детали встречи</p>
                </div>
                
                <div id="emko-deal-badge" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:8px;padding:8px 12px;font-size:13px;margin-bottom:14px;font-weight:600;"></div>

                <form id="emko-booking-form">
                    <div class="emko-form-group">
                        <label>Ваше Имя и Фамилия *</label>
                        <input type="text" id="emko-input-name" required placeholder="Иван Иванов" />
                    </div>
                    <div class="emko-form-group">
                        <label>Электронная почта *</label>
                        <input type="email" id="emko-input-email" required placeholder="ivan@example.com" />
                    </div>
                    <div class="emko-form-group">
                        <label>Номер телефона *</label>
                        <input type="tel" id="emko-input-phone" required placeholder="+7 (999) 000-00-00" />
                    </div>
                    <div class="emko-form-group">
                        <label>Тема или вопрос к консультации (необязательно)</label>
                        <textarea id="emko-input-note" rows="3" placeholder="О чем хотите поговорить на встрече?"></textarea>
                    </div>
                    <button type="submit" id="emko-submit-btn" class="emko-btn-primary">Подтвердить запись</button>
                </form>
            </div>

            <!-- Step 4: Success Screen -->
            <div id="emko-step-success" class="emko-step">
                <div class="emko-success-card">
                    <div class="emko-success-icon">✓</div>
                    <h3 style="margin:0 0 6px 0;">Вы успешно записаны!</h3>
                    <p style="color:#6b7280;margin:0 0 16px 0;">Подтверждение отправлено на ваш email</p>

                    <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;padding:16px;text-align:left;margin-bottom:16px;">
                        <div style="margin-bottom:8px;">Преподаватель: <strong id="emko-success-teacher"></strong></div>
                        <div style="margin-bottom:8px;">Дата и время: <strong id="emko-success-date"></strong> (по МСК)</div>
                        <div style="font-size:13px;color:#6b7280;">Формат: Онлайн-консультация в Яндекс Телемост</div>
                    </div>

                    <div style="text-align:left;margin-bottom:16px;">
                        <label style="font-size:12px;font-weight:600;color:#4b5563;">Персональная ссылка на видеовстречу:</label>
                        <div style="display:flex;gap:8px;margin-top:6px;">
                            <input type="text" id="emko-success-telemost-url" readonly style="flex:1;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;" />
                            <button type="button" id="emko-btn-copy" style="padding:10px 16px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;white-space:nowrap;">Копировать</button>
                        </div>
                    </div>

                    <div style="display:flex;flex-direction:column;gap:10px;margin-top:14px;">
                        <a href="#" id="emko-btn-google-cal" target="_blank" class="emko-btn-primary" style="background:#0284c7;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;">
                            🌐 Открыть в Google Календаре
                        </a>
                        <a href="#" id="emko-btn-download-ics" class="emko-btn-primary" style="background:#4b5563;font-size:13px;padding:10px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;">
                            📱 Скачать файл для Apple / Outlook (.ics)
                        </a>
                    </div>

                    <div style="margin-top:16px;padding:12px;background:#fefce8;border:1px solid #fef08a;border-radius:8px;font-size:12px;color:#854d0e;text-align:left;">
                        💡 <strong>Если вы пользуетесь Яндекс Календарем:</strong> мы автоматически отправили приглашение на вашу почту. В письме от Яндекса просто нажмите <strong>«Принять»</strong>, и встреча появится в вашем Яндекс Календаре!
                    </div>

                    <p style="font-size:12px;color:#9ca3af;margin:16px 0 0 0;">
                        Перед началом встречи вам придёт автоматическое напоминание со ссылкой на видеозвонок.
                    </p>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
}

Emko_Consultations_Plugin::get_instance();
