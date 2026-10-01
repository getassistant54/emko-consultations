import crypto from "crypto";

const email = "eminxx@ya.ru";
const pass = "yovntuopdvswhvcb";
const auth = Buffer.from(`${email}:${pass}`).toString("base64");
const calHref = "/calendars/eminxx%40ya.ru/events-381685793656155/";

async function testCreateTelemostEvent() {
  const uid = crypto.randomUUID();
  const eventUrl = `https://caldav.yandex.ru${calHref}${uid}.ics`;

  // Generate a random 14-digit room number for Telemost
  // e.g. 25 + 12 digits
  const randomRoomId = "25" + Array.from({length: 12}, () => Math.floor(Math.random() * 10)).join("");
  const telemostUrl = `https://telemost.yandex.ru/j/${randomRoomId}`;

  const eventDate = new Date();
  eventDate.setDate(eventDate.getDate() + 2); // 2 days from now
  eventDate.setUTCHours(12, 0, 0, 0); // 15:00 MSK
  const endDate = new Date(eventDate.getTime() + 45 * 60 * 1000); // 45 min

  const formatICSDate = (d) => d.toISOString().replace(/[-:]/g, "").slice(0, 15) + "Z";
  const dtStart = formatICSDate(eventDate);
  const dtEnd = formatICSDate(endDate);
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
    "SUMMARY:Консультация с учеником (Авто-Телемост)",
    `DESCRIPTION:Ссылка на звонок: ${telemostUrl}\\nСтудент: Анна Смирнова\\nEmail: anna@test.ru`,
    `CONFERENCE;FEATURE=VIDEO;VALUE=URI:${telemostUrl}`,
    `X-TELEMOST-CONFERENCE:${telemostUrl}`,
    "STATUS:CONFIRMED",
    "TRANSP:OPAQUE",
    "END:VEVENT",
    "END:VCALENDAR"
  ].join("\r\n");

  const res = await fetch(eventUrl, {
    method: "PUT",
    headers: {
      "Authorization": `Basic ${auth}`,
      "Content-Type": "text/calendar; charset=utf-8"
    },
    body: icsData
  });

  console.log("Create Telemost event status:", res.status);

  // Fetch back
  const getRes = await fetch(eventUrl, {
    headers: { "Authorization": `Basic ${auth}` }
  });
  console.log("Event back from Yandex:\n", await getRes.text());
}

testCreateTelemostEvent();
