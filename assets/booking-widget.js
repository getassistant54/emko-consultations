function initBookingWidget() {
    const root = document.querySelector('.emko-booking-widget');
    if (!root) return;

    const apiBase = window.emkoBookingConfig?.apiUrl || '/wp-json/emko-booking/v1';

    // Parse URL params for GetCourse order pass-through
    const urlParams = new URLSearchParams(window.location.search);
    const preselectedTeacher = urlParams.get('teacher');
    const dealId = urlParams.get('deal_id') || urlParams.get('order_id') || urlParams.get('deal') || '';
    const isDemo = urlParams.has('demo') || urlParams.has('test') || urlParams.has('preview');

    // Storage & Cookie Helpers (shared across .emko.academy subdomains)
    function getSharedCookie(key) {
        try {
            const match = document.cookie.match(new RegExp('(?:^|;\\s*)' + key + '=([^;]+)'));
            return match ? decodeURIComponent(match[1]) : '';
        } catch (e) { return ''; }
    }

    function saveUserData(key, val) {
        if (!val || typeof val !== 'string') return;
        const cleanVal = val.trim();
        if (!cleanVal) return;
        try {
            localStorage.setItem(key, cleanVal);
            sessionStorage.setItem(key, cleanVal);
            const host = window.location.hostname;
            const domain = host.includes('emko.academy') ? '; domain=.emko.academy' : '';
            document.cookie = key + '=' + encodeURIComponent(cleanVal) + '; path=/; max-age=2592000' + domain;
        } catch (e) {}
    }

    // Always tag visitor on consultation booking page
    saveUserData('emko_order_type', 'consultation');

    let prefillPhone = urlParams.get('phone') || urlParams.get('user_phone') || '';
    if (!prefillPhone) {
        const rawMatch = window.location.search.match(/(?:\+|%2B)?([78]\d{10})/);
        if (rawMatch) {
            prefillPhone = '+' + rawMatch[1];
        }
    }
    // Fallback to browser storage
    if (!prefillPhone) {
        prefillPhone = localStorage.getItem('emko_user_phone') || sessionStorage.getItem('emko_user_phone') || getSharedCookie('emko_user_phone') || '';
    }

    let prefillName = urlParams.get('name') || urlParams.get('user_name') || urlParams.get('client_name') || urlParams.get('student_name') || '';
    if (!prefillName) {
        prefillName = localStorage.getItem('emko_user_name') || sessionStorage.getItem('emko_user_name') || getSharedCookie('emko_user_name') || '';
    }

    let prefillEmail = urlParams.get('email') || '';
    if (!prefillEmail) {
        prefillEmail = localStorage.getItem('emko_user_email') || sessionStorage.getItem('emko_user_email') || getSharedCookie('emko_user_email') || '';
    }

    // Persist discovered values
    if (prefillPhone) saveUserData('emko_user_phone', prefillPhone);
    if (prefillName) saveUserData('emko_user_name', prefillName);
    if (prefillEmail) saveUserData('emko_user_email', prefillEmail);

    // Auto-capture phone/name/email typed anywhere on page
    document.addEventListener('input', function (e) {
        const el = e.target;
        if (!el || !el.value) return;
        const n = ((el.name || '') + ' ' + (el.id || '') + ' ' + (el.className || '')).toLowerCase();
        const t = (el.type || '').toLowerCase();
        if (t === 'tel' || n.includes('phone')) {
            saveUserData('emko_user_phone', el.value);
            state.phone = el.value;
        } else if (t === 'email' || n.includes('email')) {
            saveUserData('emko_user_email', el.value);
            state.email = el.value;
        } else if (n.includes('name') && !n.includes('username')) {
            saveUserData('emko_user_name', el.value);
            state.name = el.value;
        }
    }, true);

    // Timezone detection
    const userTz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/Moscow';
    const cityRaw = userTz.split('/').pop().replace(/_/g, ' ');
    const userCity = cityRaw === 'Moscow' ? 'Москва' : cityRaw;

    // Test if user timezone is same as Moscow
    const nowSample = new Date();
    const mskSample = nowSample.toLocaleTimeString('ru-RU', { timeZone: 'Europe/Moscow', hour: '2-digit', minute: '2-digit' });
    const localSample = nowSample.toLocaleTimeString('ru-RU', { timeZone: userTz, hour: '2-digit', minute: '2-digit' });
    const isMskTimezone = (mskSample === localSample && (userTz === 'Europe/Moscow' || userTz.includes('Moscow')));

    // Helper: format slot times in both local and Moscow
    function getSlotTimes(ts) {
        const ms = (ts > 1e11 ? ts : ts * 1000);
        const d = new Date(ms);
        const mskStr = d.toLocaleTimeString('ru-RU', { timeZone: 'Europe/Moscow', hour: '2-digit', minute: '2-digit' });
        const localStr = d.toLocaleTimeString('ru-RU', { timeZone: userTz, hour: '2-digit', minute: '2-digit' });
        return { ms, mskStr, localStr, isSame: isMskTimezone };
    }

    // State
    let state = {
        teacher: null,
        date: null,
        dateFormatted: null,
        slot: null,
        dealId: dealId,
        name: prefillName,
        email: prefillEmail,
        phone: prefillPhone,
        note: ''
    };

    const stepGate = root.querySelector('#emko-step-gate');
    const stepTeacher = root.querySelector('#emko-step-teacher');
    const stepDateTime = root.querySelector('#emko-step-datetime');
    const stepForm = root.querySelector('#emko-step-form');
    const stepSuccess = root.querySelector('#emko-step-success');

    function showStep(stepEl) {
        if (!stepEl) return;
        root.querySelectorAll('.emko-step').forEach(s => s.classList.remove('active'));
        stepEl.classList.add('active');
        stepEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // 0. Check Gate: Require paid order (deal_id) or demo mode
    if (!dealId && !isDemo) {
        showStep(stepGate);
        return;
    }

    // 1. Load Teachers
    async function loadTeachers() {
        try {
            const res = await fetch(`${apiBase}/teachers`);
            const teachers = await res.json();

            if (!teachers || teachers.length === 0) {
                stepTeacher.innerHTML = '<p class="emko-empty-slots">В настоящий момент нет доступных преподавателей для записи.</p>';
                showStep(stepTeacher);
                return;
            }

            // Auto-select if passed in URL or if only 1 teacher exists
            if (preselectedTeacher) {
                const found = teachers.find(t => t.id === preselectedTeacher);
                if (found) {
                    selectTeacher(found);
                    return;
                }
            } else if (teachers.length === 1) {
                selectTeacher(teachers[0]);
                return;
            }

            const listEl = root.querySelector('.emko-teacher-list');
            listEl.innerHTML = teachers.map(t => `
                <div class="emko-teacher-card" data-id="${t.id}">
                    <div>
                        <div class="emko-teacher-name">${t.name}</div>
                        <div class="emko-teacher-role">${t.role || 'Консультант'} (${t.duration} мин)</div>
                    </div>
                    <span style="font-size:20px;color:#9ca3af;">➔</span>
                </div>
            `).join('');

            listEl.querySelectorAll('.emko-teacher-card').forEach(card => {
                card.addEventListener('click', () => {
                    const t = teachers.find(item => item.id === card.dataset.id);
                    selectTeacher(t);
                });
            });

            showStep(stepTeacher);
        } catch (e) {
            console.error('Error loading teachers:', e);
        }
    }

    function selectTeacher(t) {
        state.teacher = t;
        root.querySelector('#emko-selected-teacher-name').textContent = t.name;
        root.querySelector('#emko-selected-teacher-meta').textContent = `${t.role ? t.role + ' • ' : ''}${t.duration} минут`;
        generateDateStrip();
        showStep(stepDateTime);
    }

    // 2. Generate 14 days strip
    function generateDateStrip() {
        const strip = root.querySelector('.emko-date-strip');
        strip.innerHTML = '';
        const dayNames = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
        const months = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];

        const today = new Date();
        for (let i = 0; i < 14; i++) {
            const d = new Date(today);
            d.setDate(today.getDate() + i);

            const yyyy = d.getFullYear();
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const dd = String(d.getDate()).padStart(2, '0');
            const dateStr = `${yyyy}-${mm}-${dd}`;
            const dateFmt = `${d.getDate()} ${months[d.getMonth()]}`;

            const btn = document.createElement('div');
            btn.className = `emko-date-btn ${i === 0 ? 'active' : ''}`;
            btn.dataset.date = dateStr;
            btn.dataset.formatted = dateFmt;
            btn.innerHTML = `
                <div class="emko-day-name">${dayNames[d.getDay()]}</div>
                <div class="emko-day-num">${dateFmt}</div>
            `;
            btn.addEventListener('click', () => {
                strip.querySelectorAll('.emko-date-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                state.date = dateStr;
                state.dateFormatted = dateFmt;
                loadSlots(dateStr);
            });
            strip.appendChild(btn);

            if (i === 0) {
                state.date = dateStr;
                state.dateFormatted = dateFmt;
                loadSlots(dateStr);
            }
        }
    }

    // 3. Load Slots & Render Timezone Information
    async function loadSlots(dateStr) {
        const grid = root.querySelector('.emko-slots-grid');
        grid.innerHTML = '<div class="emko-empty-slots">Загрузка слотов...</div>';

        // Render Timezone Banner
        const tzBar = root.querySelector('#emko-tz-bar');
        if (tzBar) {
            if (isMskTimezone) {
                tzBar.innerHTML = `
                    <div class="emko-tz-pill">
                        <span>🕒 Время слотов указано по <strong>Москве (МСК, UTC+3)</strong></span>
                    </div>
                `;
            } else {
                tzBar.innerHTML = `
                    <div class="emko-tz-pill emko-tz-diff">
                        <div>🌐 Ваше местное время: <strong>${userCity}</strong> (показано крупным шрифтом)</div>
                        <div class="emko-tz-sub">Снизу на кнопках указано время преподавателя по Москве (МСК)</div>
                    </div>
                `;
            }
        }

        try {
            const res = await fetch(`${apiBase}/slots?teacher_id=${state.teacher.id}&date=${dateStr}`);
            const data = await res.json();

            if (!data.slots || data.slots.length === 0) {
                grid.innerHTML = `<div class="emko-empty-slots">${data.message || 'На эту дату нет свободных слотов'}</div>`;
                return;
            }

            grid.innerHTML = data.slots.map(s => {
                const times = getSlotTimes(s.timestamp);
                if (times.isSame) {
                    return `
                        <button type="button" class="emko-slot-btn" data-ts="${s.timestamp}" data-msk="${times.mskStr}" data-local="${times.localStr}">
                            <span class="emko-slot-time">${times.mskStr}</span>
                            <span class="emko-slot-local-time">МСК</span>
                        </button>
                    `;
                } else {
                    return `
                        <button type="button" class="emko-slot-btn emko-slot-tz" data-ts="${s.timestamp}" data-msk="${times.mskStr}" data-local="${times.localStr}">
                            <span class="emko-slot-time">${times.localStr}</span>
                            <span class="emko-slot-local-time">${times.mskStr} МСК</span>
                        </button>
                    `;
                }
            }).join('');

            grid.querySelectorAll('.emko-slot-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    grid.querySelectorAll('.emko-slot-btn').forEach(b => b.classList.remove('selected'));
                    btn.classList.add('selected');
                    state.slot = {
                        timestamp: btn.dataset.ts,
                        mskTime: btn.dataset.msk,
                        localTime: btn.dataset.local,
                        isSame: isMskTimezone
                    };
                    goToForm();
                });
            });
        } catch (e) {
            grid.innerHTML = '<div class="emko-empty-slots" style="color:red;">Ошибка загрузки слотов</div>';
        }
    }

    // 4. Form Step
    function goToForm() {
        const timeHtml = state.slot.isSame 
            ? `Время: <strong>${state.slot.mskTime} (МСК)</strong>` 
            : `Ваше местное время: <strong>${state.slot.localTime}</strong> <span style="color:#6b7280;font-size:13px;">(по Москве: ${state.slot.mskTime} МСК)</span>`;

        root.querySelector('#emko-form-details').innerHTML = 
            `<strong>${state.teacher.name}</strong> • ${state.dateFormatted || state.date}<br>${timeHtml}`;


        // Populate prefill fields
        if (!state.name) {
            state.name = localStorage.getItem('emko_user_name') || sessionStorage.getItem('emko_user_name') || getSharedCookie('emko_user_name') || '';
        }
        if (state.name) root.querySelector('#emko-input-name').value = state.name;

        if (state.email) {
            const emailInput = root.querySelector('#emko-input-email');
            emailInput.value = state.email;
            if (state.dealId) {
                // If tied to paid deal, keep email bound
                emailInput.readOnly = true;
                emailInput.style.backgroundColor = '#f9fafb';
            }
        }

        if (!state.phone) {
            state.phone = localStorage.getItem('emko_user_phone') || sessionStorage.getItem('emko_user_phone') || getSharedCookie('emko_user_phone') || '';
        }
        if (state.phone) root.querySelector('#emko-input-phone').value = state.phone;

        // Save on edit
        root.querySelector('#emko-input-phone')?.addEventListener('input', (e) => {
            state.phone = e.target.value;
            saveUserData('emko_user_phone', e.target.value);
        });
        root.querySelector('#emko-input-name')?.addEventListener('input', (e) => {
            state.name = e.target.value;
            saveUserData('emko_user_name', e.target.value);
        });

        showStep(stepForm);
    }

    // Back Buttons
    root.querySelector('#emko-back-to-teacher')?.addEventListener('click', () => showStep(stepTeacher));
    root.querySelector('#emko-back-to-slots')?.addEventListener('click', () => showStep(stepDateTime));

    // 5. Submit Booking
    root.querySelector('#emko-booking-form').addEventListener('submit', async function (e) {
        e.preventDefault();
        const submitBtn = root.querySelector('#emko-submit-btn');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Оформляем запись...';

        const payload = {
            teacher_id: state.teacher.id,
            timestamp: parseInt(state.slot.timestamp, 10),
            name: root.querySelector('#emko-input-name').value,
            email: root.querySelector('#emko-input-email').value,
            phone: root.querySelector('#emko-input-phone').value,
            note: root.querySelector('#emko-input-note').value,
            deal_id: state.dealId || ''
        };

        try {
            const res = await fetch(`${apiBase}/book`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const result = await res.json();
            if (result.success) {
                const dateText = state.slot.isSame 
                    ? `${result.datetime} (по МСК)` 
                    : `<strong>${state.slot.localTime}</strong> (по вашему времени)<br><span style="font-size:13px;color:#6b7280;">${result.datetime} по Москве (МСК)</span>`;

                root.querySelector('#emko-success-date').innerHTML = dateText;
                root.querySelector('#emko-success-teacher').textContent = result.teacher_name;
                
                const teleInput = root.querySelector('#emko-success-telemost-url');
                if (teleInput) teleInput.value = result.telemost_url;

                // Copy button
                const copyBtn = root.querySelector('#emko-btn-copy');
                if (copyBtn) {
                    copyBtn.onclick = () => {
                        navigator.clipboard.writeText(result.telemost_url);
                        copyBtn.textContent = 'Скопировано!';
                        setTimeout(() => { copyBtn.textContent = 'Копировать'; }, 2000);
                    };
                }

                // Google Calendar Link
                const sMs = (payload.timestamp > 1e11 ? payload.timestamp : payload.timestamp * 1000);
                const sDate = new Date(sMs);
                const eDate = new Date(sMs + (state.teacher.duration || 45) * 60 * 1000);
                const getMskIso = (d) => {
                    const parts = new Intl.DateTimeFormat('en-GB', {
                        timeZone: 'Europe/Moscow',
                        year: 'numeric', month: '2-digit', day: '2-digit',
                        hour: '2-digit', minute: '2-digit', second: '2-digit',
                        hour12: false
                    }).formatToParts(d);
                    const p = {};
                    parts.forEach(x => p[x.type] = x.value);
                    return `${p.year}${p.month}${p.day}T${p.hour}${p.minute}00`;
                };

                const sIso = getMskIso(sDate);
                const eIso = getMskIso(eDate);
                const gText = encodeURIComponent(`Консультация: ${result.teacher_name}`);
                const gDetails = encodeURIComponent(`Ссылка на Яндекс Телемост: ${result.telemost_url}\nВремя по Москве: ${result.datetime}`);
                const gLoc = encodeURIComponent(result.telemost_url);
                const gUrl = `https://calendar.google.com/calendar/render?action=TEMPLATE&text=${gText}&dates=${sIso}/${eIso}&ctz=Europe/Moscow&details=${gDetails}&location=${gLoc}`;
                
                const gBtn = root.querySelector('#emko-btn-google-cal');
                if (gBtn) gBtn.href = gUrl;

                // ICS download
                const fmt = (d) => d.toISOString().replace(/[-:]/g, "").slice(0, 15) + "Z";
                const icsContent = [
                    "BEGIN:VCALENDAR",
                    "VERSION:2.0",
                    "PRODID:-//Emko//Booking//RU",
                    "BEGIN:VEVENT",
                    `UID:${Date.now()}@emko.ru`,
                    `DTSTAMP:${fmt(new Date())}`,
                    `DTSTART:${fmt(sDate)}`,
                    `DTEND:${fmt(eDate)}`,
                    `SUMMARY:Консультация: ${result.teacher_name}`,
                    `DESCRIPTION:Ссылка на Яндекс Телемост: ${result.telemost_url}`,
                    `LOCATION:${result.telemost_url}`,
                    "END:VEVENT",
                    "END:VCALENDAR"
                ].join("\r\n");

                const blob = new Blob([icsContent], { type: "text/calendar;charset=utf-8" });
                const icsLink = root.querySelector('#emko-btn-download-ics');
                if (icsLink) {
                    icsLink.href = URL.createObjectURL(blob);
                    icsLink.download = `consultation-${state.date}.ics`;
                }

                showStep(stepSuccess);
            } else {
                alert('Ошибка при бронировании: ' + (result.message || 'Попробуйте другой слот'));
                submitBtn.disabled = false;
                submitBtn.textContent = 'Подтвердить запись';
            }
        } catch (err) {
            alert('Ошибка сети или сервера. Попробуйте еще раз.');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Подтвердить запись';
        }
    });

    loadTeachers();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBookingWidget);
} else {
    initBookingWidget();
}
