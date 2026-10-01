const account = "directinganimationru";
const secretKey = "qblQwd7zZ1la7NybyYEs0ZBa9P9wkWaIPf0im0Sr9eRuHE4OP2Eli99MetIgrDYne8E824rwgR1lUeQccTCSKDyBmWXlfUbl21UndFk5nVIWLh14QMM6v0q44xupGRar";
const orderId = 1779296486;

async function testUpdateWithoutStatus() {
  const apiUrl = `https://${account}.getcourse.ru/pl/api/deals`;

  const payload = {
    user: {
      email: "eminxx@ya.ru",
      group_name: ["Записан на консультацию"]
    },
    deal: {
      id: orderId,
      deal_fields: {
        "Дата и время консультации": "29.09.2026 в 11:00",
        "Преподаватель": "Елена Маркова",
        "Ссылка на Телемост": "https://telemost.yandex.ru/j/25147647652915"
      }
    }
  };

  const bodyParams = new URLSearchParams();
  bodyParams.append("action", "add");
  bodyParams.append("key", secretKey);
  bodyParams.append("params", Buffer.from(JSON.stringify(payload)).toString("base64"));

  const res = await fetch(apiUrl, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: bodyParams.toString()
  });

  const json = await res.json();
  console.log("Result without deal_status:\n", JSON.stringify(json, null, 2));
}

testUpdateWithoutStatus();
