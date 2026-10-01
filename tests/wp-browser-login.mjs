import puppeteer from "file:///d:/РАБОТА/ЕМКО-АНИМАЦИЯ/Gemini/local-demo/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js";

const edgePath = "C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe";
const loginUrl = "https://emko.academy/wp-login.php?pass=1";
const user = "Gemini";
const pass = "S@eOthwd@dB";

async function login() {
  console.log("Launching Edge browser...");
  const browser = await puppeteer.launch({
    executablePath: edgePath,
    headless: "new",
    args: ["--no-sandbox", "--disable-setuid-sandbox", "--disable-blink-features=AutomationControlled"]
  });

  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 800 });

  console.log("Navigating to login URL:", loginUrl);
  await page.goto(loginUrl, { waitUntil: "networkidle2", timeout: 30000 });

  console.log("Current page title:", await page.title());
  console.log("Current URL:", page.url());

  // Check if we are on the bot protection redirect page
  if (page.url().includes("pass=1") && (await page.$("#user_login")) === null) {
    console.log("Waiting for possible redirect...");
    await new Promise(r => setTimeout(r, 2000));
  }

  // Look for login fields
  await page.waitForSelector("#user_login", { timeout: 15000 });
  console.log("Found login form. Typing credentials...");

  await page.type("#user_login", user, { delay: 50 });
  await page.type("#user_pass", pass, { delay: 50 });

  console.log("Submitting login form...");
  await Promise.all([
    page.waitForNavigation({ waitUntil: "networkidle2", timeout: 30000 }),
    page.click("#wp-submit")
  ]);

  console.log("After login URL:", page.url());
  console.log("After login title:", await page.title());

  const content = await page.content();
  if (page.url().includes("wp-admin")) {
    console.log("SUCCESS! Successfully logged into WordPress Admin!");

    // Audit site details
    console.log("\nAuditing WordPress environment...");
    await page.goto("https://emko.academy/wp-admin/plugins.php", { waitUntil: "networkidle2" });
    const pluginsTitle = await page.title();
    console.log("Plugins page title:", pluginsTitle);

    const plugins = await page.evaluate(() => {
      const rows = document.querySelectorAll(".wp-list-table tr[data-plugin]");
      return Array.from(rows).map(r => {
        const nameEl = r.querySelector(".plugin-title strong");
        const active = r.classList.contains("active");
        return { name: nameEl ? nameEl.innerText.trim() : "Unknown", active };
      });
    });

    console.log("\nInstalled plugins (" + plugins.length + "):");
    plugins.forEach(p => console.log(` - [${p.active ? "АКТИВЕН" : "ОТКЛЮЧЕН"}] ${p.name}`));

  } else {
    console.log("Did not reach wp-admin. Page content snippet:\n", content.slice(0, 1000));
    const loginError = await page.$eval("#login_error", el => el.innerText).catch(() => null);
    if (loginError) {
      console.log("Login Error message:", loginError);
    }
  }

  await browser.close();
}

login().catch(err => console.error("Browser login error:", err));
