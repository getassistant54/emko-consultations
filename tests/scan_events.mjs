const email = "eminxx@ya.ru";
const pass = "yovntuopdvswhvcb";
const auth = Buffer.from(`${email}:${pass}`).toString("base64");

const calendars = [
  "/calendars/eminxx%40ya.ru/events-1469795/",
  "/calendars/eminxx%40ya.ru/events-381685965832239/",
  "/calendars/eminxx%40ya.ru/events-381685793656155/"
];

async function scanCalendars() {
  const now = new Date();
  const start = new Date(now.getTime() - 2 * 24 * 3600 * 1000).toISOString().replace(/[-:]/g, "").slice(0, 15) + "Z";
  const end = new Date(now.getTime() + 14 * 24 * 3600 * 1000).toISOString().replace(/[-:]/g, "").slice(0, 15) + "Z";

  const query = `<?xml version="1.0" encoding="utf-8" ?>
<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:prop>
    <D:getetag/>
    <C:calendar-data/>
  </D:prop>
  <C:filter>
    <C:comp-filter name="VCALENDAR">
      <C:comp-filter name="VEVENT">
        <C:time-range start="${start}" end="${end}"/>
      </C:comp-filter>
    </C:comp-filter>
  </C:filter>
</C:calendar-query>`;

  for (const calHref of calendars) {
    const fullUrl = `https://caldav.yandex.ru${calHref}`;
    const res = await fetch(fullUrl, {
      method: "REPORT",
      headers: {
        "Authorization": `Basic ${auth}`,
        "Depth": "1",
        "Content-Type": "application/xml; charset=utf-8"
      },
      body: query
    });

    const text = await res.text();
    console.log(`\n================== CALENDAR: ${calHref} ==================`);
    // Extract VCALENDAR blocks
    const events = text.match(/BEGIN:VCALENDAR[\s\S]*?END:VCALENDAR/g);
    if (!events || events.length === 0) {
      console.log("No events found in this date range.");
    } else {
      for (const ev of events) {
        console.log("Found event:\n", ev);
      }
    }
  }
}

scanCalendars();
