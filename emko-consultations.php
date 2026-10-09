<?php
/**
 * Plugin Name: Emko Consultations & Telemost Booking
 * Plugin URI:  https://emko.academy
 * Description: Запись на консультации с автоматической интеграцией в Яндекс Календарь, Яндекс Телемост и GetCourse.
 * Version:     1.3.2
 * Author:      ЁМКО
 * Text Domain: emko-consultations
 */

if (!defined('ABSPATH')) {
    exit;
}

define('EMKO_BOOKING_VERSION', '1.3.2');
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
        add_filter('request', array($this, 'prevent_wp_name_query_var_conflict'));
        add_action('wp_footer', array($this, 'inject_site_enhancements'));

        // Кнопки управления и индикатор версии в списке плагинов WP
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_plugin_action_links'));
        add_filter('plugin_row_meta', array($this, 'add_plugin_row_meta'), 10, 2);
    }

    public function add_plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=emko-consultations') . '" style="font-weight:600;color:#0284c7;">⚙️ Настройки</a>';
        $upload_link   = '<a href="' . admin_url('plugin-install.php?tab=upload') . '" style="font-weight:600;color:#10b981;">⬆️ Обновить плагин (загрузить zip)</a>';
        array_unshift($links, $upload_link);
        array_unshift($links, $settings_link);
        return $links;
    }

    public function add_plugin_row_meta($links, $file) {
        if ($file === plugin_basename(__FILE__)) {
            $links[] = '<strong style="color:#059669;background:#ecfdf5;padding:2px 8px;border-radius:12px;border:1px solid #a7f3d0;">🟢 Установлена версия: v' . EMKO_BOOKING_VERSION . '</strong>';
        }
        return $links;
    }

    public function prevent_wp_name_query_var_conflict($query_vars) {
        if (isset($query_vars['name']) && (isset($query_vars['pagename']) || isset($_GET['deal_id']) || isset($_GET['order_id']))) {
            unset($query_vars['name']);
        }
        return $query_vars;
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
            <!-- Step 0: Gate: Access via Paid Order or Link to Catalog -->
            <div id="emko-step-gate" class="emko-step">
                <div class="emko-gate-card" style="text-align:center; padding: 32px 24px;">
                    <div style="font-size: 40px; margin-bottom: 12px;">📅</div>
                    <h3 style="font-size: 20px; font-weight: 700; margin-bottom: 10px; color: #111827;">Запись на консультацию</h3>
                    <p style="font-size: 15px; color: #4b5563; max-width: 480px; margin: 0 auto 24px; line-height: 1.5;">
                        Выбор даты и времени встречи открывается автоматически после оформления и оплаты консультации.
                    </p>

                    <div style="margin-bottom: 24px;">
                        <a href="https://emko.academy/consultations/" class="emko-btn-primary" style="display:inline-block; text-decoration:none; padding:14px 28px; font-size:15px; font-weight:600; border-radius:10px;">
                            Перейти к выбору консультации →
                        </a>
                    </div>

                    <div class="emko-gate-notice" style="margin-top:20px; text-align: left; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; font-size: 13px; color: #6b7280; line-height: 1.5;">
                        💡 <strong>Уже оплатили консультацию?</strong><br>
                        Пожалуйста, перейдите по персональной ссылке для записи из письма или сообщения с подтверждением оплаты. Если ссылка не пришла, напишите в нашу службу заботы.
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

    public function inject_site_enhancements() {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $is_consultations = is_page('consultations') || strpos($uri, 'consultations') !== false;
        $is_teacher = is_singular('teachers') || strpos($uri, '/teachers/') !== false;

        if (!$is_consultations && !$is_teacher) {
            return;
        }
        ?>
        <style>
        /* Стили кнопки Расписание уточняется */
        .btn-frozen,
        .btn-frozen span {
            color: #ffffff !important;
            text-align: center !important;
        }
        .btn-frozen {
            cursor: pointer !important;
            opacity: 0.88 !important;
            transition: opacity 0.2s ease !important;
        }
        .btn-frozen:hover {
            opacity: 1 !important;
        }

        /* Стили модального окна для GetCourse виджета */
        #callback_4 .modal__content {
            max-width: 520px !important;
            width: 100% !important;
            max-height: 94vh !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            padding: 24px 16px 20px !important;
            border-radius: 24px !important;
            position: relative !important;
            background: #ffffff !important;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15) !important;
            box-sizing: border-box !important;
        }
        #callback_4 .modal__close {
            position: absolute !important;
            top: 14px !important;
            right: 14px !important;
            z-index: 100 !important;
            cursor: pointer !important;
            background: none !important;
            border: none !important;
            padding: 4px !important;
        }
        #callback_4 .modal__title,
        #callback_4 .modal__text,
        #callback_4 form {
            display: none !important;
        }
        #emko-gc-iframe {
            width: 100% !important;
            min-height: 620px !important;
            height: 620px !important;
            border: none !important;
            display: block !important;
            background: transparent !important;
        }
        </style>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var activeSlugs = ['semyon-laskin', 'dmitrij-demidov', 'kelo-lokonte'];
            var currentPath = window.location.pathname;

            // Динамическая подстройка высоты виджета по сообщению от GetCourse
            window.addEventListener('message', function(e) {
                if (e.data && e.data.height) {
                    var ifr = document.getElementById('emko-gc-iframe');
                    if (ifr) {
                        ifr.style.height = (parseInt(e.data.height) + 25) + 'px';
                    }
                }
            }, false);

            // 1. Каталог /consultations/
            if (currentPath.indexOf('consultations') !== -1) {
                var cards = document.querySelectorAll('.courses__wrapper.consultation .course, .course');
                cards.forEach(function(card) {
                    var links = card.querySelectorAll('a[href*="/teachers/"]');
                    var isLive = false;
                    links.forEach(function(l) {
                        var href = l.getAttribute('href') || '';
                        activeSlugs.forEach(function(slug) {
                            if (href.indexOf(slug) !== -1) isLive = true;
                        });
                    });

                    var bookBtn = card.querySelector('a.btn:not(.btn-grey)');
                    var reviewBtn = card.querySelector('a.btn-grey');

                    if (isLive) {
                        // Для активных преподавателей фиксируем цену 4 900 ₽
                        var priceBlock = card.querySelector('.course__price-value');
                        if (priceBlock) priceBlock.innerText = '4 900 ₽';
                    } else if (bookBtn) {
                        // Кнопка остается на месте, сохраняя ровную сетку всех карточек
                        bookBtn.classList.add('btn-frozen');
                        var span = bookBtn.querySelector('span');
                        if (span) span.innerText = 'Расписание уточняется';
                        else bookBtn.innerText = 'Расписание уточняется';

                        // При клике переводим на страницу опыта/отзывов преподавателя
                        bookBtn.removeAttribute('target');
                        bookBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            if (reviewBtn && reviewBtn.getAttribute('href')) {
                                window.location.href = reviewBtn.getAttribute('href');
                            }
                        });
                    }
                });
            }

            // 2. Страница преподавателя /teachers/{slug}/
            if (currentPath.indexOf('/teachers/') !== -1) {
                var isLiveTeacher = false;
                var currentTeacherSlug = '';
                activeSlugs.forEach(function(slug) {
                    if (currentPath.indexOf(slug) !== -1) {
                        isLiveTeacher = true;
                        currentTeacherSlug = slug;
                    }
                });

                var consultPrice = document.querySelector('.consult-teacher-desc-price');
                var consultBtn = document.querySelector('.button-buy-consult');
                var modal = document.getElementById('callback_4');

                if (isLiveTeacher) {
                    if (consultPrice) consultPrice.innerText = '4900 р/час';

                    if (modal && !document.getElementById('emko-gc-iframe')) {
                        var iframe = document.createElement('iframe');
                        iframe.id = 'emko-gc-iframe';
                        iframe.src = 'https://course.emko.academy/pl/lite/widget/widget?id=1628250';
                        iframe.setAttribute('scrolling', 'auto');
                        modal.querySelector('.modal__content').appendChild(iframe);
                    }

                    if (consultBtn) {
                        consultBtn.addEventListener('click', function() {
                            // Сохраняем метку в Cookie для перехвата редиректа после оплаты
                            document.cookie = 'emko_order_type=consultation; domain=.emko.academy; path=/; max-age=86400';
                            document.cookie = 'emko_teacher_slug=' + currentTeacherSlug + '; domain=.emko.academy; path=/; max-age=86400';
                            try {
                                localStorage.setItem('emko_order_type', 'consultation');
                                localStorage.setItem('emko_teacher_slug', currentTeacherSlug);
                            } catch(e) {}
                        });
                    }
                } else if (consultBtn) {
                    consultBtn.classList.add('btn-frozen');
                    consultBtn.innerText = 'Расписание уточняется';
                    consultBtn.removeAttribute('data-modal');
                    consultBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                    });
                }
            }
        });
        </script>
        <?php
    }
}
}

Emko_Consultations_Plugin::get_instance();
