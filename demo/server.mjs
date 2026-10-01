import http from "http";
import fs from "fs";
import path from "path";
import crypto from "crypto";
import { fileURLToPath } from "url";
import { sendBookingToGetCourse } from "./getcourse-sender.mjs";

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const PORT = 3888;
const EMAIL = "eminxx@ya.ru";
const PASS = "yovntuopdvswhvcb";
const AUTH = Buffer.from(`${EMAIL}:${PASS}`).toString("base64");

// Конфигурация преподавателей для демо
const TEACHERS = {
  teacher_1: {
    id: "teacher_1",
    name: "Елена Маркова",
    role: "Режиссура и визуальный сторителлинг",
    calendar_href: "/calendars/eminxx%40ya.ru/events-381685793656155/", // календарь 1
    duration: 45, // мин
    buffer: 15,   // мин
    work_start: "10:00",
    work_end: "20:00"
  },
  teacher_2: {
    id: "teacher_2",
    name: "Алексей Смирнов",
    role: "Продюсирование и пост-продакшн",
    calendar_href: "/calendars/eminxx%40ya.ru/events-381685965832239/", // Каелндарь 2
    duration: 60,
    buffer: 15,
    work_start: "11:00",
    work_end: "19:00"
  }
};

// Запрос занятых интервалов из CalDAV
async function getBusyIntervals(calHref, dateStr) {
  const startIso = `${dateStr.replace(/-/g, "")}T000000Z`;
  const endIso = `${dateStr.replace(/-/g, "")}T235959Z`;

  const query = `<?xml version="1.0" encoding="utf-8" ?>
<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:prop>
    <C:calendar-data/>
  </D:prop>
  <C:filter>
    <C:comp-filter name="VCALENDAR">
      <C:comp-filter name="VEVENT">
        <C:time-range start="${startIso}" end="${endIso}"/>
      </C:comp-filter>
    </C:comp-filter>
  </C:filter>
</C:calendar-query>`;

  try {
    const res = await fetch(`https://caldav.yandex.ru${calHref}`, {
      method: "REPORT",
      headers: {
        Authorization: `Basic ${AUTH}`,
        Depth: "1",
        "Content-Type": "application/xml; charset=utf-8"
      },
      body: query
    });

    const text = await res.text();
    const intervals = [];
    const eventMatches = text.matchAll(/BEGIN:VEVENT([\s\S]*?)END:VEVENT/gi);

    for (const match of eventMatches) {
      const ev = match[1];
      const startM = ev.match(/DTSTART[^:]*:(.*?)\r?\n/);
      const endM = ev.match(/DTEND[^:]*:(.*?)\r?\n/);
      if (startM) {
        const sTs = parseIcsDate(startM[1]);
        const eTs = endM ? parseIcsDate(endM[1]) : sTs + 3600000;
        intervals.push({ start: sTs, end: eTs });
      }
    }
    return intervals;
  } catch (err) {
    console.error("CalDAV read error:", err);
    return [];
  }
}

function parseIcsDate(dStr) {
  const clean = dStr.trim();
  if (clean.endsWith("Z")) {
    const y = clean.slice(0, 4);
    const m = clean.slice(4, 6);
    const d = clean.slice(6, 8);
    const h = clean.slice(9, 11);
    const min = clean.slice(11, 13);
    const s = clean.slice(13, 15);
    return new Date(`${y}-${m}-${d}T${h}:${min}:${s}Z`).getTime();
  }
  return new Date(clean).getTime();
}

