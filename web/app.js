const app = document.querySelector("#app"),
  account = document.querySelector("#account");
let me,
  csrf,
  students = [],
  reports = [],
  selected = null,
  month = new Date()
    .toLocaleDateString("sv-SE", { timeZone: "Asia/Tokyo" })
    .slice(0, 7);
const esc = (v) =>
  String(v ?? "").replace(
    /[&<>"']/g,
    (c) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[
        c
      ],
  );
const money = (n) =>
  new Intl.NumberFormat("ja-JP", {
    style: "currency",
    currency: "JPY",
    maximumFractionDigits: 0,
  }).format(n || 0);
const fields = {
  sales: "今月の売上（円）",
  gross_profit: "粗利益（円）",
  net_profit: "純利益（円）",
  units: "販売個数",
  target: "今月の目標売上（円）",
  next_target: "来月の目標売上（円）",
  current_goal: "今月の目標・重点事項",
  next_goal: "来月の目標・重点事項",
  activities: "今月取り組んだこと",
  successes: "良かったこと",
  challenges: "現在の課題",
  next_actions: "来月やること",
  consultation: "講師に相談したいこと",
};
const numeric = [
  "sales",
  "gross_profit",
  "net_profit",
  "units",
  "target",
  "next_target",
];
function notice(text) {
  const n = document.querySelector("#notice");
  n.textContent = text;
  n.style.display = "block";
  setTimeout(() => (n.style.display = "none"), 5000);
}
async function api(path, data) {
  const r = await fetch("." + path, {
    method: data ? "POST" : "GET",
    headers: data
      ? { "Content-Type": "application/json", "X-CSRF-Token": csrf || "" }
      : {},
    body: data ? JSON.stringify(data) : undefined,
  });
  const v = await r.json();
  if (!r.ok) {
    if (r.status === 401 && me) {
      me = null;
      reports = [];
      students = [];
      selected = null;
      login();
    }
    throw Error(v.error);
  }
  return v;
}
function bind(form, fn) {
  document.querySelector(form).onsubmit = async (e) => {
    e.preventDefault();
    const button = e.submitter;
    try {
      if (button) button.disabled = true;
      await fn(Object.fromEntries(new FormData(e.target)), button);
    } catch (x) {
      notice(x.message);
    } finally {
      if (button) button.disabled = false;
    }
  };
}
function prev(m) {
  const [y, n] = m.split("-").map(Number);
  return n === 1 ? `${y - 1}-12` : `${y}-${String(n - 1).padStart(2, "0")}`;
}
function report(id, m = month) {
  return reports.find((r) => r.student_id === id && r.month === m);
}
function ratio(r) {
  return r?.target > 0 ? `${((r.sales / r.target) * 100).toFixed(1)}%` : "—";
}
function growth(id, m = month) {
  const r = report(id, m),
    p = report(id, prev(m));
  return r?.submitted && p?.submitted && p.sales > 0
    ? `${(((r.sales - p.sales) / p.sales) * 100).toFixed(1)}%`
    : "比較なし";
}
function badge(text) {
  return `<span class="badge ${text === "要確認" ? "warn" : text === "フォロー必要" ? "danger" : ""}">${esc(text)}</span>`;
}
async function load() {
  const v = await api("/api/me");
  me = v.user;
  csrf = v.csrf;
  reports = await api("/api/reports");
  students = me.role === "teacher" ? await api("/api/students") : [me];
  account.innerHTML = `${esc(me.name)}　<button class="secondary" id="password">パスワード変更</button> <button class="secondary" id="logout">ログアウト</button>`;
  document.querySelector("#logout").onclick = async () => {
    await api("/api/logout", {});
    me = null;
    csrf = null;
    reports = [];
    students = [];
    selected = null;
    login();
  };
  document.querySelector("#password").onclick = password;
  render();
}
function login() {
  account.innerHTML = "";
  app.innerHTML = `<section class="login"><small>FOLLOWUP STUDENT MANAGEMENT</small><h1>おかえりなさい</h1><p>登録されたアカウントでログインしてください。</p><form id="login"><label>メールアドレス</label><input name="email" type="email" autocomplete="username" required><label>パスワード</label><input name="password" type="password" autocomplete="current-password" required><div class="actions"><button>ログイン</button></div></form></section>`;
  bind("#login", async (data) => {
    await api("/api/login", data);
    selected = null;
    await load();
  });
}
function render() {
  if (me.role === "student") selected = me.id;
  if (selected !== null) return detail();
  dashboard();
}
function monthControl() {
  return `<label>対象月</label><input id="month" type="month" value="${month}">`;
}
function setMonth() {
  document.querySelector("#month").onchange = (e) => {
    if (e.target.value) {
      month = e.target.value;
      render();
    }
  };
}
function dashboard() {
  const active = students.filter((s) => s.active),
    submitted = active.filter((s) => report(s.id)?.submitted),
    sum = (k) => submitted.reduce((a, s) => a + report(s.id)[k], 0);
  app.innerHTML = `<small>TEACHER WORKSPACE</small><h1>生徒ダッシュボード</h1><p>一人ひとりの進捗を確認し、次の一歩をサポートしましょう。</p><div class="toolbar">${monthControl()}<input id="search" type="search" placeholder="生徒名で検索" aria-label="生徒名で検索"><select id="filter" aria-label="生徒の絞り込み"><option value="all">在籍生徒すべて</option><option value="missing">未提出</option><option value="support">フォロー必要</option><option value="check">要確認</option><option value="good">順調</option><option value="graduates">卒業生</option></select><button class="secondary" id="export">この月の一覧をCSV保存</button><button class="secondary" id="backup">全データをバックアップ</button></div><div class="metrics"><div class="card">在籍生徒<div class="value">${active.length}名</div></div><div class="card">月報提出<div class="value">${submitted.length} / ${active.length}</div></div><div class="card">提出済み売上合計<div class="value">${money(sum("sales"))}</div></div><div class="card">提出済み純利益合計<div class="value">${money(sum("net_profit"))}</div></div></div><section><h2>生徒一覧</h2><div class="tablewrap"><table><thead><tr><th>生徒 / 参加期</th><th>月報</th><th>売上</th><th>純利益</th><th>売上前月比</th><th>目標達成率</th><th>ステータス</th></tr></thead><tbody id="rows"></tbody></table></div></section><section><details><summary>生徒を追加する</summary><p>本人専用のメールアドレスを登録してください。初期パスワードは安全な方法で本人に伝え、変更を依頼してください。</p><form id="add"><div class="grid">${[
    ["name", "生徒名", "text"],
    ["email", "メールアドレス", "email"],
    ["prefecture", "都道府県", "text"],
    ["cohort", "参加期", "text"],
    ["start_date", "フォローアップ開始日", "date"],
    ["password", "初期パスワード（12文字以上）", "password"],
  ]
    .map(
      ([k, l, t]) =>
        `<div><label>${l}</label><input name="${k}" type="${t}" ${["name", "email", "password"].includes(k) ? "required" : ""} ${k === "password" ? 'minlength="12" autocomplete="new-password"' : ""}></div>`,
    )
    .join(
      "",
    )}</div><div class="actions"><button>生徒を登録</button></div></form></details></section>`;
  setMonth();
  function rows(filter = "all") {
    const search = document.querySelector("#search").value;
    const list = students
      .filter((s) => s.name.includes(search))
      .filter((s) =>
        filter === "graduates"
          ? !s.active
          : s.active &&
            (filter === "missing"
              ? !report(s.id)?.submitted
              : filter === "support"
                ? report(s.id)?.status === "フォロー必要"
                : filter === "check"
                  ? (report(s.id)?.status || "要確認") === "要確認"
                  : filter === "good"
                    ? report(s.id)?.status === "順調"
                    : true),
      );
    document.querySelector("#rows").innerHTML = list.length
      ? list
          .map((s) => {
            const r = report(s.id);
            return `<tr><td><button class="link" data-id="${s.id}">${esc(s.name)}</button><small>${esc(s.cohort || "参加期未登録")}</small></td><td>${badge(r?.submitted ? "提出済み" : "未提出")}</td><td>${r?.submitted ? money(r.sales) : "—"}</td><td>${r?.submitted ? money(r.net_profit) : "—"}</td><td>${growth(s.id)}</td><td>${r?.submitted ? ratio(r) : "—"}</td><td>${badge(r?.status || "要確認")}</td></tr>`;
          })
          .join("")
      : '<tr><td colspan="7">該当する生徒はいません。生徒を登録すると一覧に表示されます。</td></tr>';
    document.querySelectorAll("[data-id]").forEach(
      (b) =>
        (b.onclick = () => {
          selected = Number(b.dataset.id);
          detail();
        }),
    );
  }
  rows();
  document.querySelector("#export").onclick = exportMonth;
  document.querySelector("#backup").onclick = downloadBackup;
  document.querySelector("#filter").onchange = (e) => rows(e.target.value);
  document.querySelector("#search").oninput = () =>
    rows(document.querySelector("#filter").value);
  bind("#add", async (data) => {
    await api("/api/students", data);
    notice("生徒を登録しました");
    await load();
  });
}
function detail() {
  const s = students.find((s) => s.id === selected);
  if (!s) {
    selected = null;
    return dashboard();
  }
  const r = report(s.id) || {
      target: report(s.id, prev(month))?.next_target || 0,
      current_goal: report(s.id, prev(month))?.next_goal || "",
    },
    history = reports.filter((r) => r.student_id === s.id && r.submitted),
    max = Math.max(1, ...history.map((r) => r.sales));
  app.innerHTML = `${me.role === "teacher" ? '<button class="secondary" id="back">← 生徒一覧</button>' : ""}<h1>${esc(s.name)}${me.role === "teacher" ? " さんのカルテ" : " さんのマイページ"}</h1><p>${esc(s.prefecture || "都道府県未登録")} / ${esc(s.cohort || "参加期未登録")} / 開始日 ${esc(s.start_date || "未登録")} / ${s.active ? "在籍" : "卒業"}</p><div class="toolbar">${monthControl()}${badge(r.submitted ? "提出済み" : "未提出")}${badge(r.status || "要確認")}</div><section><h2>売上・利益の推移</h2>${history.length ? trend(history) : "<p>提出済みの月報があると推移が表示されます。</p>"}<div class="tablewrap"><table><thead><tr><th>月</th><th>売上</th><th>粗利益</th><th>純利益</th><th>目標達成率</th><th>前月比</th></tr></thead><tbody>${history.map((r) => `<tr><td><button class="link" data-month="${r.month}">${r.month}</button></td><td>${money(r.sales)}</td><td>${money(r.gross_profit)}</td><td>${money(r.net_profit)}</td><td>${ratio(r)}</td><td>${growth(s.id, r.month)}</td></tr>`).join("")}</tbody></table></div></section><section><h2>${month} の月報</h2><p>目標達成率 ${ratio(r)} ・ 売上前月比 ${growth(s.id)}<br>粗利益＝売上−仕入原価、純利益＝粗利益−経費。下書きは集計対象外です。提出後も修正・再提出できます。</p>${r.submitted_at ? `<p>最終提出：${new Date(r.submitted_at).toLocaleString("ja-JP", { timeZone: "Asia/Tokyo" })}</p>` : ""}<form id="report"><div class="grid">${Object.entries(
    fields,
  )
    .map(
      ([k, l]) =>
        `<div class="${numeric.includes(k) ? "" : "wide"}"><label>${l}</label>${numeric.includes(k) ? `<input name="${k}" type="number" step="${k === "units" ? "1" : "0.01"}" ${["sales", "units", "target", "next_target"].includes(k) ? 'min="0"' : ""} value="${esc(r[k] ?? 0)}" required>` : `<textarea name="${k}" maxlength="10000">${esc(r[k] || "")}</textarea>`}</div>`,
    )
    .join(
      "",
    )}</div><div class="actions"><button class="secondary" value="draft">下書き保存</button><button value="submit">月報を提出</button></div></form></section><section><h2>講師からのコメント</h2>${me.role === "teacher" ? `<form id="feedback"><label>ステータス</label><select name="status">${["順調", "要確認", "フォロー必要"].map((v) => `<option ${r.status === v ? "selected" : ""}>${v}</option>`).join("")}</select><label>コメント</label><textarea name="comment" maxlength="10000">${esc(r.comment || "")}</textarea><div class="actions"><button>コメントを保存</button></div></form>` : `<p class="comment">${esc(r.comment || "まだコメントはありません。").replace(/\n/g, "<br>")}</p>`}</section>${me.role === "teacher" ? `${profileEditor(s)}<section><h2>在籍管理</h2><p>卒業扱いにするとログインできなくなります。過去の月報は保持されます。</p><button class="secondary" id="active">${s.active ? "卒業扱いにする" : "在籍に戻す"}</button></section>` : ""}`;
  setMonth();
  if (me.role === "teacher") {
    bindProfile(s);
    document.querySelector("#back").onclick = () => {
      selected = null;
      dashboard();
    };
    bind("#feedback", async (d) => {
      await api("/api/feedback", { ...d, student_id: s.id, month });
      notice("コメントを保存しました");
      await load();
    });
    document.querySelector("#active").onclick = async () => {
      if (
        confirm(`${s.name}さんを${s.active ? "卒業" : "在籍"}扱いにしますか？`)
      ) {
        try {
          await api("/api/student/update", { id: s.id, active: !s.active });
          await load();
        } catch (e) {
          notice(e.message);
        }
      }
    };
  }
  bind("#report", async (d, b) => {
    await api("/api/report", {
      ...d,
      student_id: s.id,
      month,
      submitted: b.value === "submit",
    });
    notice(
      b.value === "submit" ? "月報を提出しました" : "下書きを保存しました",
    );
    await load();
  });
  document.querySelectorAll("[data-month]").forEach(
    (b) =>
      (b.onclick = () => {
        month = b.dataset.month;
        detail();
      }),
  );
}
function password() {
  app.innerHTML = `<section class="login"><h1>パスワード変更</h1><form id="pw"><label>現在のパスワード</label><input name="current" type="password" autocomplete="current-password" required><label>新しいパスワード（12文字以上）</label><input name="password" type="password" minlength="12" autocomplete="new-password" required><div class="actions"><button>変更する</button><button type="button" class="secondary" id="cancel">戻る</button></div></form></section>`;
  bind("#pw", async (d) => {
    await api("/api/password", d);
    notice("パスワードを変更しました");
    render();
  });
  document.querySelector("#cancel").onclick = render;
}
start();

function trend(history) {
  return `<div class="trend-grid">${[
    ["sales", "売上"],
    ["gross_profit", "粗利益"],
    ["net_profit", "純利益"],
  ]
    .map(([key, label]) => {
      const max = Math.max(1, ...history.map((r) => Math.abs(r[key])));
      return `<div><h3>${label}</h3><div class="chart">${history.map((r) => `<div class="barcol"><span>${money(r[key])}</span><div class="bar ${r[key] < 0 ? "negative" : ""}" style="height:${Math.max(2, Math.round((Math.abs(r[key]) / max) * 100))}px"></div>${esc(r.month)}</div>`).join("")}</div></div>`;
    })
    .join(
      "",
    )}</div><p class="muted">金額は提出済みの月報を表示。赤い棒は赤字を示します。各グラフの縮尺は独立しています。</p>`;
}
function profileEditor(s) {
  return `<section><details><summary>生徒の基本情報を編集</summary><form id="profile"><div class="grid">${[
    ["name", "生徒名", "text"],
    ["email", "メールアドレス", "email"],
    ["prefecture", "都道府県", "text"],
    ["cohort", "参加期", "text"],
    ["start_date", "フォローアップ開始日", "date"],
  ]
    .map(
      ([k, l, t]) =>
        `<div><label>${l}</label><input type="${t}" name="${k}" maxlength="200" value="${esc(s[k])}" ${["name", "email"].includes(k) ? "required" : ""}></div>`,
    )
    .join(
      "",
    )}</div><div class="actions"><button>基本情報を保存</button></div></form></details><details class="reset"><summary>生徒のパスワードを再設定</summary><p>本人確認後に実行してください。既存のログインは失効します。新しいパスワードは本人だけに伝えてください。</p><form id="reset-password"><label>新しいパスワード（12文字以上）</label><input type="password" name="password" minlength="12" maxlength="256" autocomplete="new-password" required><div class="actions"><button>再設定する</button></div></form></details></section>`;
}
function bindProfile(s) {
  bind("#profile", async (d) => {
    await api("/api/student/profile", { ...d, id: s.id });
    notice("基本情報を更新しました");
    await load();
  });
  bind("#reset-password", async (d) => {
    if (!confirm(`${s.name}さんのパスワードを再設定しますか？`)) return;
    await api("/api/student/password", { ...d, id: s.id });
    notice("パスワードを再設定しました");
    await load();
  });
}
async function start() {
  try {
    const setup = await api("/api/setup");
    if (setup.needs_setup) return setupScreen(setup.requires_key);
    await load();
  } catch (e) {
    login();
    if (!e.message.includes("ログインしてください")) notice(e.message);
  }
}
function setupScreen(requiresKey = false) {
  account.innerHTML = "";
  app.innerHTML = `<section class="login"><small>WELCOME TO FOLLOWUP</small><h1>はじめての設定</h1><p>講師のアカウントを作成します。登録後、この画面は利用できなくなります。</p><form id="setup">${requiresKey ? '<label>初期設定キー</label><input name="setup_key" autocomplete="off" required>' : ""}<label>講師名</label><input name="name" autocomplete="name" maxlength="200" required><label>メールアドレス</label><input name="email" type="email" autocomplete="username" required><label>パスワード（12文字以上）</label><input name="password" type="password" minlength="12" maxlength="256" autocomplete="new-password" required><label>パスワード確認</label><input name="confirmation" type="password" minlength="12" autocomplete="new-password" required><div class="actions"><button>アカウントを作成して開始</button></div></form></section>`;
  bind("#setup", async (d) => {
    if (d.password !== d.confirmation) throw Error("パスワードが一致しません");
    await api("/api/setup", d);
    await api("/api/login", { email: d.email, password: d.password });
    await load();
  });
}

function exportMonth() {
  const labels = [
    "生徒名",
    "都道府県",
    "参加期",
    "開始日",
    "在籍状態",
    "対象月",
    "提出状況",
    "売上",
    "粗利益",
    "純利益",
    "販売個数",
    "目標売上",
    "目標達成率",
    "売上前月比",
    "今月の目標",
    "今月取り組んだこと",
    "良かったこと",
    "現在の課題",
    "来月目標売上",
    "来月の目標",
    "来月やること",
    "相談事項",
    "講師コメント",
    "ステータス",
  ];
  const lines = [
    labels,
    ...students.map((s) => {
      const r = report(s.id) || {};
      return [
        s.name,
        s.prefecture,
        s.cohort,
        s.start_date,
        s.active ? "在籍" : "卒業",
        month,
        r.submitted ? "提出済み" : "未提出",
        r.sales,
        r.gross_profit,
        r.net_profit,
        r.units,
        r.target,
        ratio(r),
        growth(s.id),
        r.current_goal,
        r.activities,
        r.successes,
        r.challenges,
        r.next_target,
        r.next_goal,
        r.next_actions,
        r.consultation,
        r.comment,
        r.status || "要確認",
      ];
    }),
  ];
  const cell = (v) => {
    let t = String(v ?? "");
    if (typeof v === "string" && /^[=+@\-\t\r]/.test(t)) t = "'" + t;
    return '"' + t.replace(/"/g, '""') + '"';
  };
  const blob = new Blob(
    ["\ufeff" + lines.map((line) => line.map(cell).join(",")).join("\r\n")],
    { type: "text/csv;charset=utf-8" },
  );
  const url = URL.createObjectURL(blob),
    a = document.createElement("a");
  a.href = url;
  a.download = `月報一覧-${month}.csv`;
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

async function downloadBackup() {
  try {
    const response = await fetch("./api/backup", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
      body: "{}",
    });
    if (!response.ok) {
      const error = await response.json();
      throw Error(error.error);
    }
    const blob = await response.blob(),
      url = URL.createObjectURL(blob),
      link = document.createElement("a");
    link.href = url;
    link.download = `followup-backup-${new Date().toISOString().slice(0, 10)}.sqlite3`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    notice(
      "バックアップを保存しました。個人情報を含むため安全に保管してください。",
    );
  } catch (e) {
    notice(e.message);
  }
}
