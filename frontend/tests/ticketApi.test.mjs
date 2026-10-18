import assert from "node:assert/strict";
import { registerHooks } from "node:module";
import test from "node:test";

const httpUrl = new URL("../src/api/http.js", import.meta.url).href;

// Vite injects import.meta.env in the browser; supply that value for Node only.
const hooks = registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier.startsWith(".") && context.parentURL?.includes("/src/api/") && !/\.[cm]?js$/.test(specifier)) {
      return nextResolve(new URL(specifier + ".js", context.parentURL).href, context);
    }
    return nextResolve(specifier, context);
  },
  load(url, context, nextLoad) {
    const result = nextLoad(url, context);
    if (url !== httpUrl) return result;
    const source = typeof result.source === "string" ? result.source : new TextDecoder().decode(result.source);
    return { ...result, source: source.replace("import.meta.env.VITE_API_BASE_URL", '"http://supportops.test"') };
  },
});

const { default: http } = await import("../src/api/http.js");
const api = await import("../src/api/tickets.js");
hooks.deregister();

function adapterFor(requests, respond = () => ({ data: {} })) {
  return async (config) => {
    requests.push(config);
    return {
      data: respond(config),
      status: 200,
      statusText: "OK",
      headers: {},
      config,
    };
  };
}

function payload(config) {
  return typeof config.data === "string" ? JSON.parse(config.data) : config.data;
}

test("shared Axios client sends cookies and requests the XSRF header", () => {
  assert.equal(http.defaults.withCredentials, true);
  assert.equal(http.defaults.withXSRFToken, true);
  assert.equal(http.defaults.headers.Accept, "application/json");
});

const readCases = [
  ["tickets", (signal) => api.listTickets({ page: 2, per_page: 25, status: "open" }, signal), "/api/v1/tickets", { page: 2, per_page: 25, status: "open" }, false],
  ["detail", (signal) => api.getTicket(42, signal), "/api/v1/tickets/42", undefined, true],
  ["categories", (signal) => api.listCategories(signal), "/api/v1/categories", undefined, true],
  ["comments", (signal) => api.listTicketComments(42, { page: 2 }, signal), "/api/v1/tickets/42/comments", { page: 2 }, false],
  ["events", (signal) => api.listTicketEvents(42, { per_page: 100 }, signal), "/api/v1/tickets/42/events", { per_page: 100 }, false],
  ["active technicians", (signal) => api.listActiveTechnicians({ page: 2 }, signal), "/api/v1/admin/technicians", { page: 2 }, false],
];

for (const [name, invoke, url, params, unwrap] of readCases) {
  test("GET " + name + " preserves signal and expected envelope", async () => {
    const requests = [];
    const body = { data: [{ id: 42 }], links: { next: null }, meta: { current_page: 2 } };
    http.defaults.adapter = adapterFor(requests, () => body);
    const controller = new AbortController();
    const result = await invoke(controller.signal);

    assert.equal(requests.length, 1);
    assert.equal(requests[0].method, "get");
    assert.equal(requests[0].url, url);
    assert.deepEqual(requests[0].params, params);
    assert.equal(requests[0].signal, controller.signal);
    assert.deepEqual(result, unwrap ? body.data : body);
  });
}

const mutationCases = [
  ["create", () => api.createTicket({ title: "Printer issue", description: "Printer cannot connect.", category_id: 3, priority: "normal" }), "post", "/api/v1/tickets", { title: "Printer issue", description: "Printer cannot connect.", category_id: 3, priority: "normal" }],
  ["edit", () => api.updateTicket(42, { title: "Updated title" }), "patch", "/api/v1/tickets/42", { title: "Updated title" }],
  ["assign", () => api.assignTicket(42, 7), "patch", "/api/v1/tickets/42/assignment", { technician_id: 7 }],
  ["unassign", () => api.assignTicket(42, null), "patch", "/api/v1/tickets/42/assignment", { technician_id: null }],
  ["status", () => api.changeTicketStatus(42, { status: "resolved", resolution_note: "Cable replaced." }), "patch", "/api/v1/tickets/42/status", { status: "resolved", resolution_note: "Cable replaced." }],
  ["comment", () => api.addTicketComment(42, "A diagnostic update."), "post", "/api/v1/tickets/42/comments", { content: "A diagnostic update." }],
];

for (const [name, invoke, method, url, expectedPayload] of mutationCases) {
  test("mutation " + name + " obtains CSRF before exactly one write", async () => {
    const requests = [];
    http.defaults.adapter = adapterFor(requests, (config) => config.url === "/sanctum/csrf-cookie" ? {} : { data: { id: 42 } });
    const result = await invoke();

    assert.equal(requests.length, 2);
    assert.equal(requests[0].method, "get");
    assert.equal(requests[0].url, "/sanctum/csrf-cookie");
    assert.equal(requests[1].method, method);
    assert.equal(requests[1].url, url);
    assert.deepEqual(payload(requests[1]), expectedPayload);
    assert.deepEqual(result, { id: 42 });
  });
}

for (const status of [401, 403, 419, 422, 500]) {
  test("failed write " + status + " is never automatically retried", async () => {
    const requests = [];
    const failure = { response: { status, data: {} } };
    http.defaults.adapter = async (config) => {
      requests.push(config);
      if (config.url !== "/sanctum/csrf-cookie") throw failure;
      return { data: {}, status: 204, statusText: "No Content", headers: {}, config };
    };

    await assert.rejects(api.addTicketComment(42, "Keep a single write."), (error) => error === failure);
    assert.deepEqual(requests.map((request) => request.method), ["get", "post"]);
  });
}

test("network failure after a write is propagated once without retry", async () => {
  const requests = [];
  const failure = new Error("Connection lost after sending request.");
  http.defaults.adapter = async (config) => {
    requests.push(config);
    if (config.method === "post") throw failure;
    return { data: {}, status: 204, headers: {}, config };
  };

  await assert.rejects(api.createTicket({ title: "Printer issue" }), (error) => error === failure);
  assert.equal(requests.length, 2);
});

test("failed CSRF preparation prevents the write", async () => {
  const requests = [];
  const failure = new Error("CSRF endpoint unavailable.");
  http.defaults.adapter = async (config) => {
    requests.push(config);
    throw failure;
  };

  await assert.rejects(api.updateTicket(42, { title: "Must not be sent" }), (error) => error === failure);
  assert.equal(requests.length, 1);
  assert.equal(requests[0].url, "/sanctum/csrf-cookie");
});

test("already aborted GET requests never reach the adapter", async () => {
  const requests = [];
  http.defaults.adapter = adapterFor(requests);
  const controller = new AbortController();
  controller.abort();

  await assert.rejects(api.listTickets({}, controller.signal), (error) => error.code === "ERR_CANCELED");
  assert.equal(requests.length, 0);
});

test("ticket ids are encoded as a single URL segment", async () => {
  const requests = [];
  http.defaults.adapter = adapterFor(requests, () => ({ data: { id: 1 } }));

  await api.getTicket("../42?query=1");

  assert.equal(requests[0].url, "/api/v1/tickets/..%2F42%3Fquery%3D1");
});