// Создание события в CalDAV со ссылкой на Телемост
async function createBooking(teacher, slotTs, student) {
  const uid = crypto.randomUUID();
  const eventUrl = `https://caldav.yandex.ru${teacher.calendar_href}${uid}.ics`;

  const randomRoom = "25" + Array.from({ length: 12 }, () => Math.floor(Math.random() * 10)).join("");
  const telemostUrl = `https://telemost.yandex.ru/j/${randomRoom}`;

  const startDate = new Date(slotTs);
  const endDate = new Date(slotTs + teacher.duration * 60 * 1000);

  const formatICS = (d) => d.toISOString().replace(/[-:]/g, "").slice(0, 15) + "Z";
  const dtStart = formatICS(startDate);
  const dtEnd = formatICS(endDate);
  const dtStamp = formatICS(new Date());

  const icsData = [
    "BEGIN:VCALENDAR",
    "VERSION:2.0",
    "PRODID:-//Emko Demo//RU",
    "CALSCALE:GREGORIAN",
    "BEGIN:VEVENT",
    `UID:${uid}`,
    `DTSTAMP:${dtStamp}`,
    `DTSTART:${dtStart}`,
    `DTEND:${dtEnd}`,
    `SUMMARY:Консультация: ${student.name}`,
    `DESCRIPTION:Ссылка на звонок: ${telemostUrl}\\nУченик: ${student.name}\\nEmail: ${student.email}\\nТелефон: ${student.phone}\\nПреподаватель: ${teacher.name}\\nВопрос: ${student.note || "—"}`,
    `CONFERENCE;FEATURE=VIDEO;VALUE=URI:${telemostUrl}`,
    `X-TELEMOST-CONFERENCE:${telemostUrl}`,
    `ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE;CN=${student.name}:mailto:${student.email}`,
    "STATUS:CONFIRMED",
    "TRANSP:OPAQUE",
    "END:VEVENT",
    "END:VCALENDAR"
  ].join("\r\n");

  const res = await fetch(eventUrl, {
    method: "PUT",
    headers: {
      Authorization: `Basic ${AUTH}`,
      "Content-Type": "text/calendar; charset=utf-8"
    },
    body: icsData
  });

  return {
    success: res.status >= 200 && res.status < 300,
    telemostUrl,
    startStr: startDate.toLocaleString("ru-RU", { timeZone: "Europe/Moscow" })
  };
}

