<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Emko_Admin_Settings')) {
class Emko_Admin_Settings {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_init', array($this, 'handle_form_actions'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // AJAX handlers
        add_action('wp_ajax_emko_test_yandex', array($this, 'ajax_test_yandex'));
        add_action('wp_ajax_emko_fetch_calendars', array($this, 'ajax_fetch_calendars'));
        add_action('wp_ajax_emko_add_calendar', array($this, 'ajax_add_calendar'));
        add_action('wp_ajax_emko_delete_calendar', array($this, 'ajax_delete_calendar'));
        add_action('wp_ajax_emko_clear_all_calendars', array($this, 'ajax_clear_all_calendars'));
        add_action('wp_ajax_emko_test_getcourse', array($this, 'ajax_test_getcourse'));
    }

    public function add_menu_page() {
        add_menu_page(
            'Запись на консультации',
            'Запись ЁМКО',
            'manage_options',
            'emko-consultations',
            array($this, 'render_admin_page'),
            'dashicons-calendar-alt',
            28
        );
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'emko-consultations') === false) {
            return;
        }
        // WordPress built-in styles and scripts
        wp_enqueue_style('dashicons');
    }

    public function handle_form_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Сохранение общих настроек
        if (isset($_POST['emko_save_settings']) && check_admin_referer('emko_settings_nonce')) {
            $email = sanitize_email($_POST['yandex_email'] ?? '');
            update_option('emko_yandex_email', $email);

            if (!empty($_POST['yandex_password'])) {
                update_option('emko_yandex_app_password', sanitize_text_field($_POST['yandex_password']));
            }

            update_option('emko_getcourse_account', sanitize_text_field($_POST['getcourse_account'] ?? ''));

            if (!empty($_POST['getcourse_key'])) {
                update_option('emko_getcourse_key', sanitize_text_field($_POST['getcourse_key']));
            }

            wp_safe_redirect(add_query_arg(array('page' => 'emko-consultations', 'tab' => 'settings', 'saved' => '1'), admin_url('admin.php')));
            exit;
        }

        // Сохранение/редактирование преподавателя
        if (isset($_POST['emko_save_teacher']) && check_admin_referer('emko_teacher_nonce')) {
            $teachers = get_option('emko_teachers', array());
            $teacherId = !empty($_POST['teacher_id']) ? sanitize_text_field($_POST['teacher_id']) : ('teacher_' . time());

            $schedule = array();
            for ($d = 1; $d <= 7; $d++) {
                $schedule[$d] = array(
                    'active'      => !empty($_POST["day_{$d}_active"]),
                    'start'       => sanitize_text_field($_POST["day_{$d}_start"] ?? '10:00'),
                    'end'         => sanitize_text_field($_POST["day_{$d}_end"] ?? '18:00'),
                    'start2'      => sanitize_text_field($_POST["day_{$d}_start2"] ?? ''),
                    'end2'        => sanitize_text_field($_POST["day_{$d}_end2"] ?? ''),
                    'break_start' => sanitize_text_field($_POST["day_{$d}_break_start"] ?? ''),
                    'break_end'   => sanitize_text_field($_POST["day_{$d}_break_end"] ?? '')
                );
            }

            $teachers[$teacherId] = array(
                'id'            => $teacherId,
                'name'          => sanitize_text_field($_POST['teacher_name'] ?? ''),
                'role'          => sanitize_text_field($_POST['teacher_role'] ?? ''),
                'calendar_href' => sanitize_text_field($_POST['calendar_href'] ?? ''),
                'duration'      => intval($_POST['duration'] ?? 45),
                'buffer'        => intval($_POST['buffer'] ?? 10),
                'active'        => !empty($_POST['is_active']),
                'schedule'      => $schedule
            );

            update_option('emko_teachers', $teachers);
            wp_safe_redirect(add_query_arg(array('page' => 'emko-consultations', 'tab' => 'teachers', 'saved' => '1'), admin_url('admin.php')));
            exit;
        }

        // Удаление преподавателя
        if (isset($_GET['action']) && $_GET['action'] === 'delete_teacher' && isset($_GET['teacher_id']) && check_admin_referer('emko_delete_teacher')) {
            $teachers = get_option('emko_teachers', array());
            $teacherId = sanitize_text_field($_GET['teacher_id']);
            unset($teachers[$teacherId]);
            update_option('emko_teachers', $teachers);
            wp_safe_redirect(add_query_arg(array('page' => 'emko-consultations', 'tab' => 'teachers'), admin_url('admin.php')));
            exit;
        }
    }

    /* =========================================================================
       AJAX ENDPOINTS
       ========================================================================= */

    public function ajax_test_yandex() {
        check_ajax_referer('emko_admin_ajax_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Доступ запрещен');
        }

        $email = !empty($_POST['email']) ? sanitize_email($_POST['email']) : get_option('emko_yandex_email');
        $pass  = !empty($_POST['password']) ? sanitize_text_field($_POST['password']) : get_option('emko_yandex_app_password');

        if (empty($email) || empty($pass)) {
            wp_send_json_error('Введите email и пароль приложения Яндекса.');
        }

        $client = new Emko_CalDAV_Client($email, $pass);
        $res = $client->test_connection();

        if ($res['success']) {
            $calendars = $client->get_calendars();
            $count = count($calendars);
            if ($count > 0) {
                update_option('emko_cached_calendars', $calendars);
            }
            wp_send_json_success(array(
                'message' => "Успешное подключение к Яндекс CalDAV! Обнаружено календарей: {$count}.",
                'calendars_count' => $count
            ));
        } else {
            wp_send_json_error($res['error'] ?? 'Не удалось подключиться к Яндекс CalDAV');
        }
    }

    public function ajax_fetch_calendars() {
        check_ajax_referer('emko_admin_ajax_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Доступ запрещен');
        }

        $email = get_option('emko_yandex_email');
        $pass  = get_option('emko_yandex_app_password');

        if (empty($email) || empty($pass)) {
            wp_send_json_error('Сначала укажите и сохраните email и пароль приложения во вкладке «Настройки»!');
        }

        $client = new Emko_CalDAV_Client($email, $pass);
        $calendars = $client->get_calendars();

        if (empty($calendars)) {
            wp_send_json_error('Календари не найдены или ошибка авторизации. Проверьте пароль приложения в Яндекс ID.');
        }

        update_option('emko_cached_calendars', $calendars);
        wp_send_json_success(array('calendars' => $calendars));
    }

    public function ajax_add_calendar() {
        check_ajax_referer('emko_admin_ajax_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Доступ запрещен');
        }

        $name = sanitize_text_field($_POST['name'] ?? '');
        $href = sanitize_text_field($_POST['href'] ?? '');

        if (empty($name) || empty($href)) {
            wp_send_json_error('Укажите название и CalDAV путь/URL календаря.');
        }

        $calendars = get_option('emko_cached_calendars', array());
        // Проверяем дубли
        foreach ($calendars as $c) {
            if ($c['href'] === $href) {
                wp_send_json_error('Календарь с таким URL уже есть в списке.');
            }
        }

        $calendars[] = array(
            'href' => $href,
            'name' => $name
        );

        update_option('emko_cached_calendars', $calendars);
        wp_send_json_success(array('calendars' => $calendars));
    }

    public function ajax_delete_calendar() {
        check_ajax_referer('emko_admin_ajax_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Доступ запрещен');
        }

        $href = isset($_POST['href']) ? wp_unslash($_POST['href']) : '';
        $href = trim($href);
        if (empty($href)) {
            wp_send_json_error('Не указан идентификатор календаря.');
        }

        $calendars = get_option('emko_cached_calendars', array());
        $filtered = array();
        $targetNorm = rtrim(urldecode($href), '/');

        foreach ($calendars as $c) {
            $curHref = $c['href'] ?? '';
            $curNorm = rtrim(urldecode($curHref), '/');
            if ($curNorm !== $targetNorm && $curHref !== $href) {
                $filtered[] = $c;
            }
        }

        update_option('emko_cached_calendars', $filtered);
        wp_send_json_success(array('calendars' => $filtered));
    }

    public function ajax_clear_all_calendars() {
        check_ajax_referer('emko_admin_ajax_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Доступ запрещен');
        }

        update_option('emko_cached_calendars', array());
        wp_send_json_success(array('calendars' => array()));
    }

    public function ajax_test_getcourse() {
        check_ajax_referer('emko_admin_ajax_nonce', 'security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Доступ запрещен');
        }

        $account = !empty($_POST['account']) ? sanitize_text_field($_POST['account']) : get_option('emko_getcourse_account');
        $key     = !empty($_POST['key']) ? sanitize_text_field($_POST['key']) : get_option('emko_getcourse_key');

        if (empty($account) || empty($key)) {
            wp_send_json_error('Укажите аккаунт и секретный ключ GetCourse.');
        }

        $client = new Emko_GetCourse_Client($account, $key);
        $res = $client->test_connection();

        if ($res['success']) {
            wp_send_json_success(array('message' => 'Связь с GetCourse API успешно установлена! Ключ валиден.'));
        } else {
            wp_send_json_error($res['error'] ?? 'Ошибка проверки GetCourse API');
        }
    }

    /* =========================================================================
       RENDER ADMIN INTERFACE
       ========================================================================= */

    public function render_admin_page() {
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'teachers';
        $editId = isset($_GET['edit_teacher']) ? sanitize_text_field($_GET['edit_teacher']) : null;
        $teachers = get_option('emko_teachers', array());
        $cachedCalendars = get_option('emko_cached_calendars', array());
        $yEmail = get_option('emko_yandex_email');
        $yPass  = get_option('emko_yandex_app_password');
        $gcAcc  = get_option('emko_getcourse_account');
        $gcKey  = get_option('emko_getcourse_key');

        $yStatus = (!empty($yEmail) && !empty($yPass));
        $gcStatus = (!empty($gcAcc) && !empty($gcKey));
        $calsCount = count($cachedCalendars);
        $teachersCount = count($teachers);
        ?>
        <div class="wrap" style="max-width: 1100px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin:15px 0 20px 0;flex-wrap:wrap;gap:15px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="background:#0284c7;color:#fff;width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                        <span class="dashicons dashicons-calendar-alt" style="font-size:26px;width:26px;height:26px;"></span>
                    </div>
                    <div>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <h1 style="margin:0;font-size:24px;line-height:1.2;">Запись на консультации: ЁМКО</h1>
                            <span style="background:#10b981;color:#fff;font-size:12px;font-weight:700;padding:3px 10px;border-radius:20px;letter-spacing:0.3px;">v<?php echo defined('EMKO_BOOKING_VERSION') ? EMKO_BOOKING_VERSION : '1.3.2'; ?></span>
                        </div>
                        <p style="margin:4px 0 0 0;color:#6b7280;font-size:13px;">Интеграция: Яндекс Календарь • Яндекс Телемост • GetCourse</p>
                    </div>
                </div>
                <div style="background:#fff;border:1px solid #e5e7eb;padding:6px 14px;border-radius:8px;display:flex;align-items:center;gap:10px;">
                    <span style="font-size:13px;color:#4b5563;">Шорткод:</span>
                    <code style="background:#f3f4f6;padding:4px 8px;border-radius:4px;font-size:13px;font-weight:600;color:#1e40af;">[emko_booking]</code>
                    <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('[emko_booking]');alert('Шорткод скопирован в буфер обмена!');">Копировать</button>
                </div>
            </div>

            <!-- Блок статуса версии и обновления плагина -->
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 18px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:20px;">🟢</span>
                    <div>
                        <strong style="color:#166534;font-size:14px;">Установлена версия v<?php echo defined('EMKO_BOOKING_VERSION') ? EMKO_BOOKING_VERSION : '1.3.2'; ?></strong>
                        <span style="color:#15803d;font-size:13px;margin-left:8px;">(Модули календаря, Телемоста и GetCourse активны)</span>
                    </div>
                </div>
                <a href="<?php echo admin_url('plugin-install.php?tab=upload'); ?>" class="button button-primary" style="background:#10b981;border-color:#059669;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
                    <span class="dashicons dashicons-upload" style="font-size:16px;width:16px;height:16px;margin-top:2px;"></span>
                    Обновить плагин (загрузить .zip)
                </a>
            </div>

            <!-- Индикатор готовности / Статусная панель первичной установки -->
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 20px;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <div style="font-size:13px;font-weight:600;color:#374151;margin-bottom:12px;text-transform:uppercase;letter-spacing:0.5px;">
                    Статус настройки системы
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:14px;">
                    <!-- 1. Яндекс Аккаунт -->
                    <div style="background:<?php echo $yStatus ? '#f0fdf4' : '#fffbeb'; ?>;border:1px solid <?php echo $yStatus ? '#bbf7d0' : '#fde68a'; ?>;padding:12px;border-radius:8px;">
                        <div style="display:flex;align-items:center;gap:8px;font-weight:600;font-size:13px;color:<?php echo $yStatus ? '#166534' : '#92400e'; ?>;">
                            <span><?php echo $yStatus ? '🟢' : '🟡'; ?></span>
                            <span>Яндекс Аккаунт</span>
                        </div>
                        <div style="font-size:12px;color:#4b5563;margin-top:4px;">
                            <?php if ($yStatus): ?>
                                <strong><?php echo esc_html($yEmail); ?></strong>
                            <?php else: ?>
                                Не настроен (<a href="?page=emko-consultations&tab=settings">Настроить</a>)
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 2. Календари -->
                    <div style="background:<?php echo $calsCount > 0 ? '#f0fdf4' : '#fffbeb'; ?>;border:1px solid <?php echo $calsCount > 0 ? '#bbf7d0' : '#fde68a'; ?>;padding:12px;border-radius:8px;">
                        <div style="display:flex;align-items:center;gap:8px;font-weight:600;font-size:13px;color:<?php echo $calsCount > 0 ? '#166534' : '#92400e'; ?>;">
                            <span><?php echo $calsCount > 0 ? '🟢' : '🟡'; ?></span>
                            <span>Календари в системе</span>
                        </div>
                        <div style="font-size:12px;color:#4b5563;margin-top:4px;">
                            <?php if ($calsCount > 0): ?>
                                Загружено: <strong><?php echo $calsCount; ?></strong> (<a href="?page=emko-consultations&tab=calendars">Управление</a>)
                            <?php else: ?>
                                0 календарей (<a href="?page=emko-consultations&tab=calendars">Синхронизировать</a>)
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 3. Преподаватели -->
                    <div style="background:<?php echo $teachersCount > 0 ? '#f0fdf4' : '#fffbeb'; ?>;border:1px solid <?php echo $teachersCount > 0 ? '#bbf7d0' : '#fde68a'; ?>;padding:12px;border-radius:8px;">
                        <div style="display:flex;align-items:center;gap:8px;font-weight:600;font-size:13px;color:<?php echo $teachersCount > 0 ? '#166534' : '#92400e'; ?>;">
                            <span><?php echo $teachersCount > 0 ? '🟢' : '🟡'; ?></span>
                            <span>Преподаватели</span>
                        </div>
                        <div style="font-size:12px;color:#4b5563;margin-top:4px;">
                            <?php if ($teachersCount > 0): ?>
                                Активно: <strong><?php echo $teachersCount; ?></strong> (<a href="?page=emko-consultations&tab=teachers">Список</a>)
                            <?php else: ?>
                                Нет преподавателей (<a href="?page=emko-consultations&tab=teachers&new=1">+ Добавить</a>)
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 4. GetCourse -->
                    <div style="background:<?php echo $gcStatus ? '#f0fdf4' : '#f9fafb'; ?>;border:1px solid <?php echo $gcStatus ? '#bbf7d0' : '#e5e7eb'; ?>;padding:12px;border-radius:8px;">
                        <div style="display:flex;align-items:center;gap:8px;font-weight:600;font-size:13px;color:<?php echo $gcStatus ? '#166534' : '#4b5563'; ?>;">
                            <span><?php echo $gcStatus ? '🟢' : '⚪'; ?></span>
                            <span>GetCourse API</span>
                        </div>
                        <div style="font-size:12px;color:#4b5563;margin-top:4px;">
                            <?php if ($gcStatus): ?>
                                Подключен (<code><?php echo esc_html($gcAcc); ?></code>)
                            <?php else: ?>
                                Опционально (<a href="?page=emko-consultations&tab=settings">Подключить</a>)
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (isset($_GET['saved'])): ?>
                <div class="notice notice-success is-dismissible" style="border-radius:6px;"><p><strong>✓ Настройки успешно сохранены!</strong></p></div>
            <?php endif; ?>

            <!-- Вкладки управления -->
            <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
                <a href="?page=emko-consultations&tab=teachers" class="nav-tab <?php echo $tab === 'teachers' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-groups" style="margin-right:4px;"></span> Преподаватели
                </a>
                <a href="?page=emko-consultations&tab=calendars" class="nav-tab <?php echo $tab === 'calendars' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-calendar" style="margin-right:4px;"></span> Календари Яндекса
                </a>
                <a href="?page=emko-consultations&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-settings" style="margin-right:4px;"></span> Подключение аккаунта и API
                </a>
                <a href="?page=emko-consultations&tab=instructions" class="nav-tab <?php echo $tab === 'instructions' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-book-alt" style="margin-right:4px;"></span> Инструкция и GetCourse
                </a>
            </nav>

            <!-- ============================================================= -->
            <!-- ВКЛАДКА 1: ПРЕПОДАВАТЕЛИ                                      -->
            <!-- ============================================================= -->
            <?php if ($tab === 'teachers'): ?>
                <?php if ($editId || isset($_GET['new'])): 
                    $tData = $editId && isset($teachers[$editId]) ? $teachers[$editId] : array(
                        'id' => '', 'name' => '', 'role' => '', 'calendar_href' => '', 'duration' => 45, 'buffer' => 10, 'active' => 1,
                        'schedule' => array(
                            1 => array('active' => 1, 'start' => '11:00', 'end' => '19:00'),
                            2 => array('active' => 1, 'start' => '11:00', 'end' => '19:00'),
                            3 => array('active' => 1, 'start' => '11:00', 'end' => '19:00'),
                            4 => array('active' => 1, 'start' => '11:00', 'end' => '19:00'),
                            5 => array('active' => 1, 'start' => '11:00', 'end' => '19:00'),
                            6 => array('active' => 0, 'start' => '12:00', 'end' => '17:00'),
                            7 => array('active' => 0, 'start' => '12:00', 'end' => '17:00'),
                        )
                    );
                    $daysName = array(1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье');
                ?>
                    <div class="card" style="max-width:none;padding:24px;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,0.06);box-sizing:border-box;">
                        <h2><?php echo $editId ? 'Редактировать преподавателя' : 'Добавить нового преподавателя'; ?></h2>
                        <form method="POST">
                            <?php wp_nonce_field('emko_teacher_nonce'); ?>
                            <input type="hidden" name="teacher_id" value="<?php echo esc_attr($editId); ?>" />
                            <table class="form-table">
                                <tr>
                                    <th scope="row">ФИО Преподавателя *</th>
                                    <td>
                                        <input type="text" name="teacher_name" value="<?php echo esc_attr($tData['name']); ?>" class="regular-text" required placeholder="Например: Алексей Смирнов" />
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Специальность / Роль</th>
                                    <td>
                                        <input type="text" name="teacher_role" value="<?php echo esc_attr($tData['role']); ?>" placeholder="Например: Режиссер анимации, супервайзер" class="regular-text" />
                                        <p class="description">Отображается ученику под именем преподавателя при выборе.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Календарь в Яндекс *</th>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                            <select name="calendar_href" id="calendar_href_select" style="min-width:320px;" required>
                                                <option value="">-- Выберите календарь --</option>
                                                <?php foreach ($cachedCalendars as $cal): 
                                                    $cBase = basename(rtrim($cal['href'] ?? '', '/'));
                                                    $tBase = basename(rtrim($tData['calendar_href'] ?? '', '/'));
                                                    $isSel = ($tData['calendar_href'] === $cal['href'] || (!empty($tBase) && $tBase === $cBase));
                                                ?>
                                                    <option value="<?php echo esc_attr($cal['href']); ?>" <?php if ($isSel) echo 'selected="selected"'; ?>>
                                                        <?php echo esc_html($cal['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" id="btn-sync-cals-teacher" class="button button-secondary">
                                                🔄 Обновить список из Яндекса
                                            </button>
                                            <span id="teacher-sync-spinner" class="spinner"></span>
                                        </div>
                                        <p class="description">События консультаций и проверки занятости слотов будут вестись именно в этом календаре Яндекса.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Длительность консультации</th>
                                    <td>
                                        <select name="duration">
                                            <option value="30" <?php selected($tData['duration'], 30); ?>>30 минут</option>
                                            <option value="45" <?php selected($tData['duration'], 45); ?>>45 минут</option>
                                            <option value="60" <?php selected($tData['duration'], 60); ?>>60 минут (1 час)</option>
                                            <option value="90" <?php selected($tData['duration'], 90); ?>>90 минут (1.5 часа)</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Перерыв между встречами</th>
                                    <td>
                                        <select name="buffer">
                                            <option value="0" <?php selected($tData['buffer'], 0); ?>>Без перерыва (0 мин)</option>
                                            <option value="10" <?php selected($tData['buffer'], 10); ?>>10 минут</option>
                                            <option value="15" <?php selected($tData['buffer'], 15); ?>>15 минут</option>
                                            <option value="30" <?php selected($tData['buffer'], 30); ?>>30 минут</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Статус</th>
                                    <td>
                                        <label><input type="checkbox" name="is_active" value="1" <?php checked(!empty($tData['active'])); ?> /> <strong>Активен для бронирования</strong> (отображается в виджете на сайте)</label>
                                    </td>
                                </tr>
                            </table>

                            <h3 style="margin-top:30px;border-top:1px solid #e5e7eb;padding-top:20px;">Рабочие дни и часы приёма</h3>
                            <p class="description" style="margin-bottom:15px;">Укажите, в какие дни и часы преподаватель готов проводить консультации (по московскому времени):</p>
                            
                            <table class="widefat fixed striped" style="max-width:760px;border-radius:6px;overflow:hidden;">
                                <thead>
                                    <tr>
                                        <th style="width:130px;">День недели</th>
                                        <th style="width:90px;">Рабочий</th>
                                        <th>Периоды приёма и перерывы (МСК)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($daysName as $num => $name): 
                                        $dConf = $tData['schedule'][$num] ?? array('active' => 0, 'start' => '10:00', 'end' => '18:00');
                                    ?>
                                    <tr>
                                        <td style="vertical-align:top;padding-top:12px;"><strong><?php echo esc_html($name); ?></strong></td>
                                        <td style="vertical-align:top;padding-top:12px;">
                                            <label><input type="checkbox" name="day_<?php echo $num; ?>_active" value="1" <?php checked(!empty($dConf['active'])); ?> /> Да</label>
                                        </td>
                                        <td>
                                            <div style="margin-bottom:6px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                                <span style="font-size:12px;color:#334155;font-weight:600;min-width:65px;">Период 1:</span>
                                                <span>с</span> <input type="time" name="day_<?php echo $num; ?>_start" value="<?php echo esc_attr($dConf['start'] ?? '10:00'); ?>" style="padding:2px 6px;" />
                                                <span>до</span> <input type="time" name="day_<?php echo $num; ?>_end" value="<?php echo esc_attr($dConf['end'] ?? '18:00'); ?>" style="padding:2px 6px;" />
                                            </div>
                                            <div style="margin-bottom:6px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                                <span style="font-size:12px;color:#475569;min-width:65px;">Период 2:</span>
                                                <span>с</span> <input type="time" name="day_<?php echo $num; ?>_start2" value="<?php echo esc_attr($dConf['start2'] ?? ''); ?>" style="padding:2px 6px;" />
                                                <span>до</span> <input type="time" name="day_<?php echo $num; ?>_end2" value="<?php echo esc_attr($dConf['end2'] ?? ''); ?>" style="padding:2px 6px;" />
                                                <span class="description" style="font-size:11px;color:#94a3b8;">(опционально, второй блок дня)</span>
                                            </div>
                                            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                                <span style="font-size:12px;color:#b91c1c;min-width:65px;">Перерыв:</span>
                                                <span>с</span> <input type="time" name="day_<?php echo $num; ?>_break_start" value="<?php echo esc_attr($dConf['break_start'] ?? ''); ?>" style="padding:2px 6px;" />
                                                <span>до</span> <input type="time" name="day_<?php echo $num; ?>_break_end" value="<?php echo esc_attr($dConf['break_end'] ?? ''); ?>" style="padding:2px 6px;" />
                                                <span class="description" style="font-size:11px;color:#94a3b8;">(исключение из слотов, например 12:00–15:00)</span>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <p class="submit" style="margin-top:24px;">
                                <input type="submit" name="emko_save_teacher" class="button button-primary" value="Сохранить преподавателя" />
                                <a href="?page=emko-consultations&tab=teachers" class="button">Отмена</a>
                            </p>
                        </form>
                    </div>

                    <script>
                    jQuery(document).ready(function($) {
                        $('#btn-sync-cals-teacher').on('click', function() {
                            var btn = $(this);
                            var spin = $('#teacher-sync-spinner');
                            spin.addClass('is-active');
                            btn.prop('disabled', true);

                            $.post(ajaxurl, {
                                action: 'emko_fetch_calendars',
                                security: '<?php echo wp_create_nonce('emko_admin_ajax_nonce'); ?>'
                            }, function(res) {
                                spin.removeClass('is-active');
                                btn.prop('disabled', false);
                                if (res.success && res.data.calendars) {
                                    var sel = $('#calendar_href_select');
                                    var currentVal = sel.val();
                                    sel.empty().append('<option value="">-- Выберите календарь --</option>');
                                    res.data.calendars.forEach(function(c) {
                                        var opt = $('<option></option>').attr('value', c.href).text(c.name);
                                        if (c.href === currentVal) opt.prop('selected', true);
                                        sel.append(opt);
                                    });
                                    alert('Календари успешно загружены из Яндекса!');
                                } else {
                                    alert('Ошибка: ' + (res.data || 'Не удалось получить календари'));
                                }
                            });
                        });
                    });
                    </script>
                <?php else: ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;flex-wrap:wrap;gap:10px;">
                        <p class="description" style="margin:0;">Список преподавателей, доступных для бронирования консультаций.</p>
                        <a href="?page=emko-consultations&tab=teachers&new=1" class="button button-primary">
                            + Добавить преподавателя
                        </a>
                    </div>

                    <table class="widefat fixed striped" style="border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                        <thead>
                            <tr>
                                <th style="width:220px;">Имя преподавателя</th>
                                <th style="width:200px;">Специальность</th>
                                <th>Календарь Яндекса</th>
                                <th style="width:130px;">Длительность</th>
                                <th style="width:100px;">Статус</th>
                                <th style="width:170px;">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($teachers)): ?>
                                <tr><td colspan="6" style="text-align:center;padding:30px;color:#6b7280;">Преподаватели пока не добавлены. Нажмите «+ Добавить преподавателя» для старта.</td></tr>
                            <?php else: ?>
                                <?php foreach ($teachers as $id => $t): 
                                    // Поиск названия календаря
                                    $calHref = $t['calendar_href'] ?? '';
                                    $calName = '';
                                    if (!empty($calHref)) {
                                        $tBase = basename(rtrim($calHref, '/'));
                                        foreach ($cachedCalendars as $c) {
                                            $cBase = basename(rtrim($c['href'] ?? '', '/'));
                                            if (($c['href'] ?? '') === $calHref || (!empty($tBase) && $tBase === $cBase)) {
                                                $calName = $c['name'];
                                                break;
                                            }
                                        }
                                        if (empty($calName)) {
                                            $calName = '✓ Календарь подключен (' . $tBase . ')';
                                        }
                                    } else {
                                        $calName = '— Не назначен —';
                                    }
                                ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($t['name']); ?></strong></td>
                                        <td><?php echo esc_html($t['role'] ?? '—'); ?></td>
                                        <td>
                                            <span style="font-weight:600;color:#1e40af;"><?php echo esc_html($calName); ?></span><br/>
                                            <code style="font-size:11px;color:#6b7280;"><?php echo esc_html($t['calendar_href'] ?? ''); ?></code>
                                        </td>
                                        <td><?php echo intval($t['duration'] ?? 45); ?> мин (+<?php echo intval($t['buffer'] ?? 10); ?>м)</td>
                                        <td>
                                            <?php if (!empty($t['active'])): ?>
                                                <span style="color:#166534;background:#dcfce7;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:600;">Активен</span>
                                            <?php else: ?>
                                                <span style="color:#6b7280;background:#f3f4f6;padding:3px 8px;border-radius:4px;font-size:11px;">Отключен</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="?page=emko-consultations&tab=teachers&edit_teacher=<?php echo esc_attr($id); ?>" class="button button-small">Редактировать</a>
                                            <a href="<?php echo wp_nonce_url('?page=emko-consultations&action=delete_teacher&teacher_id=' . esc_attr($id), 'emko_delete_teacher'); ?>" onclick="return confirm('Вы уверены, что хотите удалить этого преподавателя?');" class="button button-small button-link-delete">Удалить</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

            <!-- ============================================================= -->
            <!-- ВКЛАДКА 2: КАЛЕНДАРИ ЯНДЕКСА                                  -->
            <!-- ============================================================= -->
            <?php elseif ($tab === 'calendars'): ?>
                <div class="card" style="max-width:none;padding:24px;border-radius:10px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.06);box-sizing:border-box;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:15px;margin-bottom:15px;">
                        <div>
                            <h2 style="margin:0 0 4px 0;">Управление календарями Яндекса</h2>
                            <p class="description" style="margin:0;">
                                Здесь отображаются календари, подключенные к системе через Яндекс CalDAV (аккаунт: <strong><?php echo esc_html($yEmail ?: 'не указан'); ?></strong>).
                            </p>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <button type="button" id="btn-sync-all-calendars" class="button button-primary">
                                🔄 Синхронизировать календари с Яндексом
                            </button>
                            <button type="button" id="btn-clear-all-calendars" class="button button-secondary" style="color:#b91c1c;">
                                🗑️ Очистить список
                            </button>
                            <span id="cals-sync-spinner" class="spinner"></span>
                        </div>
                    </div>

                    <div id="cals-notice-area"></div>

                    <!-- Таблица подключенных календарей -->
                    <table class="widefat fixed striped" style="margin-top:15px;border-radius:6px;overflow:hidden;">
                        <thead>
                            <tr>
                                <th style="width:200px;">Название календаря</th>
                                <th>CalDAV URL / Идентификатор</th>
                                <th style="width:220px;">Привязан к преподавателю</th>
                                <th style="width:110px;">Действие</th>
                            </tr>
                        </thead>
                        <tbody id="calendars-table-body">
                            <?php if (empty($cachedCalendars)): ?>
                                <tr id="row-no-cals"><td colspan="4" style="text-align:center;padding:25px;color:#6b7280;">Календари пока не загружены. Нажмите «Синхронизировать календари с Яндексом».</td></tr>
                            <?php else: ?>
                                <?php foreach ($cachedCalendars as $c): 
                                    // Проверяем, какой преподаватель привязан
                                    $assignedTeachers = array();
                                    $cBase = basename(rtrim($c['href'] ?? '', '/'));
                                    foreach ($teachers as $t) {
                                        $tBase = basename(rtrim($t['calendar_href'] ?? '', '/'));
                                        if (($t['calendar_href'] ?? '') === $c['href'] || (!empty($cBase) && $cBase === $tBase)) {
                                            $assignedTeachers[] = $t['name'];
                                        }
                                    }
                                ?>
                                    <tr data-href="<?php echo esc_attr($c['href']); ?>">
                                        <td><strong><?php echo esc_html($c['name']); ?></strong></td>
                                        <td style="word-break:break-all;"><code><?php echo esc_html($c['href']); ?></code></td>
                                        <td>
                                            <?php if (!empty($assignedTeachers)): ?>
                                                <span style="color:#166534;font-weight:600;">✓ <?php echo esc_html(implode(', ', $assignedTeachers)); ?></span>
                                            <?php else: ?>
                                                <span style="color:#9ca3af;">— Не назначен —</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button type="button" class="button button-small button-link-delete btn-delete-cal" data-href="<?php echo esc_attr($c['href']); ?>">Удалить</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Добавить календарь вручную -->
                    <div style="margin-top:24px;background:#f9fafb;border:1px dashed #d1d5db;border-radius:8px;padding:16px;">
                        <h4 style="margin:0 0 10px 0;">+ Добавить календарь вручную</h4>
                        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                            <input type="text" id="manual-cal-name" placeholder="Название (например: Консультации Михаила)" style="flex:1;min-width:200px;" />
                            <input type="text" id="manual-cal-href" placeholder="CalDAV URL (например: /calendars/user@ya.ru/events-12345/)" style="flex:2;min-width:300px;" />
                            <button type="button" id="btn-add-manual-cal" class="button button-secondary">Добавить в список</button>
                        </div>
                    </div>
                </div>

                <!-- Понятная инструкция по добавлению календарей в Яндекс -->
                <div class="card" style="max-width:none;padding:20px;border-radius:10px;background:#f8fafc;border:1px solid #e2e8f0;box-sizing:border-box;">
                    <h3 style="margin-top:0;">💡 Как создать отдельный календарь для нового преподавателя:</h3>
                    <ol style="margin-left:20px;line-height:1.8;color:#334155;">
                        <li>Перейдите в веб-интерфейс <a href="https://calendar.yandex.ru" target="_blank">calendar.yandex.ru</a> под аккаунтом <strong><?php echo esc_html($yEmail ?: 'вашим Яндекс аккаунтом'); ?></strong>.</li>
                        <li>В левой боковой колонке в блоке «Мои календари» нажмите на значок <strong>«+ Новый календарь»</strong>.</li>
                        <li>Введите имя календаря (например: <em>«Консультации Режиссера»</em> или <em>«Консультации Анны»</em>) и сохраните.</li>
                        <li>Вернитесь на эту страницу и нажмите сверху кнопку <strong>«🔄 Синхронизировать календари с Яндексом»</strong>.</li>
                        <li>Перейдите во вкладку <strong>«Преподаватели»</strong> и выберите этот календарь при создании или редактировании преподавателя.</li>
                    </ol>
                </div>

                <script>
                jQuery(document).ready(function($) {
                    var ajaxNonce = '<?php echo wp_create_nonce('emko_admin_ajax_nonce'); ?>';

                    // Синхронизация календарей через AJAX
                    $('#btn-sync-all-calendars').on('click', function() {
                        var btn = $(this);
                        var spin = $('#cals-sync-spinner');
                        btn.prop('disabled', true);
                        spin.addClass('is-active');

                        $.post(ajaxurl, {
                            action: 'emko_fetch_calendars',
                            security: ajaxNonce
                        }, function(res) {
                            btn.prop('disabled', false);
                            spin.removeClass('is-active');

                            if (res.success && res.data.calendars) {
                                renderCalendarsTable(res.data.calendars);
                                showNotice('cals-notice-area', 'success', 'Календари успешно синхронизированы из Яндекса! Найдено: ' + res.data.calendars.length);
                            } else {
                                showNotice('cals-notice-area', 'error', 'Ошибка синхронизации: ' + (res.data || 'Неизвестная ошибка'));
                            }
                        });
                    });

                    // Очистить все календари
                    $('#btn-clear-all-calendars').on('click', function() {
                        if (!confirm('Очистить весь сохраненный список календарей? (Сами календари в Яндексе не удалятся)')) {
                            return;
                        }
                        var btn = $(this);
                        btn.prop('disabled', true);
                        $.post(ajaxurl, {
                            action: 'emko_clear_all_calendars',
                            security: ajaxNonce
                        }, function(res) {
                            btn.prop('disabled', false);
                            if (res.success) {
                                renderCalendarsTable([]);
                                showNotice('cals-notice-area', 'success', 'Список календарей очищен. Теперь нажмите «Синхронизировать календари с Яндексом».');
                            }
                        });
                    });

                    // Удаление календаря из списка
                    $(document).on('click', '.btn-delete-cal', function() {
                        var href = $(this).attr('data-href') || $(this).data('href');
                        if (!href) return;
                        if (!confirm('Удалить этот календарь из списка плагина? (В самом Яндексе календарь не удалится)')) {
                            return;
                        }

                        $.post(ajaxurl, {
                            action: 'emko_delete_calendar',
                            href: href,
                            security: ajaxNonce
                        }, function(res) {
                            if (res.success && res.data) {
                                renderCalendarsTable(res.data.calendars || []);
                                showNotice('cals-notice-area', 'success', 'Календарь удален из списка плагина.');
                            } else {
                                alert('Ошибка: ' + (res.data || 'Не удалось удалить'));
                            }
                        });
                    });

                    // Добавление календаря вручную
                    $('#btn-add-manual-cal').on('click', function() {
                        var name = $('#manual-cal-name').val().trim();
                        var href = $('#manual-cal-href').val().trim();

                        if (!name || !href) {
                            alert('Пожалуйста, укажите название и CalDAV URL календаря.');
                            return;
                        }

                        $.post(ajaxurl, {
                            action: 'emko_add_calendar',
                            name: name,
                            href: href,
                            security: ajaxNonce
                        }, function(res) {
                            if (res.success && res.data.calendars) {
                                $('#manual-cal-name').val('');
                                $('#manual-cal-href').val('');
                                renderCalendarsTable(res.data.calendars);
                                showNotice('cals-notice-area', 'success', 'Календарь успешно добавлен!');
                            } else {
                                alert('Ошибка: ' + (res.data || 'Не удалось добавить'));
                            }
                        });
                    });

                    function renderCalendarsTable(cals) {
                        var tbody = $('#calendars-table-body');
                        tbody.empty();
                        if (cals.length === 0) {
                            tbody.append('<tr><td colspan="4" style="text-align:center;padding:25px;color:#6b7280;">Календари не найдены.</td></tr>');
                            return;
                        }
                        cals.forEach(function(c) {
                            var tr = $('<tr></tr>').attr('data-href', c.href);
                            tr.append('<td><strong>' + $('<div>').text(c.name).html() + '</strong></td>');
                            tr.append('<td><code>' + $('<div>').text(c.href).html() + '</code></td>');
                            tr.append('<td><span style="color:#9ca3af;">— Обновите страницу для проверки назначения —</span></td>');
                            tr.append('<td><button type="button" class="button button-small button-link-delete btn-delete-cal" data-href="' + $('<div>').text(c.href).html() + '">Удалить</button></td>');
                            tbody.append(tr);
                        });
                    }

                    function showNotice(areaId, type, msg) {
                        var cls = type === 'success' ? 'notice notice-success' : 'notice notice-error';
                        $('#' + areaId).html('<div class="' + cls + ' is-dismissible" style="margin:10px 0;"><p>' + msg + '</p></div>');
                    }
                });
                </script>

            <!-- ============================================================= -->
            <!-- ВКЛАДКА 3: НАСТРОЙКИ (ЯНДЕКС И GETCOURSE)                     -->
            <!-- ============================================================= -->
            <?php elseif ($tab === 'settings'): ?>
                <div class="card" style="max-width:none;padding:24px;border-radius:10px;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,0.06);box-sizing:border-box;">
                    <form method="POST">
                        <?php wp_nonce_field('emko_settings_nonce'); ?>

                        <div style="border-bottom:1px solid #e5e7eb;padding-bottom:20px;margin-bottom:20px;">
                            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                                <div>
                                    <h2 style="margin:0 0 4px 0;">1. Подключение Яндекс (Календарь и Телемост)</h2>
                                    <p class="description" style="margin:0;">Через этот аккаунт плагин проверяет занятость слотов, создает встречи в календаре и генерирует ссылки на Яндекс Телемост.</p>
                                </div>
                                <button type="button" id="btn-test-yandex" class="button button-secondary">
                                    🔍 Проверить подключение к Яндексу
                                </button>
                            </div>

                            <div id="yandex-test-result" style="margin-top:12px;"></div>

                            <table class="form-table" style="margin-top:10px;">
                                <tr>
                                    <th scope="row">Почта Яндекс (организатор) *</th>
                                    <td>
                                        <input type="email" id="input_yandex_email" name="yandex_email" value="<?php echo esc_attr(get_option('emko_yandex_email', 'eminxx@ya.ru')); ?>" class="regular-text" required />
                                        <p class="description">Email личного аккаунта Яндекс или Яндекс 360 (например: <code>user@ya.ru</code> или <code>admin@vash-domen.ru</code>).</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Пароль приложения Яндекса *</th>
                                    <td>
                                        <input type="password" id="input_yandex_password" name="yandex_password" placeholder="<?php echo get_option('emko_yandex_app_password') ? '••••••••••••••••' : ''; ?>" class="regular-text" />
                                        <p class="description">
                                            Специальный 16-значный пароль приложения для CalDAV. <br/>
                                            👉 <strong>Как получить за 1 минуту:</strong> перейдите в <a href="https://id.yandex.ru/security" target="_blank">id.yandex.ru/security</a> ➔ раздел «Пароли приложений» ➔ выберите тип «Календарь» ➔ скопируйте созданный пароль и вставьте сюда.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div>
                            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                                <div>
                                    <h2 style="margin:0 0 4px 0;">2. Подключение GetCourse (API заказов и допполей)</h2>
                                    <p class="description" style="margin:0;">Плагин автоматически обновляет заказ ученика в GetCourse: записывает дату встречи, ссылку на Телемост и переводит статус сделки.</p>
                                </div>
                                <button type="button" id="btn-test-getcourse" class="button button-secondary">
                                    ⚡ Проверить связь с GetCourse
                                </button>
                            </div>

                            <div id="getcourse-test-result" style="margin-top:12px;"></div>

                            <table class="form-table" style="margin-top:10px;">
                                <tr>
                                    <th scope="row">Аккаунт GetCourse</th>
                                    <td>
                                        <input type="text" id="input_gc_account" name="getcourse_account" value="<?php echo esc_attr(get_option('emko_getcourse_account', 'directinganimationru')); ?>" placeholder="например: directinganimationru" class="regular-text" />
                                        <p class="description">Поддомен школы (например, для <code>course.emko.academy</code> это технический поддомен аккаунта, например <code>directinganimationru</code>).</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Секретный ключ GetCourse API</th>
                                    <td>
                                        <input type="password" id="input_gc_key" name="getcourse_key" placeholder="<?php echo get_option('emko_getcourse_key') ? '••••••••••••••••' : ''; ?>" class="regular-text" />
                                        <p class="description">
                                            Секретный ключ API из GetCourse: «Профиль» ➔ «Настройки аккаунта» ➔ «Интеграция» ➔ «API».
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <p class="submit" style="margin-top:30px;border-top:1px solid #e5e7eb;padding-top:20px;">
                            <input type="submit" name="emko_save_settings" class="button button-primary button-hero" value="💾 Сохранить все настройки" />
                        </p>
                    </form>
                </div>

                <script>
                jQuery(document).ready(function($) {
                    var ajaxNonce = '<?php echo wp_create_nonce('emko_admin_ajax_nonce'); ?>';

                    // Тестирование Яндекса
                    $('#btn-test-yandex').on('click', function() {
                        var btn = $(this);
                        btn.prop('disabled', true).text('Проверка связи с CalDAV...');
                        var email = $('#input_yandex_email').val();
                        var password = $('#input_yandex_password').val();

                        $.post(ajaxurl, {
                            action: 'emko_test_yandex',
                            email: email,
                            password: password,
                            security: ajaxNonce
                        }, function(res) {
                            btn.prop('disabled', false).text('🔍 Проверить подключение к Яндексу');
                            if (res.success) {
                                $('#yandex-test-result').html('<div class="notice notice-success is-dismissible" style="padding:10px;"><p><strong>✓ Подключение успешно!</strong> ' + res.data.message + '</p></div>');
                            } else {
                                $('#yandex-test-result').html('<div class="notice notice-error is-dismissible" style="padding:10px;"><p><strong>✕ Ошибка подключения:</strong> ' + (res.data || 'Не удалось подключиться') + '</p></div>');
                            }
                        });
                    });

                    // Тестирование GetCourse
                    $('#btn-test-getcourse').on('click', function() {
                        var btn = $(this);
                        btn.prop('disabled', true).text('Проверка API GetCourse...');
                        var account = $('#input_gc_account').val();
                        var key = $('#input_gc_key').val();

                        $.post(ajaxurl, {
                            action: 'emko_test_getcourse',
                            account: account,
                            key: key,
                            security: ajaxNonce
                        }, function(res) {
                            btn.prop('disabled', false).text('⚡ Проверить связь с GetCourse');
                            if (res.success) {
                                $('#getcourse-test-result').html('<div class="notice notice-success is-dismissible" style="padding:10px;"><p><strong>✓ Соединение установлено!</strong> ' + res.data.message + '</p></div>');
                            } else {
                                $('#getcourse-test-result').html('<div class="notice notice-error is-dismissible" style="padding:10px;"><p><strong>✕ Ошибка GetCourse:</strong> ' + (res.data || 'Не удалось подключиться') + '</p></div>');
                            }
                        });
                    });
                });
                </script>

            <!-- ============================================================= -->
            <!-- ВКЛАДКА 4: ИНСТРУКЦИЯ И СВЯЗКА С GETCOURSE                    -->
            <!-- ============================================================= -->
            <?php elseif ($tab === 'instructions'): ?>
                <div class="card" style="max-width:none;padding:24px;border-radius:10px;line-height:1.7;box-shadow:0 1px 3px rgba(0,0,0,0.06);box-sizing:border-box;">
                    <h2 style="margin-top:0;">Полная механика и связка с GetCourse</h2>

                    <h3>Шаг 1. Размещение виджета на странице сайта WordPress</h3>
                    <p>Создайте страницу на сайте (например, <code>/booking/</code> или <code>/consultation/</code>) и добавьте шорткод:</p>
                    <pre style="background:#f3f4f6;padding:12px;border-radius:6px;font-size:14px;font-weight:600;color:#1e40af;">[emko_booking]</pre>

                    <h3>Шаг 2. Настройка заказа и ссылки в GetCourse</h3>
                    <p>Когда ученик оплачивает консультацию на GetCourse, процесс по заказам отправляет ему письмо со ссылкой на выбор времени консультации:</p>
                    <pre style="background:#f3f4f6;padding:12px;border-radius:6px;font-size:13px;word-break:break-all;">https://ваш-сайт.ru/booking/?deal_id={order_id}&name={first_name}&email={email}&phone={phone}</pre>
                    <p>Если консультация конкретного преподавателя, можно добавить параметр: <code>&teacher=teacher_1</code> — тогда виджет сразу откроет выбор даты и времени именно для него.</p>

                    <h3>Шаг 3. Дополнительные поля Заказа (Сделки) в GetCourse</h3>
                    <p>В GetCourse в разделе <em>«Настройки аккаунта» ➔ «Поля заказа»</em> создайте следующие 4 поля:</p>
                    <table class="widefat fixed striped" style="max-width:700px;margin:10px 0 20px 0;">
                        <thead>
                            <tr>
                                <th>Название поля в GetCourse</th>
                                <th>Тип поля</th>
                                <th>Назначение</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Дата и время консультации</strong></td>
                                <td>Строка</td>
                                <td>Плагин записывает выбранное время (например, <code>05.10.2026 в 15:00 МСК</code>)</td>
                            </tr>
                            <tr>
                                <td><strong>Преподаватель</strong></td>
                                <td>Строка</td>
                                <td>Имя выбранного преподавателя</td>
                            </tr>
                            <tr>
                                <td><strong>Ссылка на Телемост</strong></td>
                                <td>Строка</td>
                                <td>Уникальная ссылка на видеовстречу Яндекс Телемоста</td>
                            </tr>
                            <tr>
                                <td><strong>Статус консультации</strong></td>
                                <td>Выбор из списка</td>
                                <td>Значения: <code>Не записан</code>, <code>Записан</code>, <code>Напомнить</code>, <code>Завершена</code></td>
                            </tr>
                        </tbody>
                    </table>

                    <h3>Шаг 4. Сценарий Процесса в GetCourse</h3>
                    <ol style="margin-left:20px;">
                        <li><strong>При оплате заказа:</strong> поле «Статус консультации» = <code>Не записан</code>. Отправляется Письмо №1 с персональной ссылкой на выбор слота.</li>
                        <li><strong>После записи на сайте:</strong> плагин через API автоматически меняет статус сделки на <code>Записан</code> и заполняет ссылку на Телемост. В GetCourse срабатывает отправка подтверждающего Письма №2 с датой и ссылкой на звонок.</li>
                        <li><strong>За 1 час до встречи:</strong> плагин автоматически вызывает смену статуса на <code>Напомнить</code>, отправляя напоминание.</li>
                        <li><strong>Через 2 часа после встречи:</strong> процесс GetCourse отправляет контроль качества с двумя кнопками (Консультация состоялась / Не состоялась).</li>
                    </ol>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
}

