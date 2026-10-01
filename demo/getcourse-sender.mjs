const GC_ACCOUNT = "directinganimationru";
const GC_KEY = "qblQwd7zZ1la7NybyYEs0ZBa9P9wkWaIPf0im0Sr9eRuHE4OP2Eli99MetIgrDYne8E824rwgR1lUeQccTCSKDyBmWXlfUbl21UndFk5nVIWLh14QMM6v0q44xupGRar";

export async function sendBookingToGetCourse(studentEmail, dealId, bookingData) {
  const apiUrl = `https://${GC_ACCOUNT}.getcourse.ru/pl/api/deals`;

  const dealPayload = {
    deal_fields: {
      "Дата и время консультации": bookingData.datetimeStr,
      "Преподаватель": bookingData.teacherName,
      "Ссылка на Телемост": bookingData.telemostUrl,
      "Статус консультации": "Записан"
    }
  };

  if (dealId) {
    dealPayload.deal_number = dealId;
  }

  const payload = {
    user: {
      email: studentEmail
    },
    system: {
      multiple_offers: 1
    },
    deal: dealPayload
  };

  const bodyParams = new URLSearchParams();
  bodyParams.append("action", "add");
  bodyParams.append("key", GC_KEY);
  bodyParams.append("params", Buffer.from(JSON.stringify(payload)).toString("base64"));

  console.log(`[GetCourse] Обновляем заказ #${dealId} для ${studentEmail} (Статус: Записан)...`);

  try {
    const res = await fetch(apiUrl, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: bodyParams.toString()
    });

    const data = await res.json();
    console.log("[GetCourse] Ответ при записи:", data);
    return { success: data.success === true, data };
  } catch (err) {
    console.error("[GetCourse] Ошибка запроса:", err);
    return { success: false, error: err.message };
  }
}

export async function sendReminderToGetCourse(studentEmail, dealId) {
  const apiUrl = `https://${GC_ACCOUNT}.getcourse.ru/pl/api/deals`;

  const payload = {
    user: {
      email: studentEmail
    },
    system: {
      multiple_offers: 1
    },
    deal: {
      deal_number: dealId,
      deal_fields: {
        "Статус консультации": "Напомнить"
      }
    }
  };

  const bodyParams = new URLSearchParams();
  bodyParams.append("action", "add");
  bodyParams.append("key", GC_KEY);
  bodyParams.append("params", Buffer.from(JSON.stringify(payload)).toString("base64"));

  console.log(`[GetCourse] Отправляем напоминание для заказа #${dealId} (Статус: Напомнить)...`);

  try {
    const res = await fetch(apiUrl, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: bodyParams.toString()
    });
    const data = await res.json();
    console.log("[GetCourse] Ответ при напоминании:", data);
    return { success: data.success === true, data };
  } catch (err) {
    console.error("[GetCourse] Ошибка запроса напоминания:", err);
    return { success: false, error: err.message };
  }
}
