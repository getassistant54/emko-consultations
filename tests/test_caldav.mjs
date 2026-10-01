const email = "eminxx@ya.ru";
const pass = "yovntuopdvswhvcb";
const auth = Buffer.from(`${email}:${pass}`).toString("base64");

async function propfind(url, body, depth = "0") {
  const fullUrl = url.startsWith("http") ? url : `https://caldav.yandex.ru${url}`;
  const res = await fetch(fullUrl, {
    method: "PROPFIND",
    headers: {
      "Authorization": `Basic ${auth}`,
      "Depth": depth,
      "Content-Type": "application/xml; charset=utf-8"
    },
    body
  });
  return { status: res.status, text: await res.text() };
}

async function discover() {
  console.log("=== 1. Discover current-user-principal ===");
  const p1 = await propfind("/", `<?xml version="1.0" encoding="utf-8" ?>
<D:propfind xmlns:D="DAV:">
  <D:prop>
    <D:current-user-principal/>
  </D:prop>
</D:propfind>`);
  console.log("P1 response:\n", p1.text);

  // Extract principal href
  const principalMatch = p1.text.match(/<current-user-principal[^>]*>.*?<href[^>]*>(.*?)<\/href>/is) ||
                         p1.text.match(/<D:current-user-principal[^>]*>.*?<D:href[^>]*>(.*?)<\/D:href>/is);
  const principalHref = principalMatch ? principalMatch[1].trim() : `/principals/users/${email}/`;
  console.log("Principal Href:", principalHref);

  console.log("\n=== 2. Discover calendar-home-set ===");
  const p2 = await propfind(principalHref, `<?xml version="1.0" encoding="utf-8" ?>
<D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:prop>
    <C:calendar-home-set/>
  </D:prop>
</D:propfind>`);
  console.log("P2 response:\n", p2.text);

  const homeMatch = p2.text.match(/<calendar-home-set[^>]*>.*?<href[^>]*>(.*?)<\/href>/is) ||
                    p2.text.match(/<C:calendar-home-set[^>]*>.*?<D:href[^>]*>(.*?)<\/D:href>/is);
  const homeHref = homeMatch ? homeMatch[1].trim() : `/calendars/${email}/`;
  console.log("Calendar Home Set:", homeHref);

  console.log("\n=== 3. List calendars ===");
  const p3 = await propfind(homeHref, `<?xml version="1.0" encoding="utf-8" ?>
<D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:prop>
    <D:displayname/>
    <D:resourcetype/>
    <C:supported-calendar-component-set/>
  </D:prop>
</D:propfind>`, "1");
  console.log("P3 response:\n", p3.text);
}

discover();
