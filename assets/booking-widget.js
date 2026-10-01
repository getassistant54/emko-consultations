document.addEventListener('DOMContentLoaded', function () {
    const root = document.querySelector('.emko-booking-widget');
    if (!root) return;

    const apiBase = window.emkoBookingConfig?.apiUrl || '/wp-json/emko-booking/v1';

    // Parse URL params for GetCourse pass-through
    const urlParams = new URLSearchParams(window.location.search);
    const preselectedTeacher = urlParams.get('teacher');
    const prefillName = urlParams.get('name') || '';
    const prefillEmail = urlParams.get('email') || '';
    let prefillPhone = urlParams.get('phone') || urlParams.get('user_phone') || '';
    if (!prefillPhone) {
        const rawMatch = window.location.search.match(/(?:\+|%2B)?([78]\d{10})/);
        if (rawMatch) {
            prefillPhone = '+' + rawMatch[1];
        }
    }

    // State
    let state = {
        teacher: null,
        date: null,
        slot: null,
        name: prefillName,
        email: prefillEmail,
        phone: prefillPhone,
        note: ''
    };

    const stepTeacher = root.querySelector('#emko-step-teacher');
    const stepDateTime = root.querySelector('#emko-step-datetime');
    const stepForm = root.querySelector('#emko-step-form');
    const stepSuccess = root.querySelector('#emko-step-success');

    function showStep(stepEl) {
        root.querySelectorAll('.emko-step').forEach(s => s.classList.remove('active'));
        stepEl.classList.add('active');
    }

    // 1. Load Teachers
    async function loadTeachers() {
        try {
            const res = await fetch(`${apiBase}/teachers`);
            const teachers = await res.json();

            if (!teachers || teachers.length === 0) {
                stepTeacher.innerHTML = '<p class="emko-empty-slots">В настоящий момент нет доступных преподавателей для записи.</p>';
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

            const btn = document.createElement('div');
            btn.className = `emko-date-btn ${i === 0 ? 'active' : ''}`;
            btn.dataset.date = dateStr;
            btn.innerHTML = `
                <div class="emko-day-name">${dayNames[d.getDay()]}</div>
                <div class="emko-day-num">${d.getDate()} ${months[d.getMonth()]}</div>
            `;
            btn.addEventListener('click', () => {
                strip.querySelectorAll('.emko-date-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                state.date = dateStr;
                loadSlots(dateStr);
            });
            strip.appendChild(btn);

            if (i === 0) {
                state.date = dateStr;
                loadSlots(dateStr);
            }
        }
    }

    // 3. Load Slots
    async function loadSlots(dateStr) {
        const grid = root.querySelector('.emko-slots-grid');
        grid.innerHTML = '<div class="emko-empty-slots">Загрузка слотов...</div>';

        try {
            const res = await fetch(`${apiBase}/slots?teacher_id=${state.teacher.id}&date=${dateStr}`);
            const data = await res.json();

            if (!data.slots || data.slots.length === 0) {
                grid.innerHTML = `<div class="emko-empty-slots">${data.message || 'На эту дату нет свободных слотов'}</div>`;
                return;
            }

            grid.innerHTML = data.slots.map(s => `
                <button type="button" class="emko-slot-btn" data-ts="${s.timestamp}" data-time="${s.time}">
                    ${s.time}
                </button>
            `).join('');

            grid.querySelectorAll('.emko-slot-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    grid.querySelectorAll('.emko-slot-btn').forEach(b => b.classList.remove('selected'));
                    btn.classList.add('selected');
                    state.slot = {
                        timestamp: btn.dataset.ts,
                        time: btn.dataset.time
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
        root.querySelector('#emko-form-details').textContent = 
            `${state.teacher.name} • ${state.date} в ${state.slot.time}`;

        // Populate prefill fields
        if (state.name) root.querySelector('#emko-input-name').value = state.name;
        if (state.email) root.querySelector('#emko-input-email').value = state.email;
        if (state.phone) root.querySelector('#emko-input-phone').value = state.phone;

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
            timestamp: state.slot.timestamp,
            name: root.querySelector('#emko-input-name').value,
            email: root.querySelector('#emko-input-email').value,
            phone: root.querySelector('#emko-input-phone').value,
            note: root.querySelector('#emko-input-note').value
        };

        try {
            const res = await fetch(`${apiBase}/book`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const result = await res.json();
            if (result.success) {
                root.querySelector('#emko-success-date').textContent = result.datetime;
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
                const sDate = new Date(payload.timestamp);
                const eDate = new Date(payload.timestamp + (state.teacher.duration || 45) * 60 * 1000);
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
                const gDetails = encodeURIComponent(`Ссылка на видеовстречу: ${result.telemost_url}\nВремя по Москве (МСК)`);
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
});
