import crypto from "crypto";

const email = "eminxx@ya.ru";
const pass = "yovntuopdvswhvcb";
const auth = Buffer.from(`${email}:${pass}`).toString("base64");

// We'll create an event in "календарь 1"
const calHref = "/calendars/eminxx%40ya.ru/events-381685793656155/";

async function createEvent() {
  const uid = crypto.randomUUID();
  const eventUrl = `https://caldav.yandex.ru${calHref}${uid}.ics`;

  // Set tomorrow 14:00 to 15:00 UTC
  const tomorrow = new Date();
  tomorrow.setDate(tomorrow.getDate() + 1);
  tomorrow.setUTCHours(11, 0, 0, 0); // 11:00 UTC = 14:00 MSK
  const end = new Date(tomorrow.getTime() + 60 * 60 * 1000);

  const formatICSDate = (d) => d.toISOString().replace(/[-:]/g, "").slice(0, 15) + "Z";
  const dtStart = formatICSDate(tomorrow);
  const dtEnd = formatICSDate(end);
  const dtStamp = formatICSDate(new Date());

  const icsData = [
    "BEGIN:VCALENDAR",
    "VERSION:2.0",
    "PRODID:-//GetCourse Booking//RU",
    "CALSCALE:GREGORIAN",
    "BEGIN:VEVENT",
    `UID:${uid}`,
    `DTSTAMP:${dtStamp}`,
    `DTSTART:${dtStart}`,
    `DTEND:${dtEnd}`,
    "SUMMARY:Тестовая консультация (GetCourse)",
    "DESCRIPTION:Запись ученика: Иван Иванов\\nКурс: Режиссура\\nПреподаватель: Преподаватель 1",
    "STATUS:CONFIRMED",
    "X-TELEMOST:TRUE",
    "X-YANDEX-TELEMOST:1",
    "END:VEVENT",
    "END:VCALENDAR"
  ].join("\r\n");

  console.log("Putting event to:", eventUrl);

  const res = await fetch(eventUrl, {
    method: "PUT",
    headers: {
      "Authorization": `Basic ${auth}`,
      "Content-Type": "text/calendar; charset=utf-8"
    },
    body: icsData
  });

  console.log("Create Event Status:", res.status, res.statusText);
  const text = await res.text();
  console.log("Response:", text);

  // Now let's fetch back the created event to see what Yandex added!
  const getRes = await fetch(eventUrl, {
    headers: {
      "Authorization": `Basic ${auth}`
    }
  });
  console.log("Get Event Status:", getRes.status);
  const getIcs = await getRes.text();
  console.log("Created Event Content from Yandex:\n", getIcs);
}

createEvent();
