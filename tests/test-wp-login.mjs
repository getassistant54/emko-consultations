const loginUrl = "https://emko.academy/wp-login.php?pass=1";
const user = "Gemini";
const pass = "S@eOthwd@dB";

async function testLogin() {
  console.log("Connecting to", loginUrl);
  
  // 1. GET login page to get initial cookies and test connectivity
  const getRes = await fetch(loginUrl, {
    headers: {
      "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
    }
  });
  
  console.log("Initial GET status:", getRes.status);
  const initialCookies = getRes.headers.getSetCookie ? getRes.headers.getSetCookie() : [getRes.headers.get("set-cookie")].filter(Boolean);
  console.log("Initial cookies:", initialCookies.map(c => c.split(";")[0]));
  
  // 2. POST login form
  const body = new URLSearchParams();
  body.append("log", user);
  body.append("pwd", pass);
  body.append("rememberme", "forever");
  body.append("wp-submit", "Войти");
  body.append("redirect_to", "https://emko.academy/wp-admin/");

  const cookieHeader = initialCookies.map(c => c.split(";")[0]).join("; ");

  const postRes = await fetch(loginUrl, {
    method: "POST",
    headers: {
      "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
      "Content-Type": "application/x-www-form-urlencoded",
      "Cookie": cookieHeader
    },
    body: body.toString(),
    redirect: "manual"
  });

  console.log("POST status:", postRes.status);
  console.log("Location:", postRes.headers.get("location"));
  const postCookies = postRes.headers.getSetCookie ? postRes.headers.getSetCookie() : [postRes.headers.get("set-cookie")].filter(Boolean);
  console.log("Post cookies count:", postCookies.length);
  postCookies.forEach(c => console.log(" - Cookie:", c.split(";")[0]));

  const allCookies = [...initialCookies, ...postCookies].map(c => c.split(";")[0]).join("; ");

  // 3. Follow redirect or fetch wp-admin
  const adminRes = await fetch("https://emko.academy/wp-admin/", {
    headers: {
      "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
      "Cookie": allCookies
    }
  });

  console.log("wp-admin status:", adminRes.status);
  const adminHtml = await adminRes.text();
  console.log("wp-admin title:", adminHtml.match(/<title>(.*?)<\/title>/i)?.[1] || "no title");

  if (adminHtml.includes("dashboard") || adminHtml.includes("Консоль") || adminHtml.includes("wp-admin")) {
    console.log("SUCCESSFULLY LOGGED IN TO WORDPRESS ADMIN!");
  } else {
    console.log("Failed to enter admin. First 500 chars:\n", adminHtml.slice(0, 500));
  }
}

testLogin().catch(err => console.error("Error:", err));