// HTTP Сервер
const server = http.createServer(async (req, res) => {
  const urlObj = new URL(req.url, `http://${req.headers.host}`);
  const pathname = urlObj.pathname;

  // CORS headers
  res.setHeader("Access-Control-Allow-Origin", "*");
  res.setHeader("Access-Control-Allow-Methods", "GET, POST, OPTIONS");
  res.setHeader("Access-Control-Allow-Headers", "Content-Type");

  if (req.method === "OPTIONS") {
    res.writeHead(204);
    res.end();
    return;
  }

  // 1. API: Список преподавателей
  if (pathname === "/api/teachers" && req.method === "GET") {
    res.writeHead(200, { "Content-Type": "application/json; charset=utf-8" });
    res.end(JSON.stringify(Object.values(TEACHERS)));
    return;
  }

  // 2. API: Получение слотов
  if (pathname === "/api/slots" && req.method === "GET") {
    const teacherId = urlObj.searchParams.get("teacher_id");
    const dateStr = urlObj.searchParams.get("date"); // YYYY-MM-DD

    const teacher = TEACHERS[teacherId];
    if (!teacher || !dateStr) {
      res.writeHead(400, { "Content-Type": "application/json" });
      res.end(JSON.stringify({ error: "Missing parameters" }));
      return;
    }

    // Загружаем занятые интервалы из Яндекса
    const busyIntervals = await getBusyIntervals(teacher.calendar_href, dateStr);

    // Генерируем слоты строго по МСК (+03:00)
    const [startH, startM] = teacher.work_start.split(":").map(Number);
    const [endH, endM] = teacher.work_end.split(":").map(Number);

    const pad = (n) => String(n).padStart(2, "0");
    const startTimeTs = new Date(`${dateStr}T${pad(startH)}:${pad(startM)}:00+03:00`).getTime();
    const endTimeTs = new Date(`${dateStr}T${pad(endH)}:${pad(endM)}:00+03:00`).getTime();

    const stepMs = (teacher.duration + teacher.buffer) * 60 * 1000;
    const durationMs = teacher.duration * 60 * 1000;
    const now = Date.now();

    const slots = [];
    for (let t = startTimeTs; t + durationMs <= endTimeTs; t += stepMs) {
      const slotEnd = t + durationMs;

      // Не показываем прошедшие часы
      if (t < now) continue;

      // Проверка пересечения с занятыми в Яндексе
      const isBusy = busyIntervals.some((b) => t < b.end && slotEnd > b.start);
      if (!isBusy) {
        const slotDate = new Date(t);
        const timeStr = slotDate.toLocaleTimeString("ru-RU", {
          hour: "2-digit",
          minute: "2-digit",
          timeZone: "Europe/Moscow"
        });
        slots.push({
          time: timeStr,
          timestamp: t,
          duration: teacher.duration
        });
      }
    }

    res.writeHead(200, { "Content-Type": "application/json; charset=utf-8" });
    res.end(JSON.stringify({ slots }));
    return;
  }

  // 3. API: Бронирование
  if (pathname === "/api/book" && req.method === "POST") {
    let body = "";
    req.on("data", (chunk) => (body += chunk));
    req.on("end", async () => {
      try {
        const data = JSON.parse(body);
        const teacher = TEACHERS[data.teacher_id];
        if (!teacher || !data.timestamp || !data.email) {
          res.writeHead(400, { "Content-Type": "application/json" });
          res.end(JSON.stringify({ success: false, message: "Не все поля заполнены" }));
          return;
        }

        const booking = await createBooking(teacher, Number(data.timestamp), {
          name: data.name,
          email: data.email,
          phone: data.phone,
          note: data.note
        });

        if (booking.success) {
          // Отправка в GetCourse (обновление сделки)
          const dealId = data.deal_id;
          sendBookingToGetCourse(data.email, dealId, {
            datetimeStr: booking.startStr,
            teacherName: teacher.name,
            telemostUrl: booking.telemostUrl
          });

          // Планируем напоминание за 1 час
          const slotTs = Number(data.timestamp);
          const remindDelay = slotTs - 3600000 - Date.now();
          if (remindDelay > 0 && dealId) {
            console.log(`[Timer] Запланировано напоминание через ${Math.round(remindDelay / 1000 / 60)} мин для #${dealId}`);
            setTimeout(() => {
              sendReminderToGetCourse(data.email, dealId);
            }, remindDelay);
          }

          res.writeHead(200, { "Content-Type": "application/json; charset=utf-8" });
          res.end(
            JSON.stringify({
              success: true,
              message: "Вы успешно записаны!",
              telemost_url: booking.telemostUrl,
              datetime: booking.startStr,
              teacher_name: teacher.name
            })
          );
        } else {
          res.writeHead(500, { "Content-Type": "application/json" });
          res.end(JSON.stringify({ success: false, message: "Ошибка сохранения в календаре" }));
        }
      } catch (err) {
        res.writeHead(500, { "Content-Type": "application/json" });
        res.end(JSON.stringify({ success: false, message: err.message }));
      }
    });
    return;
  }

  // 4. Статика (public/index.html)
  let filePath = path.join(__dirname, "public", pathname === "/" ? "index.html" : pathname);
  if (fs.existsSync(filePath) && fs.statSync(filePath).isFile()) {
    const ext = path.extname(filePath);
    const contentTypes = {
      ".html": "text/html; charset=utf-8",
      ".js": "application/javascript",
      ".css": "text/css"
    };
    res.writeHead(200, { "Content-Type": contentTypes[ext] || "text/plain" });
    fs.createReadStream(filePath).pipe(res);
    return;
  }

  // 404
  res.writeHead(404, { "Content-Type": "text/plain; charset=utf-8" });
  res.end("404 Not Found");
});

server.listen(PORT, () => {
  console.log(`=======================================================`);
  console.log(`🚀 Сервер запущен на http://localhost:${PORT}`);
  console.log(`Тест редиректа из GetCourse:`);
  console.log(`👉 http://localhost:${PORT}/?name=Елена&email=eminxx@ya.ru&phone=+79991234567&teacher=teacher_1`);
  console.log(`=======================================================`);
});
