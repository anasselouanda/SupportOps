import assert from "node:assert/strict";
import { registerHooks } from "node:module";
import test from "node:test";

const httpUrl = new URL("../src/api/http.js", import.meta.url).href;
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
const { getDashboard } = await import("../src/api/dashboard.js");
hooks.deregister();

test("dashboard GET unwraps the data envelope and sends no query or CSRF request", async () => {
  const requests = [];
  const dashboard = { stats: { total: 1, open: 1, in_progress: 0, resolved: 0, closed: 0 }, recent_tickets: [{ id: 7 }] };
  http.defaults.adapter = async (config) => {
    requests.push(config);
    return { data: { data: dashboard }, status: 200, statusText: "OK", headers: {}, config };
  };
  const controller = new AbortController();

  assert.deepEqual(await getDashboard(controller.signal), dashboard);
  assert.equal(requests.length, 1);
  assert.equal(requests[0].method, "get");
  assert.equal(requests[0].url, "/api/v1/dashboard");
  assert.equal(requests[0].params, undefined);
  assert.equal(requests[0].signal, controller.signal);
  assert.equal(requests[0].withCredentials, true);
  assert.equal(requests[0].withXSRFToken, true);
});

for (const status of [401, 403, 419, 500]) {
  test("dashboard propagates status " + status + " without a retry", async () => {
    let calls = 0;
    const failure = { response: { status, data: {} } };
    http.defaults.adapter = async () => {
      calls += 1;
      throw failure;
    };
    await assert.rejects(getDashboard(), (error) => error === failure);
    assert.equal(calls, 1);
  });
}

test("dashboard propagates a network failure without fabricating zero counts", async () => {
  let calls = 0;
  const failure = new Error("Network unavailable");
  http.defaults.adapter = async () => {
    calls += 1;
    throw failure;
  };

  await assert.rejects(getDashboard(), (error) => error === failure);
  assert.equal(calls, 1);
});

test("already cancelled dashboard request never reaches the adapter", async () => {
  let calls = 0;
  http.defaults.adapter = async () => {
    calls += 1;
    throw new Error("Unexpected request");
  };
  const controller = new AbortController();
  controller.abort();

  await assert.rejects(getDashboard(controller.signal), (error) => error.code === "ERR_CANCELED");
  assert.equal(calls, 0);
});