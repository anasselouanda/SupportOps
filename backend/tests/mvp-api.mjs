import assert from "node:assert/strict";
import { readFile, writeFile } from "node:fs/promises";
import { basename, dirname, join, resolve } from "node:path";
import { tmpdir } from "node:os";

const root = resolve(process.argv[2] ?? "");
assert.equal(dirname(root).toLowerCase(), resolve(tmpdir()).toLowerCase());
assert.match(basename(root), /^SupportOps-mvp-\d{8}-\d{6}$/);
const access = JSON.parse(await readFile(join(root, "access.json"), "utf8"));
const apiRoot = "http://127.0.0.1:8001";
const serverInfo = await (await fetch(apiRoot + "/__browser-fixture")).json();
assert.equal(serverInfo.environment, "browser-testing");
assert.equal(serverInfo.driver, "sqlite");
assert.equal(resolve(serverInfo.database).toLowerCase(), join(root, "database.sqlite").toLowerCase());
const results = [];

function client(role) {
  const cookies = new Map();
  return async (method, path, body, csrf = true) => {
    const headers = { Accept: "application/json", Origin: `http://${role}.localhost:5174`, Cookie: [...cookies].map(([name, value]) => `${name}=${value}`).join("; ") };
    if (body !== undefined) headers["Content-Type"] = "application/json";
    if (csrf && cookies.has("XSRF-TOKEN")) headers["X-XSRF-TOKEN"] = decodeURIComponent(cookies.get("XSRF-TOKEN"));
    const response = await fetch(apiRoot + path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
    for (const cookie of response.headers.getSetCookie()) {
      const pair = cookie.split(";", 1)[0];
      const equals = pair.indexOf("=");
      cookies.set(pair.slice(0, equals), pair.slice(equals + 1));
    }
    const text = await response.text();
    return { status: response.status, body: text ? JSON.parse(text) : null };
  };
}
async function check(name, request, status, verify = () => {}) {
  const response = await request;
  assert.equal(response.status, status, `${name}: ${JSON.stringify(response.body)}`);
  verify(response.body);
  results.push({ name, status, passed: true });
  return response.body;
}
async function login(role) {
  const request = client(role);
  await check(`${role}: CSRF cookie`, request("GET", "/sanctum/csrf-cookie"), 204);
  await check(`${role}: login`, request("POST", "/api/login", { email: access.accounts[role].email, password: access.password }), 200,
    (body) => assert.equal(body.user.id, access.accounts[role].id));
  return request;
}

try {
  const anonymous = client("employee");
  await check("anonymous dashboard", anonymous("GET", "/api/v1/dashboard"), 401);
  await check("real CSRF protection rejects login without token", anonymous("POST", "/api/login", { email: access.accounts.employee.email, password: access.password }, false), 419);
  const employee = await login("employee");
  const technician = await login("technician");
  const admin = await login("admin");
  const outsideId = access.private_ticket_id;
  for (const suffix of ["", "/comments", "/events"]) {
    await check(`outside scope ${suffix || "detail"}`, employee("GET", `/api/v1/tickets/${outsideId}${suffix}`), 404,
      (body) => assert.ok(!JSON.stringify(body).includes("RECETTE privé")));
  }
  for (const query of ["?search=RECETTE%20priv%C3%A9", "?page=2&per_page=1", ""]) {
    const list = await check(`scoped search/pagination ${query}`, employee("GET", "/api/v1/tickets" + query), 200);
    assert.ok(list.data.every((ticket) => ticket.requester.id === access.accounts.employee.id));
  }
  const dashboard = await check("dashboard scope and invariant", employee("GET", "/api/v1/dashboard"), 200);
  assert.ok(!dashboard.data.recent_tickets.some((ticket) => ticket.id === outsideId));
  const stats = dashboard.data.stats;
  assert.equal(stats.total, stats.open + stats.in_progress + stats.resolved + stats.closed);
  await check("dashboard rejects forged scope", employee("GET", "/api/v1/dashboard?user_id=" + access.accounts.admin.id), 422);
  await check("employee cannot list admin users", employee("GET", "/api/v1/admin/users"), 403);
  await check("ordinary 403 preserves session", employee("GET", "/api/user"), 200);
  await check("employee cannot forge own role", employee("PATCH", "/api/v1/admin/users/" + access.accounts.employee.id, { role: "admin" }), 403);
  const payload = { title: "RECETTE API réouverture", description: "Ticket fictif des vérifications HTTP directes et de réouverture.", category_id: access.category_id, priority: "normal" };
  await check("forged requester rejected", employee("POST", "/api/v1/tickets", { ...payload, requester_id: access.accounts.admin.id }), 422);
  await check("inactive category rejected", employee("POST", "/api/v1/tickets", { ...payload, category_id: access.inactive_category_id }), 422);
  const created = await check("create direct HTTP test ticket", employee("POST", "/api/v1/tickets", payload), 201);
  const id = created.data.id;
  await check("requester comes from session", employee("GET", `/api/v1/tickets/${id}`), 200,
    (body) => assert.equal(body.data.requester.id, access.accounts.employee.id));
  await check("employee self assignment forbidden", employee("PATCH", `/api/v1/tickets/${id}/assignment`, { technician_id: access.accounts.technician.id }), 403);
  await check("inactive technician rejected", admin("PATCH", `/api/v1/tickets/${id}/assignment`, { technician_id: access.accounts.disabled.id }), 422);
  await check("non technician assignment rejected", admin("PATCH", `/api/v1/tickets/${id}/assignment`, { technician_id: access.accounts.employee.id }), 422);
  await check("general PATCH cannot change status", admin("PATCH", `/api/v1/tickets/${id}`, { status: "closed" }), 422);
  await check("forged comment author rejected", employee("POST", `/api/v1/tickets/${id}/comments`, { content: "Fictitious comment", author_id: access.accounts.admin.id }), 422);
  await check("admin assigns technician", admin("PATCH", `/api/v1/tickets/${id}/assignment`, { technician_id: access.accounts.technician.id }), 200);
  await check("technician starts", technician("PATCH", `/api/v1/tickets/${id}/status`, { status: "in_progress" }), 200);
  await check("resolution without note rejected", technician("PATCH", `/api/v1/tickets/${id}/status`, { status: "resolved" }), 422);
  await check("technician resolves", technician("PATCH", `/api/v1/tickets/${id}/status`, { status: "resolved", resolution_note: "Première résolution de recette conservée dans l’historique." }), 200);
  const before = await check("resolved dashboard", employee("GET", "/api/v1/dashboard"), 200);
  await check("technician non requester cannot close", technician("PATCH", `/api/v1/tickets/${id}/status`, { status: "closed" }), 403);
  await check("employee reopens before closing", employee("PATCH", `/api/v1/tickets/${id}/status`, { status: "open" }), 200,
    (body) => { assert.equal(body.data.resolution_note, null); assert.equal(body.data.resolved_at, null); assert.equal(body.data.technician.id, access.accounts.technician.id); });
  const after = await check("reopened dashboard", employee("GET", "/api/v1/dashboard"), 200);
  assert.equal(after.data.stats.total, before.data.stats.total);
  assert.equal(after.data.stats.resolved, before.data.stats.resolved - 1);
  assert.equal(after.data.stats.open, before.data.stats.open + 1);
  const history = await check("reopening keeps prior resolution and actors", employee("GET", `/api/v1/tickets/${id}/events`), 200);
  assert.ok(history.data.some((event) => event.type === "resolved" && event.actor.id === access.accounts.technician.id && event.metadata.after.resolution_note.includes("Première résolution")));
  assert.ok(history.data.some((event) => event.type === "reopened" && event.actor.id === access.accounts.employee.id));
  assert.ok(history.data.every((event) => new Date(event.occurred_at).toISOString().endsWith("Z")));
  const closed = Number(process.argv[3]);
  assert.ok(Number.isSafeInteger(closed) && closed > 0, "Pass the closed browser scenario ticket id.");
  for (const [method, suffix, body] of [
    ["PATCH", "", { title: "Forbidden edit" }],
    ["PATCH", "/assignment", { technician_id: access.accounts.technician.id }],
    ["PATCH", "/status", { status: "open" }],
    ["POST", "/comments", { content: "Forbidden closed comment" }],
  ]) await check(`closed ticket rejects ${method} ${suffix || "edit"}`, admin(method, `/api/v1/tickets/${closed}${suffix}`, body), 422);
  await check("logout", employee("POST", "/api/logout", {}), 200);
  await check("protected direct API after logout", employee("GET", "/api/v1/tickets"), 401);
  await writeFile(join(root, "api-results.json"), JSON.stringify({ database: serverInfo.database, checks: results.length, results, reopenedTicketId: id }, null, 2));
  console.log(JSON.stringify({ passed: results.length, failed: 0, reopenedTicketId: id }));
} catch (error) {
  await writeFile(join(root, "api-results.json"), JSON.stringify({ checks: results.length, results, failure: error.message }, null, 2));
  console.error(error.message);
  process.exitCode = 1;
}
